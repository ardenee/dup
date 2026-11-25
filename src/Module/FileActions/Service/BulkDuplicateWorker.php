<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use Doctrine\DBAL\Connection;

/**
 * One leased job per approved bulk batch, with no arbitrary file count cap. Each item
 * is verified and audited independently; safe per-file failures remain visible.
 * Any filesystem rollback failure stops processing for manual recovery.
 */
final class BulkDuplicateWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly FileActionService $audit,
        private readonly SafeFileSystem $fs
    ) {}

    public function run(array $job):void
    {
        $jobId=(int)$job['id'];
        $token=(string)$job['worker_token'];
        $batchId=(int)($job['file_action_batch_id']??0);
        $kind=(string)$job['kind'];
        $link=in_array($kind,['bulk-link','bulk-unlink'],true);
        $restoring=in_array($kind,['bulk-restore','bulk-unlink'],true);
        if(!in_array($kind,['bulk-link','bulk-quarantine','bulk-restore','bulk-unlink'],true)
            || $batchId<1)
            throw new \LogicException('Invalid bulk file job.');
        $batch=$this->db->fetchAssociative(
            'SELECT b.*,p.move_root FROM file_action_batches b '.
            'JOIN scan_profiles p ON p.id=b.profile_id WHERE b.id=?',[$batchId]
        );
        $expectedKind=$link?'bulk_link':'bulk_quarantine';
        if(!$batch || $batch['action_kind']!==$expectedKind ||
            $batch['state']!==($restoring?'restore_queued':'queued'))
            throw new \DomainException('This bulk file job is no longer authorized.');
        $items=$this->db->fetchAllAssociative(
            'SELECT i.*,f.filename,f.run_id,f.size_bytes,f.mtime_epoch,'.
            'f.hash_method,f.hash_value,f.error_code AS file_error,'.
            'd.path AS directory,d.is_ignored '.
            'FROM file_action_items i JOIN file_entries f ON f.id=i.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id '.
            'WHERE i.batch_id=? ORDER BY i.id',[$batchId]
        );
        $active=$link?'linked':'quarantined';
        $work=array_values(array_filter($items,static fn(array $i):bool =>
            $i['state']===($restoring?$active:'planned')
        ));
        $selected=array_fill_keys(array_map(
            static fn(array $i):int=>(int)$i['file_entry_id'],$items
        ),true);
        $succeeded=0;$failed=0;$total=count($work);
        try {
            $this->jobs->assertRunning($jobId,$token);
            $expected=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state=?',
                [$batchId,$restoring?$active:'planned']
            );
            $all=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM file_action_items WHERE batch_id=?',[$batchId]
            );
            if($expected<1 || $expected!==$total ||
               (!$restoring && $all!==$expected) ||
               ($job['progress_total']!==null && (int)$job['progress_total']!==$expected)) {
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='needs_review',error_code='BULK_WORKER_FAILED' WHERE id=?",
                    [$batchId]
                );
                throw new \DomainException('Bulk batch item count changed before processing; no files were modified by this job.');
            }
            $this->db->executeStatement(
                'UPDATE file_action_batches SET state=? WHERE id=?',
                [$restoring?'restore_executing':'executing',$batchId]
            );
            $this->jobs->progress($jobId,$token,$restoring?'restoring':'verifying',0,$total);
            foreach($work as $item) {
                $this->jobs->assertRunning($jobId,$token);
                try {
                    $this->runItem($job,$batch,$item,$selected,$link,$restoring,$succeeded,$total);
                    $succeeded++;
                } catch(\Throwable $ex) {
                    if($ex instanceof BulkRecoveryRequired) throw $ex;
                    // DB state is never advanced if the disk operation failed.
                    $this->db->executeStatement(
                        'UPDATE file_action_items SET state=?,result_code=? WHERE id=?',
                        [$restoring?$active:'failed',
                         $restoring?'BULK_RESTORE_FAILED':'BULK_ACTION_FAILED',(int)$item['id']]
                    );
                    $this->jobs->event($jobId,'warning','BULK_ITEM_FAILED',[
                        'file_entry_id'=>(int)$item['file_entry_id'],
                        'reason'=>mb_substr($ex->getMessage(),0,180)
                    ]);
                    $failed++;
                }
                $this->jobs->progress($jobId,$token,
                    $restoring?'restoring':'processing',$succeeded+$failed,$total);
            }
            $this->db->transactional(function(Connection $db) use (
                $job,$jobId,$token,$batch,$batchId,$restoring,$link,$active,$succeeded,$failed,$total
            ):void {
                $this->jobs->assertRunning($jobId,$token);
                $remaining=(int)$db->fetchOne(
                    'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state=?',
                    [$batchId,$active]
                );
                $state=$restoring?($remaining===0?'restored':'partial'):
                    ($failed===0?'completed':($succeeded>0?'partial':'failed'));
                $now=gmdate('Y-m-d H:i:s');
                $db->executeStatement(
                    'UPDATE file_action_batches SET state=?,completed_at=?,error_code=? WHERE id=?',
                    [$state,$now,$failed?'BULK_PARTIAL_FAILURE':null,$batchId]
                );
                $changed=$db->executeStatement(
                    "UPDATE jobs SET state='completed',phase='completed',".
                    'progress_current=?,progress_total=?,active_profile_id=NULL,'.
                    'finished_at=?,heartbeat_at=? WHERE id=? AND worker_token=? AND state=?',
                    [$total,$total,$now,$now,$jobId,$token,'running']
                );
                if($changed!==1) throw new LostLease('Bulk job lease was lost.','DUP-BULK-LEASE-101');
                if($succeeded>0) {
                    $db->insert('jobs',[
                        'kind'=>'rebuild-duplicates','profile_id'=>(int)$batch['profile_id'],
                        'active_profile_id'=>(int)$batch['profile_id'],
                        'run_id'=>(int)$job['run_id'],'state'=>'queued',
                        'phase'=>'waiting','progress_current'=>0,
                        'created_at'=>$now,'attempts'=>0
                    ]);
                }
                $this->audit->audit((int)$batch['actor_id'],
                    $restoring?'BULK_DUPLICATES_RESTORED':'BULK_DUPLICATES_PROCESSED',
                    $batchId,$state,['successful'=>$succeeded,'failed'=>$failed]);
                $this->jobs->event($jobId,'info','BULK_BATCH_FINISHED',[
                    'successful'=>$succeeded,'failed'=>$failed,'state'=>$state
                ]);
            });
        } catch(\Throwable $ex) {
            $this->db->executeStatement(
                "UPDATE file_action_batches SET state='needs_review',error_code=? ".
                "WHERE id=? AND state IN ('executing','restore_executing')",
                [$ex instanceof BulkRecoveryRequired?
                    'MANUAL_RECOVERY_REQUIRED':'BULK_WORKER_FAILED',$batchId]
            );
            $this->jobs->stop($jobId,$token,'failed',
                $ex instanceof BulkRecoveryRequired?'MANUAL_RECOVERY_REQUIRED':'BULK_WORKER_FAILED');
            throw $ex;
        }
    }

    private function runItem(
        array $job,array $batch,array $item,array $selected,
        bool $link,bool $restoring,int $completed,int $total
    ):void {
        $jobId=(int)$job['id'];$token=(string)$job['worker_token'];
        $batchId=(int)$batch['id'];
        $entryId=(int)$item['file_entry_id'];
        $size=(int)$item['expected_size'];
        $mtime=(int)$item['expected_mtime'];
        $digest=strtolower((string)$item['expected_sha256']);
        if(!preg_match('/^[a-f0-9]{64}$/',$digest))
            throw new \DomainException('Verified SHA-256 evidence is missing.');
        if((int)$item['run_id']!==(int)$job['run_id'] ||
           $size!==(int)$item['size_bytes'] || $mtime!==(int)$item['mtime_epoch'])
            throw new \DomainException('The indexed file changed after confirmation.');
        $directory=$this->fs->checkedRoot((string)$item['directory']);
        $source=$directory.DIRECTORY_SEPARATOR.(string)$item['filename'];
        $backup=(string)$item['target_path'];
        if(!$this->fs->samePath($source,(string)$item['source_path']))
            throw new \DomainException('The source path changed after confirmation.');
        $moveRoot=$this->fs->checkedRoot((string)$batch['move_root']);
        $expected=$moveRoot.DIRECTORY_SEPARATOR.'.dup-quarantine'.
            DIRECTORY_SEPARATOR.'batch-'.$batchId.
            DIRECTORY_SEPARATOR.'entry-'.$entryId.DIRECTORY_SEPARATOR.
            (string)$item['filename'];
        if(!$this->fs->samePath($expected,$backup))
            throw new \DomainException('The protected recovery location has changed.');
        $survivor=(string)($item['link_target_path']??'');
        $backedUp=false;$linked=false;$unlinked=false;$returned=false;
        try {
            $this->jobs->assertRunning($jobId,$token);
            if(!$restoring) {
                if($item['file_error']!==null || (int)$item['is_ignored']!==0 ||
                   $item['hash_method']!=='sha256' ||
                   !hash_equals($digest,strtolower((string)$item['hash_value'])) ||
                   !$this->audit->latestRun((int)$batch['profile_id'],(int)$item['run_id']))
                    throw new \DomainException('The selected duplicate is no longer currently verified.');
                $survivorId=(int)$item['survivor_file_entry_id'];
                if(isset($selected[$survivorId]))
                    throw new \DomainException('The protected survivor was selected for modification.');
                $other=$this->db->fetchAssociative(
                    'SELECT f.filename,f.size_bytes,f.mtime_epoch,f.hash_value,'.
                    'f.hash_method,d.path AS directory FROM duplicate_group_members self '.
                    'JOIN duplicate_groups g ON g.id=self.group_id '.
                    'JOIN duplicate_group_members member ON member.group_id=g.id '.
                    'JOIN file_entries f ON f.id=member.file_entry_id '.
                    'JOIN directories d ON d.id=f.directory_id '.
                    'WHERE self.file_entry_id=? AND f.id=? AND g.run_id=? '.
                    "AND g.rules_version=1 AND f.error_code IS NULL AND d.is_ignored=0 LIMIT 1",
                    [$entryId,$survivorId,(int)$item['run_id']]
                );
                if(!$other || $other['hash_method']!=='sha256' ||
                    !hash_equals($digest,strtolower((string)$other['hash_value'])))
                    throw new \DomainException('The verified survivor is no longer available.');
                $survivorPath=$this->fs->checkedPath(
                    (string)$other['directory'],(string)$other['filename']
                );
                if($link && !$this->fs->samePath($survivorPath,$survivor))
                    throw new \DomainException('The approved link target changed.');
                $file=$this->fs->checkedPath($directory,(string)$item['filename']);
                $this->fs->requireSameDevice($file,$moveRoot);
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='verifying' WHERE id=? AND state='planned'",
                    [(int)$item['id']]
                );
                $this->verify($file,$size,$mtime,$digest,$jobId,$token,$completed,$total);
                $this->verify($survivorPath,(int)$other['size_bytes'],
                    (int)$other['mtime_epoch'],$digest,$jobId,$token,$completed,$total);
                $this->fs->checkedStat($survivorPath,
                    (int)$other['size_bytes'],(int)$other['mtime_epoch']);
                $this->fs->prepareQuarantine($moveRoot,$batchId,$entryId);
                $this->jobs->assertRunning($jobId,$token);
                $this->fs->renameExactly($file,$backup,$size,$mtime);
                $backedUp=true;
                $this->fs->checkedStat($backup,$size,$mtime);
                if($link) {
                    if(!@symlink($survivorPath,$source) ||
                       !is_link($source) || (string)@readlink($source)!==$survivorPath)
                        throw new \DomainException('Creating the approved symbolic link failed.');
                    $linked=true;
                }
            } else {
                $expectedState=$link?'LINKED':'QUARANTINED';
                if($item['file_error']!==$expectedState)
                    throw new \DomainException('The scan record does not match the original action.');
                $backupDir=dirname($backup);
                if(is_link($backupDir) || realpath($backupDir)===false ||
                    !$this->fs->samePath((string)realpath($backupDir),$backupDir))
                    throw new \DomainException('Recovery directory is no longer trustworthy.');
                $this->fs->requireSameDevice($backup,$directory);
                if($link) {
                    if(!is_link($source) || (string)@readlink($source)!==$survivor)
                        throw new \DomainException('The approved symbolic link changed.');
                } elseif(file_exists($source) || is_link($source)) {
                    throw new \DomainException('Original path is occupied; refusing overwrite.');
                }
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='restoring' WHERE id=?",
                    [(int)$item['id']]
                );
                $this->verify($backup,$size,$mtime,$digest,$jobId,$token,$completed,$total);
                if($link) {
                    if(!is_link($source) || (string)@readlink($source)!==$survivor ||
                       !@unlink($source))
                        throw new \DomainException('Could not remove the exact approved symbolic link.');
                    $unlinked=true;
                }
                $this->jobs->assertRunning($jobId,$token);
                $this->fs->renameExactly($backup,$source,$size,$mtime);
                $returned=true;
                $this->fs->checkedStat($source,$size,$mtime);
            }
            $newFlag=$restoring?null:($link?'LINKED':'QUARANTINED');
            $newState=$restoring?'restored':($link?'linked':'quarantined');
            $this->db->transactional(function(Connection $db) use (
                $jobId,$token,$item,$entryId,$batchId,$newFlag,$newState,$restoring,$batch
            ):void {
                $this->jobs->assertRunning($jobId,$token);
                $affected=$db->executeStatement(
                    'UPDATE file_entries SET error_code=? WHERE id=? AND '.
                    ($restoring?'error_code=?':'error_code IS NULL'),
                    $restoring?[$newFlag,$entryId,$batch['action_kind']==='bulk_link'?'LINKED':'QUARANTINED']:
                        [$newFlag,$entryId]
                );
                if($affected!==1) throw new \RuntimeException('Indexed record changed during bulk action.');
                $db->executeStatement(
                    'UPDATE file_action_items SET state=?,result_code=NULL WHERE id=?',
                    [$newState,(int)$item['id']]
                );
                $this->audit->audit((int)$batch['actor_id'],
                    $restoring?'BULK_FILE_RESTORED':'BULK_FILE_PROCESSED',
                    $batchId,'completed',[
                        'file_entry_id'=>$entryId,'kind'=>$batch['action_kind']
                    ]);
            });
        } catch(\Throwable $ex) {
            $rollbackOkay=true;
            if(!$restoring) {
                if($linked || (is_link($source) && (string)@readlink($source)===$survivor)) {
                    if(!is_link($source) || (string)@readlink($source)!==$survivor ||
                        !@unlink($source)) $rollbackOkay=false;
                }
                if($backedUp && $rollbackOkay) {
                    if(file_exists($source) || is_link($source) ||
                        !@rename($backup,$source)) $rollbackOkay=false;
                }
            } else {
                if($returned) {
                    if(file_exists($backup) || is_link($backup) ||
                        !@rename($source,$backup)) $rollbackOkay=false;
                    if($link && $rollbackOkay && !@symlink($survivor,$source))
                        $rollbackOkay=false;
                } elseif($unlinked) {
                    if(file_exists($source) || is_link($source) ||
                        !@symlink($survivor,$source)) $rollbackOkay=false;
                }
            }
            if(!$rollbackOkay)
                throw new BulkRecoveryRequired('Bulk filesystem rollback needs manual recovery.',0,$ex);
            throw $ex;
        }
    }

    private function verify(
        string $path,int $size,int $mtime,string $sha,int $jobId,string $token,
        int $completed,int $total
    ):void {
        $last=time();
        $this->fs->verifyDigest($path,$size,$mtime,$sha,function() use (
            $jobId,$token,$completed,$total,&$last
        ):void {
            $this->jobs->assertRunning($jobId,$token);
            if(time()-$last>=2) {
                $this->jobs->progress($jobId,$token,'verifying_sha256',$completed,$total);
                $last=time();
            }
        });
    }
}
