<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Explicit, irreversible file deletion: user-chosen duplicate entries only.
 * No survivor requirement, no recycle-bin or quarantine destination.
 */
final class FileDeletionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $audit
    ) {}

    /** @param list<int> $ids */
    public function plan(array $ids,int $actorId):int
    {
        $ids=array_values(array_unique(array_map('intval',$ids)));
        if(!$ids || min($ids)<1)
            throw new \DomainException('Select valid duplicate files to delete.');
        $profileId=0;$runId=0;$files=[];
        foreach($ids as $id) {
            $file=$this->db->fetchAssociative(
                'SELECT f.id,f.run_id,f.filename,f.size_bytes,f.mtime_epoch,'.
                'f.hash_method,f.hash_value,f.error_code,d.path AS directory,'.
                'd.is_ignored,p.id AS profile_id '.
                'FROM file_entries f '.
                'JOIN directories d ON d.id=f.directory_id '.
                'JOIN duplicate_group_members gm ON gm.file_entry_id=f.id '.
                'JOIN duplicate_groups g ON g.id=gm.group_id AND g.run_id=f.run_id '.
                'JOIN scan_runs r ON r.id=f.run_id JOIN scan_profiles p ON p.id=r.profile_id '.
                "WHERE f.id=? AND r.state='completed' ".
                "AND r.id=(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') ".
                'LIMIT 1',[$id]
            );
            if(!$file || $file['error_code']!==null || (int)$file['is_ignored']!==0)
                throw new \DomainException('This file is not available in the latest duplicate results.');
            if($profileId!==0 && $profileId!==(int)$file['profile_id'])
                throw new \DomainException('Select files from one profile per delete operation.');
            if($runId!==0 && $runId!==(int)$file['run_id'])
                throw new \DomainException('Selected files belong to different scans.');
            $profileId=(int)$file['profile_id'];$runId=(int)$file['run_id'];
            $source=$this->fs->checkedPath((string)$file['directory'],(string)$file['filename']);
            $this->fs->checkedStat($source,(int)$file['size_bytes'],(int)$file['mtime_epoch']);
            $files[]=[
                'file_entry_id'=>$id,'source_path'=>$source,
                'expected_size'=>(int)$file['size_bytes'],
                'expected_mtime'=>(int)$file['mtime_epoch'],
                'expected_sha256'=>$file['hash_method']==='sha256' &&
                    preg_match('/^[a-f0-9]{64}$/i',(string)$file['hash_value'])
                    ?strtolower((string)$file['hash_value']):null
            ];
        }
        return $this->db->transactional(function(Connection $db) use ($files,$actorId,$profileId,$runId):int {
            if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=?',[$profileId]))
                throw new \DomainException('This profile already has an active job.');
            if(!$this->audit->latestRun($profileId,$runId))
                throw new \DomainException('The scan changed; select the files again.');
            $now=gmdate('Y-m-d H:i:s');
            $db->insert('file_action_batches',[
                'actor_id'=>$actorId,'profile_id'=>$profileId,'action_kind'=>'delete',
                'state'=>'planned','requested_at'=>$now,
                'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)
            ]);
            $batch=(int)$db->lastInsertId();
            foreach($files as $item)
                $db->insert('file_action_items',['batch_id'=>$batch,
                    'file_entry_id'=>$item['file_entry_id'],
                    'source_path'=>$item['source_path'],'target_path'=>null,
                    'expected_size'=>$item['expected_size'],
                    'expected_mtime'=>$item['expected_mtime'],
                    'expected_sha256'=>$item['expected_sha256'],'state'=>'planned']);
            $this->audit->audit($actorId,'DELETE_PREVIEW',$batch,'planned',[
                'profile_id'=>$profileId,'file_count'=>count($files),'irreversible'=>true
            ]);
            return $batch;
        });
    }

    public function details(int $id):array|false
    {
        $batch=$this->db->fetchAssociative(
            'SELECT b.*,p.name AS profile_name FROM file_action_batches b '.
            "JOIN scan_profiles p ON p.id=b.profile_id WHERE b.id=? AND b.action_kind='delete'",[$id]
        );
        if(!$batch) return false;
        $batch['items']=$this->db->fetchAllAssociative(
            'SELECT * FROM file_action_items WHERE batch_id=? ORDER BY id',[$id]
        );
        return $batch;
    }

    public function confirm(int $id,int $actorId):int
    {
        return $this->db->transactional(function(Connection $db) use ($id,$actorId):int {
            $batch=$this->details($id);
            if(!$batch || (int)$batch['actor_id']!==$actorId ||
                $batch['state']!=='planned' || !$batch['items'])
                throw new \DomainException('This delete preview is no longer available.');
            if($batch['expires_at']<gmdate('Y-m-d H:i:s'))
                throw new \DomainException('Delete preview expired. Select the files again.');
            $runId=0;
            foreach($batch['items'] as $item) {
                $row=$db->fetchAssociative(
                    'SELECT run_id,error_code FROM file_entries WHERE id=?',
                    [(int)$item['file_entry_id']]
                );
                if(!$row || $row['error_code']!==null || $item['state']!=='planned')
                    throw new \DomainException('The selected file changed after preview.');
                if($runId && $runId!==(int)$row['run_id'])
                    throw new \DomainException('Files span multiple scan runs.');
                $runId=(int)$row['run_id'];
            }
            if(!$this->audit->latestRun((int)$batch['profile_id'],$runId))
                throw new \DomainException('A newer scan exists. Select the files again.');
            try {
                $db->insert('jobs',[
                    'kind'=>'file-delete','profile_id'=>$batch['profile_id'],
                    'active_profile_id'=>$batch['profile_id'],'run_id'=>$runId,
                    'file_action_batch_id'=>$id,'state'=>'queued','phase'=>'waiting',
                    'progress_current'=>0,'progress_total'=>count($batch['items']),
                    'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
                ]);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another job is active for this profile.',0,$ex);
            }
            $jobId=(int)$db->lastInsertId();
            $db->executeStatement(
                "UPDATE file_action_batches SET state='queued',confirmed_at=? ".
                "WHERE id=? AND state='planned'",[gmdate('Y-m-d H:i:s'),$id]
            );
            $this->audit->audit($actorId,'DELETE_CONFIRMED',$id,'queued',[
                'job_id'=>$jobId,'file_count'=>count($batch['items'])
            ]);
            return $jobId;
        });
    }
}
