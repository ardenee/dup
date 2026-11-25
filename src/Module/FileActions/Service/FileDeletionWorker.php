<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use Doctrine\DBAL\Connection;

/** Unlinks only the exact files confirmed in a delete batch. */
final class FileDeletionWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $audit
    ) {}

    public function run(array $job):void
    {
        $jobId=(int)$job['id'];$token=(string)$job['worker_token'];
        $batchId=(int)$job['file_action_batch_id'];
        if($job['kind']!=='file-delete' || $batchId<1)
            throw new \LogicException('Not a permanent deletion job.');
        $batch=$this->db->fetchAssociative(
            "SELECT * FROM file_action_batches WHERE id=? AND action_kind='delete'",[$batchId]
        );
        if(!$batch || $batch['state']!=='queued')
            throw new \DomainException('Delete batch is not authorized.');
        $items=$this->db->fetchAllAssociative(
            'SELECT i.*,f.run_id,f.filename,f.directory_id,f.error_code AS file_error,'.
            'd.path AS directory FROM file_action_items i '.
            'JOIN file_entries f ON f.id=i.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id '.
            'WHERE i.batch_id=? ORDER BY i.id',[$batchId]
        );
        // JOINs must not silently discard missing approved entries. Otherwise
        // a valid 13-file batch could incorrectly complete as 0/0.
        $approved=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items WHERE batch_id=?',[$batchId]
        );
        if($approved<1 || count($items)!==$approved ||
           ($job['progress_total']!==null && (int)$job['progress_total']!==$approved)) {
            $this->db->executeStatement(
                "UPDATE file_action_batches SET state='needs_review',error_code='DELETE_REVIEW' WHERE id=?",
                [$batchId]
            );
            throw new \DomainException('Delete batch item count changed before processing. Review the batch; no files were deleted by this job.');
        }
        $done=0;$failed=0;
        $this->jobs->assertRunning($jobId,$token);
        $this->db->executeStatement(
            "UPDATE file_action_batches SET state='executing' WHERE id=? AND state='queued'",[$batchId]
        );
        $this->jobs->progress($jobId,$token,'deleting_files',0,count($items));
        foreach($items as $item) {
            try {
                $this->jobs->assertRunning($jobId,$token);
                if($item['state']!=='planned' || $item['file_error']!==null ||
                   (int)$item['run_id']!==(int)$job['run_id'])
                    throw new \DomainException('This file is no longer eligible for deletion.');
                $source=$this->fs->checkedPath((string)$item['directory'],(string)$item['filename']);
                if(!$this->fs->samePath($source,(string)$item['source_path']))
                    throw new \DomainException('File location changed after confirmation.');
                $size=(int)$item['expected_size'];$mtime=(int)$item['expected_mtime'];
                $before=$this->fs->checkedStat($source,$size,$mtime);
                $this->jobs->assertRunning($jobId,$token);
                $last=$this->fs->checkedStat($source,$size,$mtime);
                if((string)$last['dev']!==(string)$before['dev'] ||
                   (string)$last['ino']!==(string)$before['ino'])
                    throw new \DomainException('File identity changed since confirmation.');
                if(!@unlink($source))
                    throw new \DomainException('Unable to delete the selected file.');
                clearstatcache(true,$source);
                if(file_exists($source) || is_link($source))
                    throw new \RuntimeException('File still exists after deletion.');
                $this->db->transactional(function(Connection $db) use($item,$batchId):void {
                    $db->executeStatement(
                        "UPDATE file_entries SET error_code='DELETED' WHERE id=?",
                        [(int)$item['file_entry_id']]
                    );
                    $db->executeStatement(
                        "UPDATE file_action_items SET state='deleted',result_code=NULL WHERE id=?",
                        [(int)$item['id']]
                    );
                    $this->audit->audit((int)$db->fetchOne(
                        'SELECT actor_id FROM file_action_batches WHERE id=?',[$batchId]
                    ),'FILE_DELETED',$batchId,'completed',[
                        'file_entry_id'=>(int)$item['file_entry_id']
                    ]);
                });
                $done++;
            } catch(LostLease $ex) {
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code='WORKER_STALLED' WHERE id=?",
                    [$batchId]
                );
                throw $ex;
            } catch(\Throwable $ex) {
                // If unlink succeeded but an audit/database update failed, the
                // exact state is uncertain. Never retry automatically.
                clearstatcache(true,(string)$item['source_path']);
                if(!file_exists((string)$item['source_path']) &&
                   !is_link((string)$item['source_path'])) {
                    $this->db->executeStatement(
                        "UPDATE file_action_batches SET state='needs_review',error_code='DELETE_REVIEW' WHERE id=?",
                        [$batchId]
                    );
                    $this->jobs->event($jobId,'error','DELETE_NEEDS_REVIEW',[
                        'file_entry_id'=>(int)$item['file_entry_id'],
                        'reason'=>mb_substr($ex->getMessage(),0,200)
                    ]);
                    throw $ex;
                }
                $failed++;
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='failed',result_code='DELETE_FAILED' WHERE id=?",
                    [(int)$item['id']]
                );
                $this->jobs->event($jobId,'warning','DELETE_ITEM_FAILED',[
                    'file_entry_id'=>(int)$item['file_entry_id'],
                    'reason'=>mb_substr($ex->getMessage(),0,200)
                ]);
            }
            $this->jobs->progress($jobId,$token,'deleting_files',$done+$failed,count($items));
        }
        $this->db->transactional(function(Connection $db) use (
            $job,$jobId,$token,$batchId,$done,$failed,$items
        ):void {
            $this->jobs->assertRunning($jobId,$token);
            $now=gmdate('Y-m-d H:i:s');
            $state=$failed?($done?'partial':'failed'):'completed';
            $db->executeStatement(
                'UPDATE file_action_batches SET state=?,completed_at=?,error_code=? WHERE id=?',
                [$state,$now,$failed?'DELETE_PARTIAL_FAILURE':null,$batchId]
            );
            $updated=$db->executeStatement(
                "UPDATE jobs SET state='completed',phase='completed',active_profile_id=NULL,".
                "finished_at=?,heartbeat_at=?,progress_current=?,progress_total=? ".
                "WHERE id=? AND worker_token=? AND state='running'",
                [$now,$now,$done+$failed,count($items),$jobId,$token]
            );
            if($updated!==1) throw new LostLease('Deletion job lost its worker lease.','DUP-DELETE-LEASE-101');
            $this->jobs->event($jobId,'info','DELETE_BATCH_FINISHED',[
                'deleted'=>$done,'failed'=>$failed,'irreversible'=>true
            ]);
        });
    }
}
