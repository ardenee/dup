<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Multi-file duplicate actions. Planning and HTTP confirmation do no writes
 * to the filesystem. A non-selected, fully verified survivor is mandatory for
 * every affected group, including a quarantine-only batch.
 */
final class BulkDuplicateService
{
    public const MAX_FILES=50;
    public const ACTIONS=['bulk_quarantine','bulk_link'];

    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $audit
    ) {}

    /** @param list<int> $ids */
    public function plan(array $ids,int $actorId,string $kind):int
    {
        if(!in_array($kind,self::ACTIONS,true))
            throw new \DomainException('Choose bulk quarantine or bulk link.');
        $ids=array_values(array_unique(array_map('intval',$ids)));
        if(!$ids || count($ids)>self::MAX_FILES || min($ids)<1)
            throw new \DomainException('Select between 1 and 50 indexed duplicates.');
        $chosen=array_fill_keys($ids,true);
        $items=[];$groupSurvivors=[];$profileId=0;$runId=0;
        foreach($ids as $id) {
            $file=$this->db->fetchAssociative(
                'SELECT f.id,f.run_id,f.filename,f.size_bytes,f.mtime_epoch,'.
                'f.hash_value,f.hash_method,f.error_code,d.path AS directory,'.
                'd.is_ignored,g.id AS group_id,g.rules_version,g.file_count,'.
                'p.id AS profile_id,p.move_root '.
                'FROM file_entries f '.
                'JOIN directories d ON d.id=f.directory_id '.
                'JOIN duplicate_group_members gm ON gm.file_entry_id=f.id '.
                'JOIN duplicate_groups g ON g.id=gm.group_id AND g.run_id=f.run_id '.
                'JOIN scan_runs r ON r.id=f.run_id '.
                'JOIN scan_profiles p ON p.id=r.profile_id '.
                "WHERE f.id=? AND r.state='completed' ".
                "AND r.id=(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') ".
                'AND f.error_code IS NULL AND d.is_ignored=0 AND f.hash_method=? '.
                'AND g.rules_version=1 AND g.file_count>1 LIMIT 1',
                [$id,'sha256']
            );
            if(!$file || !preg_match('/^[a-f0-9]{64}$/i',(string)$file['hash_value']))
                throw new \DomainException('Only files in current verified SHA-256 duplicate groups qualify.');
            if($profileId!==0 && $profileId!==(int)$file['profile_id'])
                throw new \DomainException('Select duplicates from one profile at a time.');
            $profileId=(int)$file['profile_id'];
            $runId=(int)$file['run_id'];
            $source=$this->fs->checkedPath((string)$file['directory'],(string)$file['filename']);
            $this->fs->checkedStat($source,(int)$file['size_bytes'],(int)$file['mtime_epoch']);
            $moveRoot=$this->fs->checkedRoot((string)$file['move_root']);
            $this->fs->requireSameDevice($source,$moveRoot);
            $groupId=(int)$file['group_id'];
            if(!isset($groupSurvivors[$groupId])) {
                $exclude=implode(',',array_fill(0,count($ids),'?'));
                $survivor=$this->db->fetchAssociative(
                    'SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,f.hash_value,'.
                    'd.path AS directory FROM duplicate_group_members gm '.
                    'JOIN file_entries f ON f.id=gm.file_entry_id '.
                    'JOIN directories d ON d.id=f.directory_id '.
                    'WHERE gm.group_id=? AND f.id NOT IN ('.$exclude.') '.
                    "AND f.error_code IS NULL AND f.hash_method='sha256' AND d.is_ignored=0 ".
                    'AND f.hash_value=? ORDER BY f.id LIMIT 1',
                    [$groupId,...$ids,(string)$file['hash_value']]
                );
                if(!$survivor || isset($chosen[(int)$survivor['id']]))
                    throw new \DomainException(
                        'Keep at least one unselected verified survivor in each duplicate group. '.
                        'Unselect a file and preview again.'
                    );
                $survivorPath=$this->fs->checkedPath(
                    (string)$survivor['directory'],(string)$survivor['filename']
                );
                $this->fs->checkedStat($survivorPath,
                    (int)$survivor['size_bytes'],(int)$survivor['mtime_epoch']);
                $groupSurvivors[$groupId]=[
                    'id'=>(int)$survivor['id'],'path'=>$survivorPath,
                    'hash'=>(string)$survivor['hash_value']
                ];
            }
            $survivor=$groupSurvivors[$groupId];
            if(!hash_equals(strtolower((string)$file['hash_value']),strtolower($survivor['hash'])))
                throw new \DomainException('The selected group has inconsistent hash evidence.');
            $items[]=[
                'file_entry_id'=>$id,'filename'=>(string)$file['filename'],
                'source_path'=>$source,'size'=>(int)$file['size_bytes'],
                'mtime'=>(int)$file['mtime_epoch'],
                'digest'=>strtolower((string)$file['hash_value']),
                'survivor_id'=>$survivor['id'],'survivor_path'=>$survivor['path'],
                'move_root'=>$moveRoot
            ];
        }
        return $this->db->transactional(function(Connection $db) use (
            $items,$kind,$actorId,$profileId,$runId
        ):int {
            if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=? LIMIT 1',[$profileId]))
                throw new \DomainException('A profile job is already active.');
            if(!$this->audit->latestRun($profileId,$runId))
                throw new \DomainException('Current scan changed during planning.');
            $now=gmdate('Y-m-d H:i:s');
            $db->insert('file_action_batches',[
                'actor_id'=>$actorId,'profile_id'=>$profileId,
                'action_kind'=>$kind,'state'=>'planned','requested_at'=>$now,
                'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)
            ]);
            $batch=(int)$db->lastInsertId();
            foreach($items as $item) {
                $backup=$item['move_root'].DIRECTORY_SEPARATOR.'.dup-quarantine'.
                    DIRECTORY_SEPARATOR.'batch-'.$batch.
                    DIRECTORY_SEPARATOR.'entry-'.$item['file_entry_id'].
                    DIRECTORY_SEPARATOR.$item['filename'];
                $db->insert('file_action_items',[
                    'batch_id'=>$batch,'file_entry_id'=>$item['file_entry_id'],
                    'source_path'=>$item['source_path'],'target_path'=>$backup,
                    'link_target_path'=>$kind==='bulk_link'?$item['survivor_path']:null,
                    'survivor_file_entry_id'=>$item['survivor_id'],
                    'expected_size'=>$item['size'],'expected_mtime'=>$item['mtime'],
                    'expected_sha256'=>$item['digest'],'state'=>'planned'
                ]);
            }
            $this->audit->audit($actorId,'BULK_DUPLICATE_PREVIEW',$batch,'planned',[
                'kind'=>$kind,'profile_id'=>$profileId,'count'=>count($items)
            ]);
            return $batch;
        });
    }

    public function details(int $batchId):array|false
    {
        $row=$this->db->fetchAssociative(
            'SELECT b.*,p.name AS profile_name,p.move_root '.
            'FROM file_action_batches b JOIN scan_profiles p ON p.id=b.profile_id '.
            "WHERE b.id=? AND b.action_kind IN ('bulk_link','bulk_quarantine')",
            [$batchId]
        );
        if(!$row) return false;
        $row['items']=$this->db->fetchAllAssociative(
            'SELECT i.*,survivor.filename AS survivor_filename,'.
            'location.path AS survivor_directory FROM file_action_items i '.
            'LEFT JOIN file_entries survivor ON survivor.id=i.survivor_file_entry_id '.
            'LEFT JOIN directories location ON location.id=survivor.directory_id '.
            'WHERE i.batch_id=? ORDER BY i.id',
            [$batchId]
        );
        return $row;
    }

    public function confirm(int $batchId,int $actorId):int
    {
        return $this->db->transactional(function(Connection $db) use ($batchId,$actorId):int {
            $row=$this->details($batchId);
            if(!$row || (int)$row['actor_id']!==$actorId || $row['state']!=='planned')
                throw new \DomainException('The bulk preview is no longer available.');
            if((string)$row['expires_at']<gmdate('Y-m-d H:i:s'))
                throw new \DomainException('Preview expired. Select the files again.');
            $runId=0;
            foreach($row['items'] as $item) {
                $file=$db->fetchAssociative(
                    'SELECT f.run_id,f.error_code FROM file_entries f WHERE f.id=?',
                    [(int)$item['file_entry_id']]
                );
                if(!$file || $file['error_code']!==null || $item['state']!=='planned')
                    throw new \DomainException('Selected files changed after preview.');
                if($runId!==0 && $runId!==(int)$file['run_id'])
                    throw new \DomainException('Selected files span multiple scans.');
                $runId=(int)$file['run_id'];
            }
            if(!$runId || !$this->audit->latestRun((int)$row['profile_id'],$runId))
                throw new \DomainException('Current results changed after preview.');
            try {
                $job=$this->enqueue($db,$row['action_kind']==='bulk_link'?'bulk-link':'bulk-quarantine',
                    (int)$row['profile_id'],$runId,$batchId);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('A profile job is already active.',0,$ex);
            }
            $db->executeStatement(
                "UPDATE file_action_batches SET state='queued',confirmed_at=? WHERE id=? AND state='planned'",
                [gmdate('Y-m-d H:i:s'),$batchId]
            );
            $this->audit->audit($actorId,'BULK_DUPLICATE_CONFIRMED',$batchId,'queued',['job_id'=>$job]);
            return $job;
        });
    }

    public function restore(int $batchId,int $actorId):int
    {
        return $this->db->transactional(function(Connection $db) use ($batchId,$actorId):int {
            $row=$this->details($batchId);
            if(!$row || !in_array($row['state'],['completed','partial'],true))
                throw new \DomainException('No completed duplicate actions are available to restore.');
            $activeState=$row['action_kind']==='bulk_link'?'linked':'quarantined';
            $runId=0;$count=0;
            foreach($row['items'] as $item) {
                if($item['state']!==$activeState) continue;
                $file=$db->fetchAssociative(
                    'SELECT run_id,error_code FROM file_entries WHERE id=?',
                    [(int)$item['file_entry_id']]
                );
                if(!$file || $file['error_code']!==strtoupper($activeState))
                    throw new \DomainException('An indexed file no longer matches its recorded action state.');
                $runId=(int)$file['run_id'];
                $count++;
            }
            if(!$count || !$runId)
                throw new \DomainException('No files remain in this batch to restore.');
            try {
                $job=$this->enqueue($db,$row['action_kind']==='bulk_link'?'bulk-unlink':'bulk-restore',
                    (int)$row['profile_id'],$runId,$batchId);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another profile job is active.',0,$ex);
            }
            $db->executeStatement(
                "UPDATE file_action_batches SET state='restore_queued' WHERE id=?",
                [$batchId]
            );
            $this->audit->audit($actorId,'BULK_DUPLICATE_RESTORE_QUEUED',$batchId,'queued',[
                'job_id'=>$job,'count'=>$count
            ]);
            return $job;
        });
    }

    private function enqueue(Connection $db,string $kind,int $profile,int $run,int $batch):int
    {
        $requiredState=match($kind) {
            'bulk-unlink'=>'linked',
            'bulk-restore'=>'quarantined',
            default=>'planned'
        };
        $total=(int)$db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state=?',
            [$batch,$requiredState]
        );
        if($total<1) throw new \DomainException('No approved bulk items remain in this batch.');
        $db->insert('jobs',[
            'kind'=>$kind,'profile_id'=>$profile,'active_profile_id'=>$profile,
            'run_id'=>$run,'file_action_batch_id'=>$batch,'state'=>'queued',
            'phase'=>'waiting','progress_current'=>0,'progress_total'=>$total,
            'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
        ]);
        return (int)$db->lastInsertId();
    }
}
