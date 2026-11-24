<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use Doctrine\DBAL\Connection;

/**
 * All filesystem mutations happen here, in the shared CLI worker only.
 * Reversible same-device renames; never copy+unlink or permanently delete.
 */
final class FileActionWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly FileActionService $actions,
        private readonly SafeFileSystem $fs
    ) {}

    /** @param array<string,mixed> $job */
    public function run(array $job): void
    {
        $jobId=(int)$job['id'];
        $batchId=(int)($job['file_action_batch_id']??0);
        $token=(string)$job['worker_token'];
        $restoring=$job['kind']==='file-restore';
        if(!in_array($job['kind'],['file-action','file-restore'],true) || $batchId<=0)
            throw new \LogicException('Invalid file action job.');
        $row=$this->db->fetchAssociative(
            'SELECT b.id AS batch_id,b.actor_id,b.profile_id,b.state AS batch_state,' .
            'i.file_entry_id,i.source_path,i.target_path,i.expected_size,i.expected_mtime,' .
            'i.expected_sha256,i.survivor_file_entry_id,i.state AS item_state,' .
            'f.error_code AS file_error,f.run_id,f.hash_value,p.move_root,d.path AS directory ' .
            'FROM file_action_batches b JOIN file_action_items i ON i.batch_id=b.id ' .
            'JOIN file_entries f ON f.id=i.file_entry_id ' .
            'JOIN directories d ON d.id=f.directory_id ' .
            'JOIN scan_profiles p ON p.id=b.profile_id ' .
            'WHERE b.id=? AND f.run_id=?',
            [$batchId,(int)$job['run_id']]
        );
        $moved=false;$from='';$to='';$phase=$restoring?'restoring':'quarantining';
        try {
            $this->jobs->assertRunning($jobId,$token);
            if(!$row || $row['batch_state']!==($restoring?'restore_queued':'queued') ||
               $row['item_state']!==($restoring?'quarantined':'planned'))
                throw new \DomainException('The file action is no longer authorized.');
            $entryId=(int)$row['file_entry_id'];
            $size=(int)$row['expected_size'];
            $mtime=(int)$row['expected_mtime'];
            $digest=(string)$row['expected_sha256'];
            if(!preg_match('/^[a-f0-9]{64}$/i',$digest))
                throw new \DomainException('Verified SHA-256 evidence is missing.');
            $source=(string)$row['source_path'];
            $target=(string)$row['target_path'];
            $directory=$this->fs->checkedRoot((string)$row['directory']);
            if(!$this->fs->samePath($source,$directory.DIRECTORY_SEPARATOR.basename($source)))
                throw new \DomainException('Recorded source path does not match its directory.');
            $moveRoot=$this->fs->checkedRoot((string)$row['move_root']);
            $expected=$moveRoot.DIRECTORY_SEPARATOR.'.dup-quarantine'.DIRECTORY_SEPARATOR.
                'batch-'.$batchId.DIRECTORY_SEPARATOR.'entry-'.$entryId.
                DIRECTORY_SEPARATOR.basename($source);
            if(!$this->fs->samePath($expected,$target))
                throw new \DomainException('Quarantine location changed since approval.');
            if(!$restoring) {
                if($row['file_error']!==null || !$this->actions->latestRun(
                    (int)$row['profile_id'],(int)$row['run_id']))
                    throw new \DomainException('Source scan changed after approval.');
                $current=$this->fs->checkedPath($directory,basename($source));
                $this->fs->requireSameDevice($current,$moveRoot);
                $survivor=$this->db->fetchAssociative(
                    "SELECT d.path AS directory,f.filename,f.size_bytes,f.mtime_epoch," .
                    "f.hash_value FROM duplicate_group_members m JOIN duplicate_groups g " .
                    "ON g.id=m.group_id JOIN duplicate_group_members other " .
                    "ON other.group_id=g.id JOIN file_entries f ON f.id=other.file_entry_id " .
                    "JOIN directories d ON d.id=f.directory_id " .
                    "WHERE m.file_entry_id=? AND f.id=? AND f.id<>? AND g.rules_version=1 " .
                    "AND g.run_id=? AND f.error_code IS NULL AND d.is_ignored=0 " .
                    "AND f.hash_method='sha256' LIMIT 1",
                    [$entryId,(int)$row['survivor_file_entry_id'],$entryId,(int)$row['run_id']]
                );
                if(!$survivor || !hash_equals($digest,(string)$survivor['hash_value']))
                    throw new \DomainException('No verified surviving duplicate remains.');
                $survivorPath=$this->fs->checkedPath(
                    (string)$survivor['directory'],(string)$survivor['filename']
                );
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='executing' WHERE id=? AND state='queued'",
                    [$batchId]
                );
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='verifying' WHERE batch_id=?",[$batchId]
                );
                $this->jobs->event($jobId,'info','ACTION_VERIFICATION_STARTED',[
                    'batch_id'=>$batchId,'target_path'=>$target
                ]);
                $this->verify($current,$size,$mtime,$digest,$jobId,$token,0);
                $this->verify($survivorPath,(int)$survivor['size_bytes'],
                    (int)$survivor['mtime_epoch'],$digest,$jobId,$token,1);
                // No final survivor can be silently removed: verify it still exists.
                $this->fs->checkedStat($survivorPath,
                    (int)$survivor['size_bytes'],(int)$survivor['mtime_epoch']);
                $this->fs->prepareQuarantine($moveRoot,$batchId,$entryId);
                $from=$current;$to=$target;
            } else {
                if($row['file_error']!=='QUARANTINED')
                    throw new \DomainException('The source record is not quarantined.');
                if(file_exists($source) || is_link($source))
                    throw new \DomainException('Original path is occupied; restoration cannot overwrite files.');
                if(is_link(dirname($target)) ||
                   !$this->fs->samePath((string)realpath(dirname($target)),dirname($target)))
                    throw new \DomainException('Quarantine directory changed.');
                $this->fs->requireSameDevice($target,$directory);
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='restore_executing' " .
                    "WHERE id=? AND state='restore_queued'",[$batchId]
                );
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='restoring' WHERE batch_id=?",[$batchId]
                );
                $this->jobs->event($jobId,'info','RESTORE_VERIFICATION_STARTED',[
                    'batch_id'=>$batchId,'target_path'=>$source
                ]);
                $this->verify($target,$size,$mtime,$digest,$jobId,$token,0);
                $from=$target;$to=$source;
            }
            // Final pre-move safety check. The destination is always private and unique.
            $this->jobs->assertRunning($jobId,$token);
            $this->jobs->progress($jobId,$token,$phase,2,3);
            $this->fs->renameExactly($from,$to,$size,$mtime);
            $moved=true;
            $this->fs->checkedStat($to,$size,$mtime);
            $this->finish($job,$row,$restoring);
            $moved=false;
        } catch(\Throwable $ex) {
            $rollbackFailed=false;
            if($moved) {
                // Compensate if a DB commit failed AFTER a successful rename.
                clearstatcache(true,$from);
                if(!file_exists($from) && !is_link($from) && @rename($to,$from)) {
                    $moved=false;
                } else {
                    $rollbackFailed=true;
                }
            }
            // An unsuccessful restore leaves the quarantined file in place and
            // can be retried after an obstruction has been cleared.
            $status=$rollbackFailed?'needs_review':($restoring?'completed':'failed');
            try {
                $this->db->executeStatement(
                    'UPDATE file_action_batches SET state=?,error_code=? WHERE id=?',
                    [$status,$rollbackFailed?'MANUAL_RECOVERY_REQUIRED':($restoring?null:'ACTION_FAILED'),$batchId]
                );
                if(!$rollbackFailed) $this->db->executeStatement(
                    'UPDATE file_action_items SET state=?,result_code=? WHERE batch_id=?',
                    [$restoring?'quarantined':'failed',$restoring?'RESTORE_FAILED':'ACTION_FAILED',$batchId]
                );
                $this->actions->audit($row?(int)$row['actor_id']:null,
                    $rollbackFailed?'ACTION_NEEDS_REVIEW':'ACTION_FAILED',$batchId,
                    $status,['kind'=>$job['kind'],'reason'=>mb_substr($ex->getMessage(),0,180)]);
                $this->jobs->stop($jobId,$token,'failed',
                    $rollbackFailed?'MANUAL_RECOVERY_REQUIRED':'FILE_ACTION_FAILED');
            } catch(\Throwable) {
                // The worker command must still fail; stale recovery will flag the batch.
            }
            throw $ex;
        }
    }

    private function verify(string $path,int $size,int $mtime,string $digest,
        int $jobId,string $token,int $step): void
    {
        $this->jobs->progress($jobId,$token,'verifying_sha256',$step,3);
        $last=time();
        $this->fs->verifyDigest($path,$size,$mtime,$digest,function() use (
            $jobId,$token,$step,&$last
        ): void {
            if(time()-$last>=2) {
                $this->jobs->progress($jobId,$token,'verifying_sha256',$step,3);
                $last=time();
            }
        });
    }

    private function finish(array $job,array $row,bool $restoring): void
    {
        $jobId=(int)$job['id'];
        $token=(string)$job['worker_token'];
        $batchId=(int)$row['batch_id'];
        $entryId=(int)$row['file_entry_id'];
        $profileId=(int)$row['profile_id'];
        $runId=(int)$row['run_id'];
        $newFlag=$restoring?null:'QUARANTINED';
        $itemState=$restoring?'restored':'quarantined';
        $batchState=$restoring?'restored':'completed';
        $this->db->transactional(function(Connection $db) use (
            $jobId,$token,$batchId,$entryId,$profileId,$runId,$newFlag,
            $itemState,$batchState,$restoring,$row
        ): void {
            $this->jobs->assertRunning($jobId,$token);
            $where=$restoring?"error_code='QUARANTINED'":'error_code IS NULL';
            $affected=$db->executeStatement(
                'UPDATE file_entries SET error_code=? WHERE id=? AND '.$where,
                [$newFlag,$entryId]
            );
            if($affected!==1) throw new \RuntimeException('File record changed during action.');
            $db->executeStatement(
                'UPDATE file_action_items SET state=?,result_code=? WHERE batch_id=?',
                [$itemState,$restoring?'RESTORED':'QUARANTINED',$batchId]
            );
            $db->executeStatement(
                'UPDATE file_action_batches SET state=?,completed_at=?,error_code=NULL WHERE id=?',
                [$batchState,gmdate('Y-m-d H:i:s'),$batchId]
            );
            $finished=$db->executeStatement(
                "UPDATE jobs SET state='completed',phase='completed',progress_current=3," .
                "progress_total=3,active_profile_id=NULL,finished_at=?,heartbeat_at=? " .
                "WHERE id=? AND kind=? AND worker_token=? AND state='running'",
                [gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$jobId,
                    $restoring?'file-restore':'file-action',$token]
            );
            if($finished!==1) throw new LostLease('Worker job lease changed before committing.','DUP-FILE-ACTION-LEASE-101');
            $db->insert('jobs',[
                'kind'=>'rebuild-duplicates','profile_id'=>$profileId,
                'active_profile_id'=>$profileId,'run_id'=>$runId,
                'state'=>'queued','phase'=>'waiting','progress_current'=>'0',
                'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
            ]);
            $this->actions->audit((int)$row['actor_id'],
                $restoring?'FILE_RESTORED':'FILE_QUARANTINED',$batchId,'completed',
                ['file_entry_id'=>$entryId,'rebuild_job_id'=>(int)$db->lastInsertId()]);
            $this->jobs->event($jobId,'info',$restoring?'RESTORE_COMPLETED':'QUARANTINE_COMPLETED',[
                'batch_id'=>$batchId,'rebuild_queued'=>true
            ]);
        });
    }
}
