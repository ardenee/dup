<?php
declare(strict_types=1);
namespace App\Module\Renaming\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\FileActionService;
use Doctrine\DBAL\Connection;

/**
 * Filesystem-mutating rename worker. Every move stays within one directory,
 * never overwrites, and is checked against the current snapshot before use.
 * A failed DB update is compensated with a rename back to its source.
 */
final class RenameWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $actions
    ) {}

    public function run(array $job): void
    {
        $id=(int)$job['id'];
        $token=(string)$job['worker_token'];
        $batchId=(int)($job['file_action_batch_id']??0);
        if($job['kind']!=='file-rename' || $batchId<1)
            throw new \LogicException('Invalid rename job.');
        $batch=$this->db->fetchAssociative(
            'SELECT * FROM file_action_batches WHERE id=? AND action_kind=?',
            [$batchId,'rename']
        );
        if(!$batch || $batch['state']!=='queued')
            throw new \DomainException('The rename batch is no longer queued.');
        $items=$this->db->fetchAllAssociative(
            'SELECT * FROM file_action_items WHERE batch_id=? ORDER BY id',[$batchId]
        );
        $total=count($items);$success=0;$errors=0;$anyOverwritten=false;
        try {
            $this->jobs->assertRunning($id,$token);
            $this->db->executeStatement(
                "UPDATE file_action_batches SET state='executing' WHERE id=? AND state='queued'",
                [$batchId]
            );
            $this->jobs->progress($id,$token,'renaming',0,$total);
            foreach($items as $item) {
                $moved=false;$overwriteStaged=false;$published=false;
                $overwriteBackup=(string)($item['overwrite_backup_path']??'');
                $source=(string)$item['source_path'];
                $target=(string)$item['target_path'];
                try {
                    $this->jobs->assertRunning($id,$token);
                    $file=$this->db->fetchAssociative(
                        'SELECT f.filename,f.error_code,f.size_bytes,f.mtime_epoch,'.
                        'f.run_id,d.path AS directory FROM file_entries f '.
                        'JOIN directories d ON d.id=f.directory_id WHERE f.id=?',
                        [(int)$item['file_entry_id']]
                    );
                    if(!$file || (int)$file['run_id']!==(int)$job['run_id'] ||
                        $file['error_code']!==null || $item['state']!=='planned')
                        throw new \DomainException('Recorded scan file is no longer available.');
                    if((int)$file['size_bytes']!==(int)$item['expected_size'] ||
                       (int)$file['mtime_epoch']!==(int)$item['expected_mtime'])
                        throw new \DomainException('File snapshot changed.');
                    $directory=$this->fs->checkedRoot((string)$file['directory']);
                    $recorded=$this->fs->checkedPath($directory,(string)$file['filename']);
                    if(!$this->fs->samePath($source,$recorded) ||
                       !$this->fs->samePath(dirname($target),$directory))
                        throw new \DomainException('File paths changed since approval.');
                    RenameRules::validateName(basename($target));
                    if($this->fs->samePath($source,$target))
                        throw new \DomainException('Source and destination names match.');
                    $size=(int)$item['expected_size'];
                    $mtime=(int)$item['expected_mtime'];
                    if($overwriteBackup!=='') {
                        if($item['overwrite_target_size']===null ||
                           $item['overwrite_target_mtime']===null)
                            throw new \DomainException('Approved destination evidence is unavailable.');
                        $this->fs->stageReplacement($target,$overwriteBackup,
                            (int)$item['overwrite_target_size'],
                            (int)$item['overwrite_target_mtime']);
                        $overwriteStaged=true;
                    }
                    $this->fs->renameExactly($source,$target,$size,$mtime);
                    $moved=true;
                    $this->fs->checkedStat($target,$size,$mtime);
                    $this->db->transactional(function(Connection $db) use (
                        $id,$token,$item,$source,$target,$file,$batchId
                    ):void {
                        $this->jobs->assertRunning($id,$token);
                        $updated=$db->executeStatement(
                            'UPDATE file_entries SET filename=? '.
                            'WHERE id=? AND filename=? AND error_code IS NULL',
                            [basename($target),(int)$item['file_entry_id'],(string)$file['filename']]
                        );
                        if($updated!==1) throw new \RuntimeException('Scan index changed during rename.');
                        if(($item['overwrite_backup_path']??null)!==null) {
                            $db->executeStatement(
                                "UPDATE file_entries SET error_code='OVERWRITTEN' ".
                                "WHERE run_id=? AND directory_id=(".
                                "SELECT id FROM directories WHERE run_id=? AND path=?) ".
                                "AND filename=? AND id<>? AND error_code IS NULL",
                                [(int)$file['run_id'],(int)$file['run_id'],
                                 (string)$file['directory'],basename($target),
                                 (int)$item['file_entry_id']]
                            );
                        }
                        $db->executeStatement(
                            "UPDATE file_action_items SET state='renamed',result_code=NULL ".
                            'WHERE id=? AND batch_id=?',
                            [(int)$item['id'],$batchId]
                        );
                        $this->actions->audit((int)$this->db->fetchOne(
                            'SELECT actor_id FROM file_action_batches WHERE id=?',[$batchId]
                        ),'FILE_RENAMED',$batchId,'completed',[
                            'file_entry_id'=>(int)$item['file_entry_id'],
                            'old_name'=>basename($source),'new_name'=>basename($target)
                        ]);
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
                            throw $ex;
                        }
                    }
                    if($overwriteStaged) $anyOverwritten=true;
                    $success++;
                } catch(\Throwable $ex) {
                    if($published) throw $ex;
                    if($moved) {
                        if(file_exists($source) || is_link($source) || !@rename($target,$source)) {
                            $this->db->executeStatement(
                                "UPDATE file_action_batches SET state='needs_review',".
                                "error_code='MANUAL_RECOVERY_REQUIRED' WHERE id=?",[$batchId]
                            );
                            $this->jobs->event($id,'error','RENAME_NEEDS_REVIEW',[
                                'file_entry_id'=>(int)$item['file_entry_id']
                            ]);
                            throw new \RuntimeException('Rename could not be rolled back; manual review required.',0,$ex);
                        }
                    }
                    if($overwriteStaged) {
                        try {
                            $this->fs->restoreReplacement($target,$overwriteBackup,
                                (int)$item['overwrite_target_size'],
                                (int)$item['overwrite_target_mtime']);
                        } catch(\Throwable $restoreError) {
                            $this->db->executeStatement(
                                "UPDATE file_action_batches SET state='needs_review',".
                                "error_code='MANUAL_RECOVERY_REQUIRED' WHERE id=?",[$batchId]
                            );
                            throw new \RuntimeException(
                                'Overwrite backup restoration failed; manual review is required.',0,$restoreError
                            );
                        }
                    }
                    $this->db->executeStatement(
                        "UPDATE file_action_items SET state='failed',result_code='RENAME_FAILED' ".
                        'WHERE id=? AND batch_id=?',[(int)$item['id'],$batchId]
                    );
                    $this->jobs->event($id,'warning','RENAME_FAILED',[
                        'file_entry_id'=>(int)$item['file_entry_id'],
                        'reason'=>mb_substr($ex->getMessage(),0,180)
                    ]);
                    $errors++;
                }
                $this->jobs->progress($id,$token,'renaming',$success+$errors,$total);
            }
            $this->db->transactional(function(Connection $db) use (
                $id,$token,$batchId,$success,$errors,$total,$anyOverwritten
            ):void {
                $this->jobs->assertRunning($id,$token);
                $now=gmdate('Y-m-d H:i:s');
                $db->executeStatement(
                    'UPDATE file_action_batches SET state=?,completed_at=?,error_code=? WHERE id=?',
                    [$errors>0?'partial':'completed',$now,$errors>0?'PARTIAL_FAILURE':null,$batchId]
                );
                $changed=$db->executeStatement(
                    "UPDATE jobs SET state='completed',phase='completed',progress_current=?,".
                    'progress_total=?,active_profile_id=NULL,finished_at=?,heartbeat_at=? '.
                    "WHERE id=? AND kind='file-rename' AND worker_token=? AND state='running'",
                    [$total,$total,$now,$now,$id,$token]
                );
                if($changed!==1) throw new LostLease('Rename worker job lease changed.','DUP-RENAME-LEASE-101');
                if($anyOverwritten) {
                    $profile=$db->fetchAssociative('SELECT profile_id,run_id FROM jobs WHERE id=?',[$id]);
                    $db->insert('jobs',[
                        'kind'=>'rebuild-duplicates','profile_id'=>$profile['profile_id'],
                        'active_profile_id'=>$profile['profile_id'],'run_id'=>$profile['run_id'],
                        'state'=>'queued','phase'=>'waiting','progress_current'=>0,
                        'created_at'=>$now,'attempts'=>0
                    ]);
                }
                $this->jobs->event($id,'info','RENAME_BATCH_COMPLETE',[
                    'batch_id'=>$batchId,'renamed'=>$success,'failed'=>$errors
                ]);
            });
        } catch(\Throwable $ex) {
            $this->db->executeStatement(
                "UPDATE file_action_batches SET state='needs_review',".
                "error_code='RENAME_WORKER_FAILED' WHERE id=? AND state='executing'",
                [$batchId]
            );
            $this->jobs->stop($id,$token,'failed','RENAME_WORKER_FAILED');
            throw $ex;
        }
    }
}
