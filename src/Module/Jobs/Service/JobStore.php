<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class JobStore
{
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
        if(!in_array($settings['hash_mode'],['size','sha256'],true))
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
                "SELECT id FROM jobs WHERE state='queued' AND kind IN ('scan','rebuild-duplicates','file-action','file-restore')" .
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
            $this->event((int)$id,'info','WORKER_CLAIMED');
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
    }

    public function progress(int $id,string $token,string $phase,int $current,?int $total): void
    {
        $updated=$this->db->executeStatement(
            "UPDATE jobs SET phase=?,progress_current=?,progress_total=?,heartbeat_at=? " .
            "WHERE id=? AND worker_token=? AND state='running'",
            [$phase,$current,$total,gmdate('Y-m-d H:i:s'),$id,$token]
        );
        if($updated!==1) $this->assertRunning($id,$token);
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
                if($job['kind']==='scan')
                    $db->executeStatement("UPDATE scan_runs SET state='cancelled',finished_at=? WHERE id=?",[$now,$job['run_id']]);
                $this->event($id,'warning','SCAN_CANCELLED_QUEUED');
                return true;
            }
            $kind=$db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$id]);
            if(in_array($kind,['file-action','file-restore'],true))
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
            $changed=$db->executeStatement(
                "UPDATE jobs SET state=?,phase=?,error_code=?,active_profile_id=NULL," .
                "finished_at=?,heartbeat_at=? WHERE id=? AND worker_token=? " .
                "AND state IN ('running','cancelling')",
                [$state,$state,$errorCode,$now,$now,$id,$token]
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

    public function recoverStalled(int $seconds=120): int
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-max(30,$seconds));
        $rows=$this->db->fetchAllAssociative(
            "SELECT id,run_id,kind,file_action_batch_id FROM jobs WHERE state IN ('running','cancelling') " .
            "AND (heartbeat_at IS NULL OR heartbeat_at<?)",[$cutoff]
        );
        $count=0;
        foreach($rows as $row) {
            $id=(int)$row['id'];
            $changed=$this->db->executeStatement(
                "UPDATE jobs SET state='failed',phase='stalled',error_code='WORKER_STALLED'," .
                "active_profile_id=NULL,finished_at=? WHERE id=? " .
                "AND state IN ('running','cancelling') AND (heartbeat_at IS NULL OR heartbeat_at<?)",
                [gmdate('Y-m-d H:i:s'),$id,$cutoff]
            );
            if($changed!==1) continue;
            if($row['kind']==='scan')
                $this->db->executeStatement(
                    "UPDATE scan_runs SET state='failed',finished_at=?,error_count=error_count+1 WHERE id=?",
                    [gmdate('Y-m-d H:i:s'),(int)$row['run_id']]
                );
            if(in_array($row['kind'],['file-action','file-restore'],true))
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code='WORKER_STALLED' " .
                    "WHERE id=? AND state IN ('queued','executing','restore_queued','restore_executing')",
                    [(int)$row['file_action_batch_id']]
                );
            $this->event($id,'error','WORKER_STALLED');
            $count++;
        }
        return $count;
    }
}
