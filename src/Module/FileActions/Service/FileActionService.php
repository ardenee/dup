<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

final class FileActionService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs
    ) {}

    /** Create a read-only, expiring, one-file reversible quarantine preview. */
    public function plan(int $entryId,int $actorId): int
    {
        $file=$this->db->fetchAssociative(
            "SELECT f.id,f.run_id,f.filename,f.size_bytes,f.mtime_epoch,f.hash_value," .
            "f.error_code,d.path AS directory,g.id AS group_id,g.file_count," .
            "p.id AS profile_id,p.move_root,p.name AS profile_name " .
            "FROM file_entries f JOIN directories d ON d.id=f.directory_id " .
            "JOIN duplicate_group_members m ON m.file_entry_id=f.id " .
            "JOIN duplicate_groups g ON g.id=m.group_id AND g.run_id=f.run_id " .
            "JOIN scan_runs r ON r.id=f.run_id " .
            "JOIN scan_profiles p ON p.id=r.profile_id " .
            "WHERE f.id=? AND f.error_code IS NULL AND d.is_ignored=0 " .
            "AND g.rules_version=1 AND g.file_count>1 AND f.hash_method='sha256' " .
            "AND f.hash_value IS NOT NULL AND r.state='completed' " .
            "AND r.id=(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') " .
            "LIMIT 1",[$entryId]
        );
        if(!$file || !preg_match('/^[a-f0-9]{64}$/i',(string)$file['hash_value']))
            throw new \DomainException('Only currently verified duplicates can be quarantined.');
        $source=$this->fs->checkedPath((string)$file['directory'],(string)$file['filename']);
        $this->fs->checkedStat($source,(int)$file['size_bytes'],(int)$file['mtime_epoch']);
        $targetRoot=$this->fs->checkedRoot((string)$file['move_root']);
        $this->fs->requireSameDevice($source,$targetRoot);
        $survivor=$this->db->fetchOne(
            "SELECT other.id FROM duplicate_group_members m JOIN file_entries other " .
            "ON other.id=m.file_entry_id JOIN directories d ON d.id=other.directory_id " .
            "WHERE m.group_id=? AND other.id<>? AND other.hash_value=? " .
            "AND other.error_code IS NULL AND d.is_ignored=0 ORDER BY other.id LIMIT 1",
            [(int)$file['group_id'],$entryId,$file['hash_value']]
        );
        if(!$survivor) throw new \DomainException('No remaining verified duplicate was found.');
        $profileId=(int)$file['profile_id'];
        return $this->db->transactional(function(Connection $db) use (
            $file,$entryId,$actorId,$source,$targetRoot,$survivor,$profileId
        ): int {
            if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=? LIMIT 1',[$profileId]))
                throw new \DomainException('Wait for the current profile job to complete.');
            $now=gmdate('Y-m-d H:i:s');
            $db->insert('file_action_batches',[
                'actor_id'=>$actorId,'profile_id'=>$profileId,
                'action_kind'=>'quarantine','state'=>'planned',
                'requested_at'=>$now,'expires_at'=>gmdate('Y-m-d H:i:s',time()+900)
            ]);
            $batchId=(int)$db->lastInsertId();
            $target=$targetRoot.DIRECTORY_SEPARATOR.'.dup-quarantine'.DIRECTORY_SEPARATOR.
                'batch-'.$batchId.DIRECTORY_SEPARATOR.'entry-'.$entryId.DIRECTORY_SEPARATOR.
                (string)$file['filename'];
            $db->insert('file_action_items',[
                'batch_id'=>$batchId,'file_entry_id'=>$entryId,
                'source_path'=>$source,'target_path'=>$target,'state'=>'planned',
                'expected_size'=>(int)$file['size_bytes'],
                'expected_mtime'=>(int)$file['mtime_epoch'],
                'expected_sha256'=>strtolower((string)$file['hash_value']),
                'survivor_file_entry_id'=>(int)$survivor
            ]);
            $this->audit($actorId,'QUARANTINE_PREVIEW',$batchId,'planned',[
                'file_entry_id'=>$entryId,'profile_id'=>$profileId
            ]);
            return $batchId;
        });
    }

    public function confirm(int $batchId,int $actorId): int
    {
        return $this->db->transactional(function(Connection $db) use ($batchId,$actorId): int {
            $row=$db->fetchAssociative(
                'SELECT b.*,i.file_entry_id,f.run_id,f.error_code ' .
                'FROM file_action_batches b JOIN file_action_items i ON i.batch_id=b.id ' .
                'JOIN file_entries f ON f.id=i.file_entry_id ' .
                'WHERE b.id=? AND b.actor_id=?',[$batchId,$actorId]
            );
            if(!$row || $row['state']!=='planned' || $row['action_kind']!=='quarantine')
                throw new \DomainException('This action preview is no longer available.');
            if((string)$row['expires_at']<gmdate('Y-m-d H:i:s'))
                throw new \DomainException('The 15-minute preview expired. Preview again.');
            if($row['error_code']!==null)
                throw new \DomainException('The file was already moved or its scan record changed.');
            if(!$this->latestRun((int)$row['profile_id'],(int)$row['run_id']))
                throw new \DomainException('A newer scan exists. Preview the current results again.');
            try {
                $jobId=$this->enqueue($db,'file-action',(int)$row['profile_id'],(int)$row['run_id'],$batchId);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another job is active for this profile.',0,$ex);
            }
            $db->executeStatement(
                "UPDATE file_action_batches SET state='queued',confirmed_at=? " .
                "WHERE id=? AND state='planned'",[gmdate('Y-m-d H:i:s'),$batchId]
            );
            $this->audit($actorId,'QUARANTINE_CONFIRMED',$batchId,'queued',['job_id'=>$jobId]);
            return $jobId;
        });
    }

    public function restore(int $batchId,int $actorId): int
    {
        return $this->db->transactional(function(Connection $db) use ($batchId,$actorId):int {
            $batch=$db->fetchAssociative(
                'SELECT b.*,i.file_entry_id,f.run_id,f.error_code AS source_error FROM file_action_batches b ' .
                'JOIN file_action_items i ON i.batch_id=b.id JOIN file_entries f ON f.id=i.file_entry_id ' .
                'WHERE b.id=?',[$batchId]
            );
            if(!$batch || $batch['action_kind']!=='quarantine' ||
                $batch['state']!=='completed' || $batch['error_code']!==null)
                throw new \DomainException('This batch is not a completed quarantine.');
            if($batch['source_error']!=='QUARANTINED')
                throw new \DomainException('The file is no longer recorded as quarantined.');
            try {
                $jobId=$this->enqueue($db,'file-restore',(int)$batch['profile_id'],(int)$batch['run_id'],$batchId);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another job is active for this profile.',0,$ex);
            }
            $db->executeStatement(
                "UPDATE file_action_batches SET state='restore_queued' WHERE id=? AND state='completed'",
                [$batchId]
            );
            $this->audit($actorId,'RESTORE_REQUESTED',$batchId,'queued',['job_id'=>$jobId]);
            return $jobId;
        });
    }

    private function enqueue(Connection $db,string $kind,int $profileId,int $runId,int $batchId): int
    {
        $db->insert('jobs',[
            'kind'=>$kind,'profile_id'=>$profileId,'active_profile_id'=>$profileId,
            'run_id'=>$runId,'file_action_batch_id'=>$batchId,
            'state'=>'queued','phase'=>'waiting','progress_current'=>'0',
            'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
        ]);
        return (int)$db->lastInsertId();
    }

    public function latestRun(int $profileId,int $runId): bool
    {
        return (int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs " .
            "WHERE profile_id=? AND state='completed'",[$profileId]
        )===$runId;
    }

    /** @return array<string,mixed>|false */
    public function find(int $batchId): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT b.*,i.file_entry_id,i.source_path,i.target_path,i.state AS item_state,' .
            'i.expected_size,i.expected_mtime,i.expected_sha256,i.result_code,p.name AS profile_name ' .
            'FROM file_action_batches b JOIN file_action_items i ON i.batch_id=b.id ' .
            'JOIN scan_profiles p ON p.id=b.profile_id WHERE b.id=?',[$batchId]
        );
    }

    public function history(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT b.id,b.action_kind,b.state,b.requested_at,b.completed_at,b.error_code,' .
            'MIN(i.source_path) AS source_path,MIN(i.target_path) AS target_path,' .
            'COUNT(i.id) AS item_count,p.name AS profile_name ' .
            'FROM file_action_batches b JOIN file_action_items i ON i.batch_id=b.id ' .
            'JOIN scan_profiles p ON p.id=b.profile_id ' .
            'GROUP BY b.id,b.action_kind,b.state,b.requested_at,b.completed_at,' .
            'b.error_code,p.name ORDER BY b.id DESC LIMIT 100'
        );
    }

    public function audit(?int $actorId,string $code,int $batchId,string $outcome,array $context=[]): void
    {
        $this->db->insert('audit_events',[
            'actor_id'=>$actorId,'action'=>$code,'entity_type'=>'file_action_batch',
            'entity_id'=>(string)$batchId,'correlation_id'=>bin2hex(random_bytes(16)),
            'outcome'=>$outcome,
            'context'=>json_encode($context,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }
}
