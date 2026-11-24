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
        private readonly DirectoryBrowser $browser,
        private readonly ?JobDebugLog $debug=null
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

    /**
     * Validate actual ownership by state + token.
     *
     * MySQL PDO UPDATE affected-row counts mean CHANGED rows, not MATCHED
     * rows. Writing the same second-precision timestamp can return 0 even
     * though the running worker still owns its lease. A zero-row heartbeat or
     * progress update is NEVER, by itself, proof of lost ownership.
     */
    private function currentLease(int $id,string $token):array
    {
        $row=$this->db->fetchAssociative(
            'SELECT state,worker_token,heartbeat_at FROM jobs WHERE id=?',[$id]
        );
        if(!$row || !hash_equals((string)($row['worker_token']??''),$token))
            throw new LostLease('Worker no longer holds the job lease.','DUP-JOB-LEASE-101');
        if($row['state']==='cancelling')
            throw new ScanCancelled('Cancellation requested.');
        if($row['state']!=='running')
            throw new LostLease('Job was already finished or recovered.','DUP-JOB-LEASE-102');
        return $row;
    }

    public function assertRunning(int $id,string $token): void
    {
        $row=$this->currentLease($id,$token);
        // Full-file hashing and directory traversal call assertRunning() at
        // checkpoints but may not update progress for minutes. Limit writes.
        $now=time();
        if($this->processHeartbeat!==null && $now-$this->lastLeaseTouch>=5) {
            $stamp=gmdate('Y-m-d H:i:s',$now);
            // Avoid a no-op UPDATE in the same second as verificationProgress,
            // which MySQL would report as 0 changed rows.
            if((string)($row['heartbeat_at']??'')!==$stamp) {
                $updated=$this->db->executeStatement(
                    "UPDATE jobs SET heartbeat_at=? WHERE id=? AND worker_token=? ".
                    "AND state='running'",
                    [$stamp,$id,$token]
                );
                if($updated!==1) {
                    $after=$this->currentLease($id,$token);
                    // MySQL can return 0 when an UPDATE matched a valid row
                    // but did not change it. Only raise a real update error
                    // if the expected timestamp was not actually stored.
                    if((string)($after['heartbeat_at']??'')!==$stamp)
                        throw new \App\Core\Diagnostics\CodedRuntimeFailure(
                            'DUP-JOB-HEARTBEAT-101',
                            'Worker still owns the lease, but its heartbeat timestamp could not be saved.'
                        );
                }
            } else {
                $this->debug?->write($id,'debug','job.heartbeat.same_second',[
                    'timestamp'=>$stamp,
                    'note'=>'Worker token and state remain valid; skipped MySQL no-change heartbeat UPDATE.'
                ]);
            }
            $this->lastLeaseTouch=$now;
            $this->processHeartbeat->touch();
        }
    }

    public function progress(int $id,string $token,string $phase,int $current,?int $total): void
    {
        $stamp=gmdate('Y-m-d H:i:s');
        $updated=$this->db->executeStatement(
            "UPDATE jobs SET phase=?,progress_current=?,progress_total=?,heartbeat_at=? ".
            "WHERE id=? AND worker_token=? AND state='running'",
            [$phase,$current,$total,$stamp,$id,$token]
        );
        if($updated!==1) {
            $this->currentLease($id,$token);
            $actual=$this->db->fetchAssociative(
                'SELECT phase,progress_current,progress_total,heartbeat_at FROM jobs WHERE id=?',[$id]
            );
            if(!$actual || $actual['phase']!==$phase ||
                (int)$actual['progress_current']!==$current ||
                ($actual['progress_total']===null?null:(int)$actual['progress_total'])!==$total ||
                $actual['heartbeat_at']!==$stamp)
                throw new \App\Core\Diagnostics\CodedRuntimeFailure(
                    'DUP-JOB-PROGRESS-101',
                    'Worker lease remains valid, but scan progress was not saved.'
                );
        }
        $this->processHeartbeat?->touch();
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
        $detail=mb_substr($detail,0,255);
        $changed=$this->db->executeStatement(
            'UPDATE jobs SET phase=?,progress_current=?,progress_total=?,'.
            'progress_bytes_current=?,progress_bytes_total=?,progress_detail=?,'.
            'last_progress_at=?,heartbeat_at=? WHERE id=? AND worker_token=? AND state=?',
            [$phase,$filesDone,$filesTotal,$bytesDone,$bytesTotal,
                $detail,$now,$now,$id,$token,'running']
        );
        if($changed!==1) {
            // Do not turn a no-op (0 CHANGED rows, MySQL default behavior)
            // into a fatal LostLease. First verify actual ownership and then
            // check that ALL requested progress fields are already persisted.
            $this->currentLease($id,$token);
            $actual=$this->db->fetchAssociative(
                'SELECT phase,progress_current,progress_total,progress_bytes_current,'.
                'progress_bytes_total,progress_detail,last_progress_at,heartbeat_at '.
                'FROM jobs WHERE id=?',[$id]
            );
            if(!$actual || $actual['phase']!==$phase ||
                (int)$actual['progress_current']!==$filesDone ||
                (int)$actual['progress_total']!==$filesTotal ||
                (int)$actual['progress_bytes_current']!==$bytesDone ||
                (int)$actual['progress_bytes_total']!==$bytesTotal ||
                $actual['progress_detail']!==$detail ||
                $actual['last_progress_at']!==$now ||
                $actual['heartbeat_at']!==$now)
                throw new \App\Core\Diagnostics\CodedRuntimeFailure(
                    'DUP-JOB-PROGRESS-102',
                    'Worker lease remains valid, but SHA-256 progress was not saved.'
                );
            $this->debug?->write($id,'debug','job.verification_progress.unchanged',[
                'timestamp'=>$now,'files_done'=>$filesDone,
                'bytes_done'=>$bytesDone,
                'note'=>'SQL reported zero changed rows; all progress fields and lease ownership verified.'
            ]);
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

    /**
     * A service supervisor calls this only AFTER wait() confirms the specific
     * CLI worker PID has exited. Its last WORKER_CLAIMED event proves the job
     * belonged to that PID/host. This is stronger than a heartbeat timeout.
     * Never requeues an action that may have modified the filesystem.
     */
    public function markWorkerExited(int $pid,int $exitCode,string $host):int
    {
        if($pid<1 || $exitCode<0 || $host==='')
            throw new \DomainException('Invalid exited worker identity.');
        $rows=$this->db->fetchAllAssociative(
            "SELECT id,kind,state,worker_token,run_id,file_action_batch_id,".
            "progress_current,progress_total,progress_bytes_current,".
            "progress_bytes_total,progress_detail,heartbeat_at FROM jobs ".
            "WHERE state IN ('running','cancelling') AND worker_token IS NOT NULL"
        );
        $count=0;
        foreach($rows as $job) {
            $id=(int)$job['id'];
            $raw=$this->db->fetchOne(
                "SELECT context FROM job_events WHERE job_id=? ".
                "AND code='WORKER_CLAIMED' ORDER BY id DESC LIMIT 1",[$id]
            );
            $owner=is_string($raw)?json_decode($raw,true):null;
            if(!is_array($owner) ||
               (int)($owner['worker_pid']??0)!==$pid ||
               (string)($owner['worker_host']??'')!==$host)
                continue;
            $diagnostic=$job['kind']==='duplicate-verify'?
                ($this->debug??new JobDebugLog())->latestFailure($id):null;
            $this->db->transactional(function(Connection $db) use ($job,$id,$pid,$host,$exitCode,$diagnostic,&$count):void {
                $wasCancelling=$job['state']==='cancelling';
                $terminal=$wasCancelling?'cancelled':'failed';
                // Do not overwrite a traceable root cause with the
                // supervisor's generic process-exit code.
                $eventCode=$wasCancelling?'CANCELLATION_WORKER_EXITED':'WORKER_PROCESS_EXITED';
                $code=(!$wasCancelling && is_string($diagnostic['error_code']??null))?
                    $diagnostic['error_code']:
                    WorkerFailureCode::exited((string)$job['kind'],$exitCode,$wasCancelling);
                $now=gmdate('Y-m-d H:i:s');
                $rootCause=$diagnostic?
                    ' Root error ['.$diagnostic['error_code'].'] at '.
                    ($diagnostic['operation']??'unknown operation').
                    '; trace '.($diagnostic['trace_id']??'not assigned').
                    '; '.($diagnostic['exception_type']??'Exception').
                    ': '.mb_substr((string)($diagnostic['exception_message']??'Unknown'),0,900).
                    '; source '.($diagnostic['source_file']??'unknown').
                    ':'.($diagnostic['source_line']??'?').'.':
                    ' No structured cause found in the independent log; check Worker diagnostics, CLI output and PHP fatal errors.';
                $reason='CLI worker PID '.$pid.' exited with status '.$exitCode.
                    ' while job was '.$job['state'].'. Last recorded work: '.
                    (string)($job['progress_detail']??'not available').'.'.$rootCause;
                $updated=$db->executeStatement(
                    'UPDATE jobs SET state=?,phase=?,error_code=?,last_failure_reason=?,'.
                    'active_profile_id=NULL,worker_token=NULL,finished_at=? '.
                    'WHERE id=? AND state=? AND worker_token=?',
                    [$terminal,$wasCancelling?'cancelled':'worker_exited',$code,
                        $reason,$now,$id,$job['state'],$job['worker_token']]
                );
                if($updated!==1) return;
                if($job['kind']==='scan') {
                    $db->executeStatement(
                        'UPDATE scan_runs SET state=?,finished_at=?,error_count=error_count+1 '.
                        "WHERE id=? AND state IN ('running','queued')",
                        [$terminal,$now,(int)$job['run_id']]
                    );
                }
                if($job['kind']==='duplicate-verify')
                    $db->delete('duplicate_verification_hashes',['job_id'=>$id]);
                if(in_array($job['kind'],['file-action','file-restore','file-rename',
                    'file-move','file-move-restore','file-link','file-unlink',
                    'bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'],true))
                    $db->executeStatement(
                        "UPDATE file_action_batches SET state='needs_review',".
                        "error_code=? ".
                        "WHERE id=? AND state IN ('queued','executing','restore_queued','restore_executing')",
                        [$code,(int)$job['file_action_batch_id']]
                    );
                $this->event($id,$wasCancelling?'warning':'error',$code,[
                    'lifecycle_event'=>$eventCode,
                    'job_kind'=>$job['kind'],
                    'description'=>'Worker process exited before recording a terminal result.',
                    'root_error_code'=>$code,
                    'trace_id'=>$diagnostic['trace_id']??null,
                    'operation'=>$diagnostic['operation']??null,
                    'stage'=>$diagnostic['stage']??null,
                    'exception_type'=>$diagnostic['exception_type']??null,
                    'exception_message'=>$diagnostic['exception_message']??null,
                    'source_file'=>$diagnostic['source_file']??null,
                    'source_line'=>$diagnostic['source_line']??null,
                    'independent_log_event'=>$diagnostic['log_event']??null,
                    'worker_host'=>$host,'worker_pid'=>$pid,'exit_code'=>$exitCode,
                    'previous_state'=>$job['state'],'resolved_state'=>$terminal,
                    'last_heartbeat_at'=>$job['heartbeat_at'],
                    'files_completed'=>(int)($job['progress_current']??0),
                    'files_total'=>$job['progress_total']===null?null:(int)$job['progress_total'],
                    'bytes_read'=>(int)($job['progress_bytes_current']??0),
                    'bytes_total'=>$job['progress_bytes_total']===null?null:(int)$job['progress_bytes_total'],
                    'last_file'=>$job['progress_detail'],
                    'next_step'=>'Review worker.log or service logs. Only retry once the old process is gone.',
                    'source_files'=>'This recovery only updates job metadata and cleans temporary verification evidence.'
                ]);
                $count++;
            });
        }
        return $count;
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
                $this->event($id,'warning','JOB_CANCELLED_BEFORE_START',[
                    'description'=>'Cancelled while waiting; no worker had started this job.',
                    'kind'=>$job['kind']
                ]);
                return true;
            }
            $kind=$db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$id]);
            if(in_array($kind,['file-action','file-restore','file-rename','file-move','file-move-restore','file-link','file-unlink','bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'],true))
                return false; // Once claimed, irreversible filesystem stages cannot be cancelled.
            $running=$db->executeStatement(
                "UPDATE jobs SET state='cancelling' WHERE id=? AND state='running'",[$id]
            );
            if($running===1) {
                $this->event($id,'warning','CANCELLATION_REQUESTED',[
                    'description'=>'Cooperative cancellation requested. The running worker must '.
                        'acknowledge this at its next checkpoint.',
                    'next_step'=>'If the worker does not respond, resolve the stalled cancellation '.
                        'from this job once its heartbeat has expired and the worker is stopped.'
                ]);
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
        if($changed!==1) throw new LostLease('Rebuild job lease changed during recalculation.','DUP-JOB-LEASE-103');
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
            $storedCode=isset($details['failure_code']) &&
                is_string($details['failure_code']) &&
                preg_match('/^DUP-[A-Z-]+-[0-9]{3,5}$/D',$details['failure_code'])?
                $details['failure_code']:$errorCode;
            $changed=$db->executeStatement(
                "UPDATE jobs SET state=?,phase=?,error_code=?,active_profile_id=NULL," .
                "last_failure_reason=?,finished_at=?,heartbeat_at=? WHERE id=? AND worker_token=? " .
                "AND state IN ('running','cancelling')",
                [$state,$state,$storedCode,$reason,$now,$now,$id,$token]
            );
            if($changed!==1) return; // Stale worker has already been recovered.
            if($db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$id])==='scan')
                $db->executeStatement(
                    "UPDATE scan_runs SET state=?,finished_at=?,error_count=error_count+1 " .
                    "WHERE id=(SELECT run_id FROM jobs WHERE id=?)",[$state,$now,$id]
                );
            $eventCode=$state==='failed' && $storedCode!==$errorCode?
                $storedCode:$errorCode;
            if($eventCode!==$errorCode)
                $details['lifecycle_event']=$errorCode;
            $this->event($id,$state==='cancelled'?'warning':'error',$eventCode,$details);
        });
    }

    /**
     * Resolve expired job leases. Targeted resolution permits one job to be
     * recovered without modifying unrelated stalled jobs.
     *
     * This changes DB lease state, not the OS process. Caller MUST establish
     * worker absence before using it; late worker checkpoints reject the lease.
     */
    public function recoverStalled(int $seconds=600,?int $onlyId=null): int
    {
        $seconds=max(30,$seconds);
        $cutoff=gmdate('Y-m-d H:i:s',time()-$seconds);
        $sql="SELECT id,run_id,kind,state,file_action_batch_id,phase,heartbeat_at," .
            "progress_current,progress_total,progress_bytes_current,progress_bytes_total," .
            "progress_detail,last_progress_at,started_at FROM jobs " .
            "WHERE state IN ('running','cancelling') ".
            "AND (heartbeat_at IS NULL OR heartbeat_at<?)";
        $params=[$cutoff];
        if($onlyId!==null) {
            $sql.=' AND id=?';
            $params[]=$onlyId;
        }
        $rows=$this->db->fetchAllAssociative($sql,$params);
        $count=0;
        foreach($rows as $row) {
            $id=(int)$row['id'];
            $wasCancelling=$row['state']==='cancelling';
            $state=$wasCancelling?'cancelled':'failed';
            $code=WorkerFailureCode::stalled((string)$row['kind'],$wasCancelling);
            $phase=$wasCancelling?'cancelled':'stalled';
            $reason=($wasCancelling?
                    'Cancellation was already requested, but the worker did not acknowledge it. ':
                    'Worker heartbeat expired before completion. ').
                'Last phase: '.(string)$row['phase'].
                '; last heartbeat: '.(string)($row['heartbeat_at']??'never').
                '; completed files: '.(int)$row['progress_current'].
                '/'.(int)($row['progress_total']??0).
                '; hashed bytes: '.(int)($row['progress_bytes_current']??0).
                '/'.(int)($row['progress_bytes_total']??0);
            $now=gmdate('Y-m-d H:i:s');
            $changed=$this->db->executeStatement(
                "UPDATE jobs SET state=?,phase=?,error_code=?,".
                "last_failure_reason=?,active_profile_id=NULL,worker_token=NULL,finished_at=? ".
                "WHERE id=? AND state=? AND (heartbeat_at IS NULL OR heartbeat_at<?)",
                [$state,$phase,$code,$reason,$now,$id,$row['state'],$cutoff]
            );
            if($changed!==1) continue;
            if($row['kind']==='duplicate-verify')
                $this->db->delete('duplicate_verification_hashes',['job_id'=>$id]);
            if($row['kind']==='scan') {
                $this->db->executeStatement(
                    'UPDATE scan_runs SET state=?,finished_at=?,error_count=error_count+1 WHERE id=?',
                    [$state,$now,(int)$row['run_id']]
                );
            }
            if(in_array($row['kind'],['file-action','file-restore','file-rename',
                'file-move','file-move-restore','file-link','file-unlink',
                'bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'],true))
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code=? ".
                    "WHERE id=? AND state IN ('queued','executing','restore_queued','restore_executing')",
                    [$code,(int)$row['file_action_batch_id']]
                );
            $this->event($id,$wasCancelling?'warning':'error',$code,[
                'job_kind'=>$row['kind'],
                'lifecycle_event'=>$wasCancelling?'CANCEL_RESOLVED_AFTER_STALL':'WORKER_STALLED',
                'explanation'=>$wasCancelling?
                    'Cancellation was pending and the worker stopped reporting. '.
                    'The expired lease was cleared administratively; this does not terminate an OS process.':
                    'No job heartbeat was recorded within the recovery window. '.
                    'This does not identify why the process stopped responding.',
                'previous_state'=>$row['state'],
                'final_state'=>$state,
                'phase'=>$row['phase'],
                'last_heartbeat_at'=>$row['heartbeat_at'],
                'last_progress_at'=>$row['last_progress_at'],
                'progress_detail'=>$row['progress_detail'],
                'files_completed'=>(int)$row['progress_current'],
                'files_total'=>$row['progress_total']===null?null:(int)$row['progress_total'],
                'bytes_hashed'=>(int)($row['progress_bytes_current']??0),
                'bytes_total'=>$row['progress_bytes_total']===null?null:(int)$row['progress_bytes_total'],
                'job_started_at'=>$row['started_at'],
                'timeout_seconds'=>$seconds,
                'recovery'=>'Job lease released and a terminal state recorded; no source files were deleted.',
                'recommended_check'=>'Check the worker service state and inspect process logs before retrying.'
            ]);
            $count++;
        }
        return $count;
    }
}
