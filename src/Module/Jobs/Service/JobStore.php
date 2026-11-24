<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class JobStore
{
    private ?WorkerHeartbeat $processHeartbeat=null;
    private int $lastLeaseTouch=0;

    public function registerWorkerHeartbeat(WorkerHeartbeat $heartbeat):void
    {
        $this->processHeartbeat=$heartbeat;
    }

    public function __construct(
        private readonly Connection $db,
        private readonly DirectoryBrowser $browser
    ) {}

    public function queueScan(int $profileId): int
    {
        $profile=$this->db->fetchAssociative(
            'SELECT p.*,t.extensions FROM scan_profiles p JOIN profile_types t ' .
            'ON t.id=p.profile_type_id WHERE p.id=?',[$profileId]
        );
        if(!$profile || !(bool)$profile['enabled'])
            throw new \DomainException('Select an enabled scan profile.');
        $root=$this->browser->canonicalDirectory((string)$profile['source_root']);
        if($root===null)
            throw new \DomainException('The scan source is not accessible to the server account.');

        $ignore=$this->db->fetchFirstColumn(
            'SELECT folder_name FROM profile_ignore_rules WHERE enabled=1 AND ' .
            '(profile_id IS NULL OR profile_id=?)',[$profileId]
        );
        $settings=[
            'source_root'=>$root,
            'extensions'=>(string)$profile['extensions'],
            'minimum_bytes'=>(int)$profile['minimum_bytes'],
            'hash_mode'=>(string)$profile['hash_mode'],
            'ignored_folders'=>array_values(array_unique([...array_map('strtolower',$ignore),'.dup-quarantine']))
        ];
        if(!\App\Module\Scans\Service\FileHasher::isSupported($settings['hash_mode']))
            throw new \DomainException('Unsupported hash mode.');

        try {
            return $this->db->transactional(function(Connection $db) use ($profileId,$settings): int {
                $now=gmdate('Y-m-d H:i:s');
                $db->insert('scan_runs',[
                    'profile_id'=>$profileId,'state'=>'queued',
                    'settings_json'=>json_encode($settings,JSON_THROW_ON_ERROR)
                ]);
                $runId=(int)$db->lastInsertId();
                $db->insert('jobs',[
                    'kind'=>'scan','profile_id'=>$profileId,'active_profile_id'=>$profileId,
                    'run_id'=>$runId,'state'=>'queued','phase'=>'waiting',
                    'progress_current'=>'0','created_at'=>$now,'attempts'=>0
                ]);
                $jobId=(int)$db->lastInsertId();
                $this->event($jobId,'info','SCAN_QUEUED',['profile_id'=>$profileId,'run_id'=>$runId]);
                return $jobId;
            });
        } catch(UniqueConstraintViolationException $ex) {
            throw new \DomainException('This profile already has a queued or running scan.',0,$ex);
        }
    }

    public function claimNext(?int $onlyId=null): ?array
    {
        for($attempt=0;$attempt<5;$attempt++) {
            $id=$this->db->fetchOne(
                "SELECT id FROM jobs WHERE state='queued' AND kind IN ('scan','rebuild-duplicates','file-action','file-restore','file-rename','file-move','file-move-restore','file-link','file-unlink','bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete','duplicate-verify')" .
                ($onlyId===null?' ORDER BY id LIMIT 1':' AND id=? LIMIT 1'),
                $onlyId===null?[]:[$onlyId]
            );
            if(!$id) return null;
            $token=bin2hex(random_bytes(24));
            $now=gmdate('Y-m-d H:i:s');
            $updated=$this->db->executeStatement(
                "UPDATE jobs SET state='running',phase='preparing',worker_token=?," .
                "heartbeat_at=?,started_at=COALESCE(started_at,?),attempts=attempts+1 " .
                "WHERE id=? AND state='queued'",[$token,$now,$now,(int)$id]
            );
            if($updated!==1) continue;
            $job=$this->db->fetchAssociative('SELECT * FROM jobs WHERE id=?',[(int)$id]);
            $this->db->executeStatement(
                "UPDATE scan_runs SET state='running',started_at=? WHERE id=? AND state='queued'",
                [$now,(int)$job['run_id']]
            );
            $this->event((int)$id,'info','WORKER_CLAIMED',[
                'job_kind'=>(string)$job['kind'],
                'profile_id'=>$job['profile_id']===null?null:(int)$job['profile_id'],
                'run_id'=>$job['run_id']===null?null:(int)$job['run_id'],
                'attempt'=>(int)$job['attempts'],
                'worker_host'=>(string)(gethostname()?:'unknown'),
                'worker_pid'=>getmypid(),
                'php_version'=>PHP_VERSION
            ]);
            return $job;
        }
        return null;
    }

    public function assertRunning(int $id,string $token): void
    {
        $row=$this->db->fetchAssociative('SELECT state,worker_token FROM jobs WHERE id=?',[$id]);
        if(!$row || !hash_equals((string)($row['worker_token']??''),$token))
            throw new LostLease('Worker no longer holds the job lease.');
        if($row['state']==='cancelling')
            throw new ScanCancelled('Cancellation requested.');
        if($row['state']!=='running')
            throw new LostLease('Job was already finished or recovered.');
        // Full-file hashing and directory traversal call assertRunning() at
        // checkpoints but may not update the progress counter for minutes.
        // Throttle writes so per-MB checkpoints do not hammer the database.
        $now=time();
        if($this->processHeartbeat!==null && $now-$this->lastLeaseTouch>=5) {
            $updated=$this->db->executeStatement(
                "UPDATE jobs SET heartbeat_at=? WHERE id=? AND worker_token=? ".
                "AND state='running'",
                [gmdate('Y-m-d H:i:s',$now),$id,$token]
            );
            if($updated!==1) throw new LostLease('Job lease changed while updating heartbeat.');
            $this->lastLeaseTouch=$now;
            $this->processHeartbeat->touch();
        }
    }

    public function progress(int $id,string $token,string $phase,int $current,?int $total): void
    {
        $updated=$this->db->executeStatement(
            "UPDATE jobs SET phase=?,progress_current=?,progress_total=?,heartbeat_at=? " .
            "WHERE id=? AND worker_token=? AND state='running'",
            [$phase,$current,$total,gmdate('Y-m-d H:i:s'),$id,$token]
        );
        if($updated!==1) $this->assertRunning($id,$token);
        else $this->processHeartbeat?->touch();
    }

    /**
     * Persist actual hashing bytes separately from completed-file counts.
     * Callers throttle this to avoid an SQL UPDATE for every 1-MiB read.
     */
    public function verificationProgress(
        int $id,string $token,int $filesDone,int $filesTotal,
        int $bytesDone,int $bytesTotal,string $detail,
        string $phase='hashing_duplicates'
    ):void {
        $now=gmdate('Y-m-d H:i:s');
        $changed=$this->db->executeStatement(
            'UPDATE jobs SET phase=?,progress_current=?,progress_total=?,'.
            'progress_bytes_current=?,progress_bytes_total=?,progress_detail=?,'.
            'last_progress_at=?,heartbeat_at=? WHERE id=? AND worker_token=? AND state=?',
            [$phase,$filesDone,$filesTotal,$bytesDone,$bytesTotal,
                mb_substr($detail,0,255),$now,$now,$id,$token,'running']
        );
        if($changed!==1) {
            $this->assertRunning($id,$token);
            throw new LostLease('Verification job no longer holds the worker lease.');
        }
        $this->processHeartbeat?->touch();
    }

    public function event(int $id,string $severity,string $code,array $context=[]): void
    {
        $this->db->insert('job_events',[
            'job_id'=>$id,'severity'=>$severity,'code'=>$code,
            'context'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }

    public function requestCancel(int $id): bool
    {
        return $this->db->transactional(function(Connection $db) use ($id): bool {
            $now=gmdate('Y-m-d H:i:s');
            $queued=$db->executeStatement(
                "UPDATE jobs SET state='cancelled',phase='cancelled',finished_at=?," .
                "active_profile_id=NULL WHERE id=? AND state='queued'",[$now,$id]
            );
            if($queued===1) {
                $job=$db->fetchAssociative('SELECT run_id,kind,file_action_batch_id FROM jobs WHERE id=?',[$id]);
                if($job['kind']==='file-action')
                    $db->executeStatement("UPDATE file_action_batches SET state='cancelled' WHERE id=? AND state='queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-restore')
                    $db->executeStatement("UPDATE file_action_batches SET state='completed' WHERE id=? AND state='restore_queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-rename')
                    $db->executeStatement("UPDATE file_action_batches SET state='cancelled' WHERE id=? AND action_kind='rename' AND state='queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-move')
                    $db->executeStatement("UPDATE file_action_batches SET state='cancelled' WHERE id=? AND action_kind='move' AND state='queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-move-restore')
                    $db->executeStatement("UPDATE file_action_batches SET state='completed' WHERE id=? AND action_kind='move' AND state='restore_queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-delete')
                    $db->executeStatement(
                        "UPDATE file_action_batches SET state='cancelled' WHERE id=? AND state='queued'",
                        [$job['file_action_batch_id']]
                    );
                if($job['kind']==='file-link')
                    $db->executeStatement("UPDATE file_action_batches SET state='cancelled' WHERE id=? AND action_kind='link' AND state='queued'",[$job['file_action_batch_id']]);
                if($job['kind']==='file-unlink')
                    $db->executeStatement("UPDATE file_action_batches SET state='completed' WHERE id=? AND action_kind='link' AND state='restore_queued'",[$job['file_action_batch_id']]);
                if(in_array($job['kind'],['bulk-link','bulk-quarantine'],true))
                    $db->executeStatement(
                        "UPDATE file_action_batches SET state='cancelled' WHERE id=? AND state='queued'",
                        [$job['file_action_batch_id']]
                    );
                if(in_array($job['kind'],['bulk-restore','bulk-unlink'],true))
                    $db->executeStatement(
                        "UPDATE file_action_batches SET state='partial' WHERE id=? AND state='restore_queued'",
                        [$job['file_action_batch_id']]
                    );
                if($job['kind']==='scan')
                    $db->executeStatement("UPDATE scan_runs SET state='cancelled',finished_at=? WHERE id=?",[$now,$job['run_id']]);
                $this->event($id,'warning','SCAN_CANCELLED_QUEUED');
                return true;
            }
            $kind=$db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$id]);
            if(in_array($kind,['file-action','file-restore','file-rename','file-move','file-move-restore','file-link','file-unlink','bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'],true))
                return false; // Once claimed, irreversible filesystem stages cannot be cancelled.
            $running=$db->executeStatement(
                "UPDATE jobs SET state='cancelling' WHERE id=? AND state='running'",[$id]
            );
            if($running===1) {
                $this->event($id,'warning','CANCELLATION_REQUESTED');
                return true;
            }
            return $db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id])==='cancelling';
        });
    }

    public function complete(int $id,string $token,array $stats): void
    {
        $this->db->transactional(function(Connection $db) use ($id,$token,$stats): void {
            $this->assertRunning($id,$token);
            $now=gmdate('Y-m-d H:i:s');
            $db->executeStatement(
                "UPDATE scan_runs SET state='completed',finished_at=?,files_seen=?," .
                "files_indexed=?,bytes_indexed=?,duplicate_groups=?,error_count=? " .
                "WHERE id=(SELECT run_id FROM jobs WHERE id=?)",
                [$now,$stats['files_seen'],$stats['files_indexed'],$stats['bytes_indexed'],
                    $stats['duplicate_groups'],$stats['error_count'],$id]
            );
            $db->executeStatement(
                "UPDATE jobs SET state='completed',phase='completed',finished_at=?," .
                "progress_current=?,progress_total=?,active_profile_id=NULL,heartbeat_at=? " .
                "WHERE id=? AND worker_token=? AND state='running'",
                [$now,$stats['files_indexed'],$stats['files_indexed'],$now,$id,$token]
            );
            $this->event($id,'info','SCAN_COMPLETED',$stats);
        });
    }

    /**
     * Called inside the rebuild transaction, so a failed recalculation cannot
     * leave partly refreshed groups visible or corrupt a successful scan.
     */
    public function completeRebuild(int $id,string $token,int $groups,int $changedDirectories): void
    {
        $this->assertRunning($id,$token);
        $now=gmdate('Y-m-d H:i:s');
        $this->db->executeStatement(
            "UPDATE scan_runs SET duplicate_groups=?,ignore_applied_at=? " .
            "WHERE id=(SELECT run_id FROM jobs WHERE id=?) AND state='completed'",
            [$groups,$now,$id]
        );
        $changed=$this->db->executeStatement(
            "UPDATE jobs SET state='completed',phase='completed',finished_at=?," .
            "progress_current=?,progress_total=?,active_profile_id=NULL,heartbeat_at=? " .
            "WHERE id=? AND worker_token=? AND kind='rebuild-duplicates' AND state='running'",
            [$now,$groups,$groups,$now,$id,$token]
        );
        if($changed!==1) throw new LostLease('Rebuild job lease changed during recalculation.');
        $this->event($id,'info','IGNORE_RECALC_COMPLETED',[
            'duplicate_groups'=>$groups,'changed_directory_flags'=>$changedDirectories
        ]);
    }

    public function stop(int $id,string $token,string $state,string $errorCode,array $details=[]): void
    {
        if(!in_array($state,['cancelled','failed'],true))
            throw new \InvalidArgumentException('Invalid terminal state.');
        $this->db->transactional(function(Connection $db) use ($id,$token,$state,$errorCode,$details): void {
            $now=gmdate('Y-m-d H:i:s');
            $reason=isset($details['reason']) && is_string($details['reason'])?
                mb_substr($details['reason'],0,2000):null;
            $changed=$db->executeStatement(
                "UPDATE jobs SET state=?,phase=?,error_code=?,active_profile_id=NULL," .
                "last_failure_reason=?,finished_at=?,heartbeat_at=? WHERE id=? AND worker_token=? " .
                "AND state IN ('running','cancelling')",
                [$state,$state,$errorCode,$reason,$now,$now,$id,$token]
            );
            if($changed!==1) return; // Stale worker has already been recovered.
            if($db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$id])==='scan')
                $db->executeStatement(
                    "UPDATE scan_runs SET state=?,finished_at=?,error_count=error_count+1 " .
                    "WHERE id=(SELECT run_id FROM jobs WHERE id=?)",[$state,$now,$id]
                );
            $this->event($id,$state==='cancelled'?'warning':'error',$errorCode,$details);
        });
    }

    public function recoverStalled(int $seconds=600): int
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-max(30,$seconds));
        $rows=$this->db->fetchAllAssociative(
            "SELECT id,run_id,kind,file_action_batch_id,phase,heartbeat_at," .
            "progress_current,progress_total,progress_bytes_current,progress_bytes_total," .
            "progress_detail,last_progress_at,started_at FROM jobs " .
            "WHERE state IN ('running','cancelling') " .
            "AND (heartbeat_at IS NULL OR heartbeat_at<?)",[$cutoff]
        );
        $count=0;
        foreach($rows as $row) {
            $id=(int)$row['id'];
            $changed=$this->db->executeStatement(
                "UPDATE jobs SET state='failed',phase='stalled',error_code='WORKER_STALLED'," .
                "last_failure_reason=?,active_profile_id=NULL,finished_at=? WHERE id=? " .
                "AND state IN ('running','cancelling') AND (heartbeat_at IS NULL OR heartbeat_at<?)",
                ['Worker job heartbeat expired. Last phase: '.(string)$row['phase'].
                    '; last heartbeat: '.(string)($row['heartbeat_at']??'never').
                    '; completed files: '.(int)$row['progress_current'].
                    '/'.(int)($row['progress_total']??0).
                    '; hashed bytes: '.(int)($row['progress_bytes_current']??0).
                    '/'.(int)($row['progress_bytes_total']??0),
                    gmdate('Y-m-d H:i:s'),$id,$cutoff]
            );
            if($changed!==1) continue;
            if($row['kind']==='duplicate-verify')
                $this->db->delete('duplicate_verification_hashes',['job_id'=>$id]);
            if($row['kind']==='scan')
                $this->db->executeStatement(
                    "UPDATE scan_runs SET state='failed',finished_at=?,error_count=error_count+1 WHERE id=?",
                    [gmdate('Y-m-d H:i:s'),(int)$row['run_id']]
                );
            if(in_array($row['kind'],['file-action','file-restore','file-rename','file-move','file-move-restore','file-link','file-unlink','bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'],true))
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code='WORKER_STALLED' " .
                    "WHERE id=? AND state IN ('queued','executing','restore_queued','restore_executing')",
                    [(int)$row['file_action_batch_id']]
                );
            $this->event($id,'error','WORKER_STALLED',[
                'explanation'=>'No job heartbeat was recorded within the recovery window. '.
                    'This does not identify why the process stopped responding.',
                'phase'=>$row['phase'],
                'last_heartbeat_at'=>$row['heartbeat_at'],
                'last_progress_at'=>$row['last_progress_at'],
                'progress_detail'=>$row['progress_detail'],
                'files_completed'=>(int)$row['progress_current'],
                'files_total'=>$row['progress_total']===null?null:(int)$row['progress_total'],
                'bytes_hashed'=>(int)($row['progress_bytes_current']??0),
                'bytes_total'=>$row['progress_bytes_total']===null?null:(int)$row['progress_bytes_total'],
                'job_started_at'=>$row['started_at'],
                'timeout_seconds'=>max(30,$seconds),
                'recovery'=>'Job marked failed and its lease released; no source files were changed.',
                'recommended_check'=>'Inspect service/process logs and verify source-folder I/O before retrying.'
            ]);
            $count++;
        }
        return $count;
    }
}
