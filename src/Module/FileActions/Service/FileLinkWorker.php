<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use Doctrine\DBAL\Connection;

/** Safely replace ONE verified duplicate with a link; retain original for undo. */
final class FileLinkWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly FileActionService $actions,
        private readonly SafeFileSystem $fs
    ) {}

    public function run(array $job): void
    {
        $id=(int)$job['id'];
        $token=(string)$job['worker_token'];
        $batchId=(int)($job['file_action_batch_id']??0);
        $restoring=$job['kind']==='file-unlink';
        if(!in_array($job['kind'],['file-link','file-unlink'],true) || $batchId<1)
            throw new \LogicException('Invalid symbolic-link job.');
        $row=$this->db->fetchAssociative(
            'SELECT b.id AS batch_id,b.actor_id,b.profile_id,b.state AS batch_state,'.
            'i.id AS item_id,i.file_entry_id,i.source_path,i.target_path,'.
            'i.link_target_path,i.expected_size,i.expected_mtime,i.expected_sha256,'.
            'i.survivor_file_entry_id,i.state AS item_state,'.
            'f.filename,f.error_code AS file_error,f.run_id,d.path AS directory,p.move_root '.
            'FROM file_action_batches b JOIN file_action_items i ON i.batch_id=b.id '.
            'JOIN file_entries f ON f.id=i.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id '.
            'JOIN scan_profiles p ON p.id=b.profile_id '.
            "WHERE b.id=? AND b.action_kind='link'",[$batchId]
        );
        $source='';$backup='';$survivor='';
        $backedUp=false;$linked=false;$unlinked=false;$restored=false;
        try {
            $this->jobs->assertRunning($id,$token);
            if(!$row || $row['batch_state']!==($restoring?'restore_queued':'queued') ||
               $row['item_state']!==($restoring?'linked':'planned') ||
               (int)$row['run_id']!==(int)$job['run_id'])
                throw new \DomainException('This link operation is no longer approved.');
            $entryId=(int)$row['file_entry_id'];
            $size=(int)$row['expected_size'];
            $mtime=(int)$row['expected_mtime'];
            $digest=(string)$row['expected_sha256'];
            $survivor=(string)$row['link_target_path'];
            if(!preg_match('/^[a-f0-9]{64}$/i',$digest) || $survivor==='')
                throw new \DomainException('Verified SHA-256 or link target is missing.');
            $directory=$this->fs->checkedRoot((string)$row['directory']);
            $source=$directory.DIRECTORY_SEPARATOR.(string)$row['filename'];
            $backup=(string)$row['target_path'];
            if(!$this->fs->samePath($source,(string)$row['source_path']))
                throw new \DomainException('Source path differs from approved file.');
            $moveRoot=$this->fs->checkedRoot((string)$row['move_root']);
            $expected=$moveRoot.DIRECTORY_SEPARATOR.'.dup-quarantine'.
                DIRECTORY_SEPARATOR.'batch-'.$batchId.DIRECTORY_SEPARATOR.
                'entry-'.$entryId.DIRECTORY_SEPARATOR.(string)$row['filename'];
            if(!$this->fs->samePath($backup,$expected))
                throw new \DomainException('Private recovery location has changed.');
            if(!$restoring) {
                if($row['file_error']!==null ||
                   !$this->actions->latestRun((int)$row['profile_id'],(int)$row['run_id']))
                    throw new \DomainException('The selected duplicate is no longer current.');
                $survivorRow=$this->db->fetchAssociative(
                    'SELECT f.filename,f.size_bytes,f.mtime_epoch,f.hash_value,'.
                    'f.hash_method,d.path AS directory FROM duplicate_group_members m '.
                    'JOIN duplicate_groups g ON g.id=m.group_id '.
                    'JOIN duplicate_group_members other ON other.group_id=g.id '.
                    'JOIN file_entries f ON f.id=other.file_entry_id '.
                    'JOIN directories d ON d.id=f.directory_id '.
                    'WHERE m.file_entry_id=? AND f.id=? AND f.id<>? '.
                    "AND g.rules_version=1 AND g.run_id=? AND f.error_code IS NULL ".
                    "AND f.hash_method='sha256' AND d.is_ignored=0 LIMIT 1",
                    [$entryId,(int)$row['survivor_file_entry_id'],$entryId,(int)$row['run_id']]
                );
                if(!$survivorRow || !hash_equals(strtolower($digest),
                    strtolower((string)$survivorRow['hash_value'])))
                    throw new \DomainException('The verified surviving duplicate is missing.');
                $currentSurvivor=$this->fs->checkedPath(
                    (string)$survivorRow['directory'],(string)$survivorRow['filename']
                );
                if(!$this->fs->samePath($survivor,$currentSurvivor))
                    throw new \DomainException('The survivor path changed after approval.');
                $this->fs->requireSameDevice($source,$moveRoot);
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='executing' WHERE id=?",
                    [$batchId]
                );
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='processing' WHERE id=?",
                    [(int)$row['item_id']]
                );
                $this->jobs->progress($id,$token,'checking_files',0,4);
                $current=$this->fs->checkedPath($directory,(string)$row['filename']);
                $this->fs->checkedStat($current,$size,$mtime);
                $this->jobs->progress($id,$token,'checking_files',1,4);
                $this->fs->checkedStat($currentSurvivor,
                    (int)$survivorRow['size_bytes'],(int)$survivorRow['mtime_epoch']);
                $this->jobs->progress($id,$token,'creating_link',2,4);
                $this->fs->prepareQuarantine($moveRoot,$batchId,$entryId);
                $this->jobs->assertRunning($id,$token);
                $this->fs->renameExactly($current,$backup,$size,$mtime);
                $backedUp=true;
                if(!@symlink($survivor,$source) || !is_link($source) ||
                   (string)@readlink($source)!==$survivor)
                    throw new \DomainException('The symbolic link could not be created.');
                $linked=true;
            } else {
                if($row['file_error']!=='LINKED')
                    throw new \DomainException('The original is no longer recorded as linked.');
                if(!is_link($source) || (string)@readlink($source)!==$survivor)
                    throw new \DomainException('The link changed; refusing to remove it.');
                $backupDir=dirname($backup);
                if(is_link($backupDir) || realpath($backupDir)===false ||
                   !$this->fs->samePath((string)realpath($backupDir),$backupDir))
                    throw new \DomainException('Private backup directory changed.');
                $this->fs->requireSameDevice($backup,$directory);
                $this->db->executeStatement(
                    "UPDATE file_action_batches SET state='restore_executing' WHERE id=?",
                    [$batchId]
                );
                $this->db->executeStatement(
                    "UPDATE file_action_items SET state='restoring' WHERE id=?",
                    [(int)$row['item_id']]
                );
                $this->jobs->progress($id,$token,'restoring_link',0,3);
                $this->fs->checkedStat($backup,$size,$mtime);
                if(!is_link($source) || (string)@readlink($source)!==$survivor)
                    throw new \DomainException('Link was changed during verification.');
                $this->jobs->assertRunning($id,$token);
                if(!@unlink($source))
                    throw new \DomainException('Could not remove the exact approved link.');
                $unlinked=true;
                $this->fs->renameExactly($backup,$source,$size,$mtime);
                $restored=true;
                $this->fs->checkedStat($source,$size,$mtime);
                $this->jobs->progress($id,$token,'restoring_link',2,3);
            }
            $this->db->transactional(function(Connection $db) use (
                $id,$token,$batchId,$row,$restoring,$entryId
            ):void {
                $this->jobs->assertRunning($id,$token);
                $update=$db->executeStatement(
                    'UPDATE file_entries SET error_code=? WHERE id=? AND '.
                    ($restoring?"error_code='LINKED'":'error_code IS NULL'),
                    [$restoring?null:'LINKED',$entryId]
                );
                if($update!==1) throw new \RuntimeException('File scan record changed.');
                $db->executeStatement(
                    'UPDATE file_action_items SET state=?,result_code=? WHERE id=?',
                    [$restoring?'restored':'linked',$restoring?'LINK_REMOVED':'LINK_CREATED',
                     (int)$row['item_id']]
                );
                $db->executeStatement(
                    'UPDATE file_action_batches SET state=?,completed_at=?,error_code=NULL WHERE id=?',
                    [$restoring?'restored':'completed',gmdate('Y-m-d H:i:s'),$batchId]
                );
                $finished=$db->executeStatement(
                    "UPDATE jobs SET state='completed',phase='completed',active_profile_id=NULL,".
                    "progress_current=?,progress_total=?,finished_at=?,heartbeat_at=? ".
                    "WHERE id=? AND worker_token=? AND state='running'",
                    [$restoring?3:4,$restoring?3:4,gmdate('Y-m-d H:i:s'),gmdate('Y-m-d H:i:s'),$id,$token]
                );
                if($finished!==1) throw new LostLease('Link job lease was lost.','DUP-LINK-LEASE-101');
                $this->actions->audit((int)$row['actor_id'],
                    $restoring?'FILE_LINK_RESTORED':'FILE_LINK_CREATED',
                    $batchId,'completed',['file_entry_id'=>$entryId]);
                $this->jobs->event($id,'info',$restoring?'LINK_RESTORE_COMPLETED':'LINK_CREATED',[
                    'batch_id'=>$batchId
                ]);
            });
            $backedUp=false;$linked=false;$unlinked=false;$restored=false;
        } catch(\Throwable $ex) {
            // On failure, first undo any link we created and then return its
            // original file; if that cannot be done, preserve the backup and
            // mark for manual recovery rather than deleting unknown content.
            $rollbackOk=true;
            if(!$restoring) {
                if($linked || (is_link($source) && @readlink($source)===$survivor)) {
                    if(!is_link($source) || @readlink($source)!==$survivor ||
                       !@unlink($source)) $rollbackOk=false;
                }
                if($backedUp && $rollbackOk) {
                    if(file_exists($source) || is_link($source) ||
                       !@rename($backup,$source)) $rollbackOk=false;
                }
            } elseif($restored) {
                if(file_exists($backup) || is_link($backup) ||
                   !@rename($source,$backup) ||
                   !@symlink($survivor,$source)) $rollbackOk=false;
            } elseif($unlinked) {
                if(file_exists($source) || is_link($source) ||
                   !@symlink($survivor,$source)) $rollbackOk=false;
            }
            try {
                $state=$rollbackOk?'failed':'needs_review';
                $this->db->executeStatement(
                    'UPDATE file_action_batches SET state=?,error_code=? WHERE id=?',
                    [$state,$rollbackOk?'LINK_ACTION_FAILED':'MANUAL_RECOVERY_REQUIRED',$batchId]
                );
                if($rollbackOk) $this->db->executeStatement(
                    'UPDATE file_action_items SET state=?,result_code=? WHERE id=?',
                    [$restoring?'linked':'failed','LINK_ACTION_FAILED',
                     (int)($row['item_id']??0)]
                );
                $this->actions->audit($row?(int)$row['actor_id']:null,
                    $rollbackOk?'LINK_FAILED':'LINK_NEEDS_REVIEW',
                    $batchId,$state,['reason'=>mb_substr($ex->getMessage(),0,180)]);
                $this->jobs->stop($id,$token,'failed',
                    $rollbackOk?'FILE_LINK_FAILED':'MANUAL_RECOVERY_REQUIRED');
            } catch(\Throwable) {
                // Stalled recovery will flag the batch when the worker dies.
            }
            throw $ex;
        }
    }
}
