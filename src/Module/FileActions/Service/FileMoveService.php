<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Preview, authorize and queue reversible moves. No filesystem writes in
 * HTTP requests; work is performed only by a leased CLI worker.
 */
final class FileMoveService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly MovePathPlanner $paths,
        private readonly FileActionService $actions,
        private readonly ActionSettings $settings
    ) {}

    /** @param list<int> $ids */
    public function plan(array $ids,int $actorId): int
    {
        $ids=array_values(array_unique($ids));
        if(count($ids)<1 || count($ids)>50)
            throw new \DomainException('Select between 1 and 50 indexed files.');
        $items=[];$profileId=0;$runId=0;$destinations=[];$fileExistsPolicy=null;
        foreach($ids as $id) {
            if($id<1) throw new \DomainException('Invalid file selection.');
            $file=$this->db->fetchAssociative(
                'SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,f.error_code,'.
                'f.run_id,d.path AS directory,d.is_ignored,p.id AS profile_id,'.
                'p.source_root,p.move_root FROM file_entries f '.
                'JOIN directories d ON d.id=f.directory_id '.
                'JOIN scan_runs r ON r.id=f.run_id '.
                'JOIN scan_profiles p ON p.id=r.profile_id '.
                "WHERE f.id=? AND r.state='completed' AND ".
                "r.id=(SELECT MAX(x.id) FROM scan_runs x WHERE x.profile_id=p.id ".
                "AND x.state='completed')",[$id]
            );
            if(!$file || $file['error_code']!==null || (int)$file['is_ignored']!==0)
                throw new \DomainException('A selected file is not available in the latest completed scan.');
            if($profileId!==0 && $profileId!==(int)$file['profile_id'])
                throw new \DomainException('Select files belonging to one scan profile.');
            $profileId=(int)$file['profile_id'];
            $runId=(int)$file['run_id'];
            $fileExistsPolicy=$this->settings->policy($profileId);
            $source=$this->fs->checkedPath((string)$file['directory'],(string)$file['filename']);
            $this->fs->checkedStat($source,(int)$file['size_bytes'],(int)$file['mtime_epoch']);
            $moveRoot=$this->fs->checkedRoot((string)$file['move_root']);
            $this->fs->requireSameDevice($source,$moveRoot);
            $target=$this->paths->destination(
                (string)$file['source_root'],$moveRoot,
                (string)$file['directory'],(string)$file['filename']
            );
            $overwrite=null;
            if($fileExistsPolicy===ActionSettings::OVERWRITE &&
               (file_exists($target) || is_link($target))) {
                if(is_link($target) || !is_file($target))
                    throw new \DomainException('Cannot overwrite a symbolic link or non-file destination.');
                $stat=$this->fs->checkedStat($target,(int)filesize($target),(int)filemtime($target));
                $overwrite=['size'=>(int)$stat['size'],'mtime'=>(int)$stat['mtime']];
            }
            if($fileExistsPolicy===ActionSettings::NUMBER) {
                $numbered=\App\Module\Renaming\Service\RenameRules::availableName(
                    dirname($target),basename($target),$destinations
                );
                $target=dirname($target).DIRECTORY_SEPARATOR.$numbered;
            }
            $key=PHP_OS_FAMILY==='Windows'?mb_strtolower($target):$target;
            if(isset($destinations[$key]) ||
                (!$overwrite && (file_exists($target) || is_link($target))))
                throw new \DomainException('A target file already exists or appears more than once in the batch.');
            $destinations[$key]=true;
            $items[]=[
                'file_entry_id'=>$id,'source_path'=>$source,'target_path'=>$target,
                'expected_size'=>(int)$file['size_bytes'],
                'expected_mtime'=>(int)$file['mtime_epoch'],
                'overwrite'=>$overwrite
            ];
        }
        return $this->db->transactional(function(Connection $db) use (
            $items,$actorId,$profileId,$runId
        ):int {
            if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=? LIMIT 1',[$profileId]))
                throw new \DomainException('A job is already active for this profile.');
            if(!$this->actions->latestRun($profileId,$runId))
                throw new \DomainException('A new scan was completed. Select the files again.');
            $db->insert('file_action_batches',[
                'actor_id'=>$actorId,'profile_id'=>$profileId,
                'action_kind'=>'move','state'=>'planned',
                'requested_at'=>gmdate('Y-m-d H:i:s'),
                'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)
            ]);
            $batchId=(int)$db->lastInsertId();
            foreach($items as $item) {
                $backup=$item['overwrite']===null?null:
                    dirname($item['target_path']).DIRECTORY_SEPARATOR.
                    '.dup-replaced-'.$batchId.'-'.$item['file_entry_id'];
                $db->insert('file_action_items',[
                    'batch_id'=>$batchId,'file_entry_id'=>$item['file_entry_id'],
                    'source_path'=>$item['source_path'],
                    'target_path'=>$item['target_path'],
                    'state'=>'planned','expected_size'=>$item['expected_size'],
                    'expected_mtime'=>$item['expected_mtime'],
                    ...($item['overwrite']!==null?[
                        'overwrite_target_size'=>$item['overwrite']['size'],
                        'overwrite_target_mtime'=>$item['overwrite']['mtime'],
                        'overwrite_backup_path'=>$backup
                    ]:[])
                ]);
            }
            $this->actions->audit($actorId,'MOVE_PREVIEW',$batchId,'planned',[
                'profile_id'=>$profileId,'file_count'=>count($items)
            ]);
            return $batchId;
        });
    }

    public function details(int $id): array|false
    {
        $batch=$this->db->fetchAssociative(
            'SELECT b.*,p.name AS profile_name,p.move_root,p.source_root '.
            'FROM file_action_batches b JOIN scan_profiles p ON p.id=b.profile_id '.
            "WHERE b.id=? AND b.action_kind='move'",[$id]
        );
        if(!$batch) return false;
        $batch['items']=$this->db->fetchAllAssociative(
            'SELECT i.* FROM file_action_items i WHERE i.batch_id=? ORDER BY i.id',[$id]
        );
        return $batch;
    }

    public function confirm(int $id,int $actorId): int
    {
        return $this->db->transactional(function(Connection $db) use ($id,$actorId):int {
            $batch=$this->details($id);
            if(!$batch || (int)$batch['actor_id']!==$actorId ||
               $batch['state']!=='planned')
                throw new \DomainException('This move preview is no longer available.');
            if((string)$batch['expires_at']<gmdate('Y-m-d H:i:s'))
                throw new \DomainException('The move preview expired. Please select the files again.');
            $runId=(int)$db->fetchOne('SELECT run_id FROM file_entries WHERE id=?',
                [(int)$batch['items'][0]['file_entry_id']]);
            if(!$this->actions->latestRun((int)$batch['profile_id'],$runId))
                throw new \DomainException('Scan results changed. Preview the move again.');
            try {
                $job=$this->enqueue($db,'file-move',(int)$batch['profile_id'],$runId,$id);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('This profile has an active job.',0,$ex);
            }
            $db->executeStatement(
                "UPDATE file_action_batches SET state='queued',confirmed_at=? ".
                "WHERE id=? AND state='planned'",[gmdate('Y-m-d H:i:s'),$id]
            );
            $this->actions->audit($actorId,'MOVE_CONFIRMED',$id,'queued',['job_id'=>$job]);
            return $job;
        });
    }

    public function restore(int $id,int $actorId): int
    {
        return $this->db->transactional(function(Connection $db) use ($id,$actorId):int {
            $batch=$this->details($id);
            if(!$batch || !in_array($batch['state'],['completed','partial'],true))
                throw new \DomainException('No completed moved files are available to restore.');
            $hasMoved=false;$runId=0;
            foreach($batch['items'] as $item) {
                if($item['state']==='moved') {
                    $hasMoved=true;
                    $runId=(int)$db->fetchOne('SELECT run_id FROM file_entries WHERE id=?',
                        [(int)$item['file_entry_id']]);
                    break;
                }
            }
            if(!$hasMoved || !$runId)
                throw new \DomainException('No moved files remain in this batch.');
            try {
                $job=$this->enqueue($db,'file-move-restore',(int)$batch['profile_id'],$runId,$id);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another job is active for this profile.',0,$ex);
            }
            $db->executeStatement("UPDATE file_action_batches SET state='restore_queued' WHERE id=?",[$id]);
            $this->actions->audit($actorId,'MOVE_RESTORE_REQUESTED',$id,'queued',['job_id'=>$job]);
            return $job;
        });
    }

    private function enqueue(Connection $db,string $kind,int $profile,int $runId,int $batch): int
    {
        $total=(int)$db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state=?',
            [$batch,$kind==='file-move-restore'?'moved':'planned']
        );
        if($total<1) throw new \DomainException('No approved move items remain in this batch.');
        $db->insert('jobs',[
            'kind'=>$kind,'profile_id'=>$profile,'active_profile_id'=>$profile,
            'run_id'=>$runId,'file_action_batch_id'=>$batch,
            'state'=>'queued','phase'=>'waiting','progress_current'=>'0',
            'progress_total'=>$total,'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
        ]);
        return (int)$db->lastInsertId();
    }
}
