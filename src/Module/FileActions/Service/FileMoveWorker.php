<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use Doctrine\DBAL\Connection;

/** Reversible, same-device, non-overwriting moves on the shared CLI queue. */
final class FileMoveWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly SafeFileSystem $fs,
        private readonly MovePathPlanner $paths,
        private readonly FileActionService $actions
    ) {}

    public function run(array $job): void
    {
        $jobId=(int)$job['id'];
        $batchId=(int)($job['file_action_batch_id']??0);
        $token=(string)$job['worker_token'];
        $restoring=$job['kind']==='file-move-restore';
        if(!in_array($job['kind'],['file-move','file-move-restore'],true) || !$batchId)
            throw new \LogicException('Invalid move job.');
        $batch=$this->db->fetchAssociative(
            "SELECT * FROM file_action_batches WHERE id=? AND action_kind='move'",[$batchId]
        );
        if(!$batch || $batch['state']!==($restoring?'restore_queued':'queued'))
            throw new \DomainException('The move batch is no longer authorized.');
        $items=$this->db->fetchAllAssociative(
            'SELECT i.*,f.filename,f.error_code AS file_error,f.run_id,'.
            'f.size_bytes,f.mtime_epoch,d.path AS directory,'.
            'p.source_root,p.move_root FROM file_action_items i '.
            'JOIN file_entries f ON f.id=i.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id '.
            'JOIN scan_profiles p ON p.id=? '.
            'WHERE i.batch_id=? ORDER BY i.id',
            [(int)$batch['profile_id'],$batchId]
        );
        $work=array_values(array_filter($items,static fn(array $x):bool =>
            $x['state']===($restoring?'moved':'planned')
        ));
        $success=0;$failures=0;
        try {
            $this->jobs->assertRunning($jobId,$token);
            // A JOIN must never shrink an approved move/restore batch into a
            // misleading successful 0/0 or a partial unreported operation.
            $expected=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state=?',
                [$batchId,$restoring?'moved':'planned']
            );
            $all=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM file_action_items WHERE batch_id=?',[$batchId]
            );
            if($expected<1 || $expected!==count($work) ||
               (!$restoring && $all!==$expected) ||
               ($job['progress_total']!==null && (int)$job['progress_total']!==$expected)) {
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code='MOVE_WORKER_FAILED' WHERE id=?",
                    [$batchId]
                );
                throw new \DomainException('Move batch item count changed before processing; no files were moved by this job.');
            }
            $this->db->executeStatement(
                'UPDATE file_action_batches SET state=? WHERE id=?',
                [$restoring?'restore_executing':'executing',$batchId]
            );
            $this->jobs->progress($jobId,$token,
                $restoring?'restoring_files':'moving_files',0,count($work));
            foreach($work as $item) {
                $from='';$to='';$moved=false;$overwriteStaged=false;$published=false;
                $overwriteBackup=(string)($item['overwrite_backup_path']??'');
                try {
                    $this->jobs->assertRunning($jobId,$token);
                    $size=(int)$item['expected_size'];
                    $mtime=(int)$item['expected_mtime'];
                    if($size!==(int)$item['size_bytes'] || $mtime!==(int)$item['mtime_epoch'] ||
                       (int)$item['run_id']!==(int)$job['run_id'])
                        throw new \DomainException('The file snapshot changed since confirmation.');
                    $directory=$this->fs->checkedRoot((string)$item['directory']);
                    $source=$directory.DIRECTORY_SEPARATOR.(string)$item['filename'];
                    $target=(string)$item['target_path'];
                    if(!$this->fs->samePath($source,(string)$item['source_path']))
                        throw new \DomainException('The source location changed since preview.');
                    $moveRoot=$this->fs->checkedRoot((string)$item['move_root']);
                    $planned=$this->paths->destination(
                        (string)$item['source_root'],$moveRoot,
                        $directory,(string)$item['filename'],!$restoring
                    );
                    if(!$this->fs->samePath($planned,$target) &&
                        (!$this->fs->samePath(dirname($planned),dirname($target)) ||
                         !\App\Module\Renaming\Service\RenameRules::isNumberedVariant(
                             basename($planned),basename($target))))
                        throw new \DomainException('Move destination changed since confirmation.');
                    if(!$restoring) {
                        if($item['file_error']!==null)
                            throw new \DomainException('This file is no longer available to move.');
                        $current=$this->fs->checkedPath($directory,(string)$item['filename']);
                        $this->fs->checkedStat($current,$size,$mtime);
                        $this->fs->requireSameDevice($current,$moveRoot);
                        // Atomic same-device move: preserve recorded scan evidence,
                        // but do not re-hash file contents as a side effect.
                        $digest=$item['expected_sha256']===null?null:(string)$item['expected_sha256'];
                        $this->paths->ensureParent($moveRoot,$target);
                        $from=$current;$to=$target;
                        if($overwriteBackup!=='') {
                            if($item['overwrite_target_size']===null ||
                               $item['overwrite_target_mtime']===null)
                                throw new \DomainException('Overwritten file evidence missing.');
                            $this->jobs->assertRunning($jobId,$token);
                            $this->fs->stageReplacement($target,$overwriteBackup,
                                (int)$item['overwrite_target_size'],
                                (int)$item['overwrite_target_mtime']);
                            $overwriteStaged=true;
                        }
                    } else {
                        if($item['file_error']!=='MOVED')
                            throw new \DomainException('The file is no longer recorded as moved.');
                        $digest=$item['expected_sha256']===null?null:(string)$item['expected_sha256'];
                        $this->paths->ensureParent($moveRoot,$target);
                        $this->fs->checkedStat($target,$size,$mtime);
                        if(file_exists($source) || is_link($source))
                            throw new \DomainException('The original path is occupied; restore cannot overwrite it.');
                        $this->fs->requireSameDevice($target,$directory);
                        $from=$target;$to=$source;
                    }
                    $this->jobs->assertRunning($jobId,$token);
                    $this->fs->renameExactly($from,$to,$size,$mtime);
                    $moved=true;
                    $this->fs->checkedStat($to,$size,$mtime);
                    $this->db->transactional(function(Connection $db) use (
                        $jobId,$token,$item,$restoring,$digest,$batchId
                    ):void {
                        $this->jobs->assertRunning($jobId,$token);
                        $affected=$db->executeStatement(
                            'UPDATE file_entries SET error_code=? WHERE id=? AND '.
                            ($restoring?"error_code='MOVED'":'error_code IS NULL'),
                            [$restoring?null:'MOVED',(int)$item['file_entry_id']]
                        );
                        if($affected!==1)
                            throw new \RuntimeException('File record changed during a move.');
                        $db->executeStatement(
                            'UPDATE file_action_items SET state=?,expected_sha256=?,result_code=NULL '.
                            'WHERE id=? AND batch_id=?',
                            [$restoring?'restored':'moved',$digest,(int)$item['id'],$batchId]
                        );
                        $this->actions->audit((int)$db->fetchOne(
                            'SELECT actor_id FROM file_action_batches WHERE id=?',[$batchId]
                        ),$restoring?'FILE_MOVE_RESTORED':'FILE_MOVED',
                            $batchId,'completed',['file_entry_id'=>(int)$item['file_entry_id']]);
                    });
                    $moved=false;
                    $published=true;
                    if($overwriteStaged) {
                        try {
                            $this->fs->finishReplacement($overwriteBackup,
                                (int)$item['overwrite_target_size'],
                                (int)$item['overwrite_target_mtime']);
                        } catch(\Throwable $ex) {
                            $this->db->executeStatement(
                                "UPDATE file_action_batches SET state='needs_review',".
                                "error_code='OVERWRITE_BACKUP_REVIEW' WHERE id=?",[$batchId]
                            );
                            $this->jobs->event($jobId,'error','OVERWRITE_BACKUP_REVIEW',[
                                'file_entry_id'=>(int)$item['file_entry_id'],
                                'backup_path'=>$overwriteBackup
                            ]);
                            throw $ex;
                        }
                    }
                    $success++;
                } catch(\Throwable $ex) {
                    if($published) throw $ex;
                    if($moved) {
                        clearstatcache(true,$from);
                        if(file_exists($from) || is_link($from) || !@rename($to,$from)) {
                            $this->db->executeStatement(
                                "UPDATE file_action_batches SET state='needs_review',".
                                "error_code='MANUAL_RECOVERY_REQUIRED' WHERE id=?",[$batchId]
                            );
                            $this->jobs->event($jobId,'error','MOVE_NEEDS_REVIEW',[
                                'file_entry_id'=>(int)$item['file_entry_id']
                            ]);
                            throw new \RuntimeException(
                                'Move rollback could not be completed. Manual recovery is required.',0,$ex
                            );
                        }
                    }
                    if($overwriteStaged) {
                        try {
                            $this->fs->restoreReplacement(
                                (string)$item['target_path'],$overwriteBackup,
                                (int)$item['overwrite_target_size'],
                                (int)$item['overwrite_target_mtime']
                            );
                        } catch(\Throwable $restoreError) {
                            $this->db->executeStatement(
                                "UPDATE file_action_batches SET state='needs_review',".
                                "error_code='MANUAL_RECOVERY_REQUIRED' WHERE id=?",[$batchId]
                            );
                            throw new \RuntimeException(
                                'Approved destination backup could not be restored.',0,$restoreError
                            );
                        }
                    }
                    $this->db->executeStatement(
                        'UPDATE file_action_items SET state=?,result_code=? WHERE id=?',
                        [$restoring?'moved':'failed',$restoring?'RESTORE_FAILED':'MOVE_FAILED',
                         (int)$item['id']]
                    );
                    $this->jobs->event($jobId,'warning','MOVE_ITEM_FAILED',[
                        'file_entry_id'=>(int)$item['file_entry_id'],
                        'reason'=>mb_substr($ex->getMessage(),0,180)
                    ]);
                    $failures++;
                }
                $this->jobs->progress($jobId,$token,
                    $restoring?'restoring_files':'moving_files',
                    $success+$failures,count($work));
            }
            $this->db->transactional(function(Connection $db) use (
                $job,$jobId,$token,$batchId,$restoring,$success,$failures
            ):void {
                $this->jobs->assertRunning($jobId,$token);
                $now=gmdate('Y-m-d H:i:s');
                $state=$failures?($success?'partial':'failed'):
                    ($restoring?'restored':'completed');
                $db->executeStatement(
                    'UPDATE file_action_batches SET state=?,completed_at=?,error_code=? WHERE id=?',
                    [$state,$now,$failures?'MOVE_PARTIAL_FAILURE':null,$batchId]
                );
                $updated=$db->executeStatement(
                    "UPDATE jobs SET state='completed',phase='completed',active_profile_id=NULL,".
                    "finished_at=?,heartbeat_at=? WHERE id=? AND worker_token=? AND state='running'",
                    [$now,$now,$jobId,$token]
                );
                if($updated!==1) throw new LostLease('Move worker job lease was lost.','DUP-MOVE-LEASE-101');
                $this->jobs->event($jobId,'info','MOVE_BATCH_FINISHED',[
                    'batch_id'=>$batchId,'restoring'=>$restoring,
                    'completed'=>$success,'failed'=>$failures
                ]);
            });
        } catch(\Throwable $ex) {
            $this->db->executeStatement(
                "UPDATE file_action_batches SET state='needs_review',".
                "error_code='MOVE_WORKER_FAILED' WHERE id=? AND ".
                "state IN ('executing','restore_executing')",[$batchId]
            );
            $this->jobs->stop($jobId,$token,'failed','MOVE_WORKER_FAILED');
            throw $ex;
        }
    }


}
