<?php
declare(strict_types=1);
namespace App\Module\Renaming\Service;

use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\SafeFileSystem;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Index-driven candidate previews and explicitly approved rename batches.
 * Only completed current scan entries may be changed. The CLI worker performs
 * all actual file renames and writes the audit trail.
 */
final class RenameService
{
    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $actions
    ) {}

    public function profiles(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT p.id,p.name FROM scan_profiles p WHERE EXISTS (".
            "SELECT 1 FROM scan_runs r WHERE r.profile_id=p.id AND r.state='completed') ".
            "ORDER BY p.name,p.id"
        );
    }

    public function file(int $entryId): array|false
    {
        return $this->db->fetchAssociative(
            "SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,f.error_code,".
            "d.path AS directory,d.is_ignored,p.id AS profile_id,p.name AS profile_name,".
            "r.id AS run_id FROM file_entries f ".
            "JOIN directories d ON d.id=f.directory_id ".
            "JOIN scan_runs r ON r.id=f.run_id ".
            "JOIN scan_profiles p ON p.id=r.profile_id ".
            "WHERE f.id=? AND r.state='completed' AND ".
            "r.id=(SELECT MAX(x.id) FROM scan_runs x WHERE x.profile_id=p.id AND x.state='completed')",
            [$entryId]
        );
    }

    public function candidates(
        int $profileId,string $filters,int $dots,int $unders,bool $collapse,
        bool $duplicatesOnly=false,?int $after=null,bool $numberCollisions=false
    ): array {
        $latest=(int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [$profileId]
        );
        if(!$latest) return ['rows'=>[],'next'=>null,'scanned'=>0];
        $sql="SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,d.path AS directory ".
            "FROM file_entries f JOIN directories d ON d.id=f.directory_id ".
            "WHERE f.run_id=? AND f.error_code IS NULL AND d.is_ignored=0 ";
        $args=[$latest];
        if($duplicatesOnly) {
            $sql.=" AND EXISTS (SELECT 1 FROM duplicate_group_members m ".
                  "JOIN duplicate_groups g ON g.id=m.group_id ".
                  "WHERE m.file_entry_id=f.id AND g.run_id=f.run_id)";
        }
        if($after!==null) {$sql.=" AND f.id>?";$args[]=$after;}
        $data=$this->db->fetchAllAssociative($sql.' ORDER BY f.id LIMIT 101',$args);
        $more=count($data)>100;
        if($more) array_pop($data);
        $list=[];$reserved=[];
        foreach($data as $row) {
            try {
                $target=RenameRules::cleaned(
                    (string)$row['filename'],$filters,$dots,$unders,$collapse
                );
            } catch(\DomainException) {continue;}
            if($target!==(string)$row['filename']) {
                try {
                    $planned=$numberCollisions
                        ?RenameRules::availableName((string)$row['directory'],$target,$reserved)
                        :$target;
                } catch(\DomainException) {continue;}
                $row['new_name']=$planned;
                $row['collision_numbered']=$planned!==$target;
                if($numberCollisions) {
                    $full=rtrim((string)$row['directory'],'/\\').DIRECTORY_SEPARATOR.$planned;
                    $reserved[PHP_OS_FAMILY==='Windows'?mb_strtolower($full):$full]=true;
                }
                $list[]=$row;
            }
        }
        return [
            'rows'=>$list,
            'next'=>$more && $data?(int)$data[count($data)-1]['id']:null,
            'scanned'=>count($data)
        ];
    }

    /**
     * @param list<int> $entryIds
     * @param array<int,string> $names Individual manual overrides; all others
     *                                 use the original DUP cleanup rules.
     */
    public function queue(
        int $profileId,int $actorId,array $entryIds,string $filters,
        int $dots,int $unders,bool $collapse,array $names=[]
    ): int {
        $entryIds=array_values(array_unique($entryIds));
        if(count($entryIds)<1 || count($entryIds)>50 || $profileId<1)
            throw new \DomainException('Select between 1 and 50 files from one profile.');
        $runId=(int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [$profileId]
        );
        if(!$runId) throw new \DomainException('Scan this profile before renaming.');
        $items=[];$targets=[];
        foreach($entryIds as $id) {
            $file=$this->file($id);
            if(!$file || (int)$file['profile_id']!==$profileId
                || (int)$file['run_id']!==$runId || $file['error_code']!==null
                || (int)$file['is_ignored']!==0)
                throw new \DomainException('A selected file is no longer available in the current scan.');
            $source=$this->fs->checkedPath((string)$file['directory'],(string)$file['filename']);
            $this->fs->checkedStat($source,(int)$file['size_bytes'],(int)$file['mtime_epoch']);
            if(isset($names[$id])) {
                $targetName=trim($names[$id]);
                RenameRules::validateName($targetName);
            } else {
                $targetName=RenameRules::cleaned(
                    (string)$file['filename'],$filters,$dots,$unders,$collapse
                );
            }
            if($targetName===(string)$file['filename'])
                throw new \DomainException('A selected filename would not change.');
            $target=(string)$file['directory'].DIRECTORY_SEPARATOR.$targetName;
            $key=PHP_OS_FAMILY==='Windows'?mb_strtolower($target):$target;
            if(isset($targets[$key]) || file_exists($target) || is_link($target))
                throw new \DomainException('Rename destination already exists or conflicts with another selection: '.$targetName);
            $targets[$key]=true;
            $items[]=[
                'id'=>$id,'source'=>$source,'target'=>$target,
                'size'=>(int)$file['size_bytes'],'mtime'=>(int)$file['mtime_epoch']
            ];
        }
        try {
            return $this->db->transactional(function(Connection $db) use (
                $items,$actorId,$profileId,$runId
            ): int {
                if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=? LIMIT 1',[$profileId]))
                    throw new \DomainException('This profile has an active scan or file job.');
                if((int)$db->fetchOne(
                    "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
                    [$profileId]
                )!==$runId)
                    throw new \DomainException('A newer scan exists. Preview the renamed files again.');
                $db->insert('file_action_batches',[
                    'actor_id'=>$actorId,'profile_id'=>$profileId,'action_kind'=>'rename',
                    'state'=>'queued','requested_at'=>gmdate('Y-m-d H:i:s')
                ]);
                $batchId=(int)$db->lastInsertId();
                foreach($items as $item) {
                    $db->insert('file_action_items',[
                        'batch_id'=>$batchId,'file_entry_id'=>$item['id'],
                        'source_path'=>$item['source'],'target_path'=>$item['target'],
                        'state'=>'planned','expected_size'=>$item['size'],
                        'expected_mtime'=>$item['mtime']
                    ]);
                }
                $db->insert('jobs',[
                    'kind'=>'file-rename','profile_id'=>$profileId,
                    'active_profile_id'=>$profileId,'run_id'=>$runId,
                    'file_action_batch_id'=>$batchId,'state'=>'queued',
                    'phase'=>'waiting','progress_current'=>'0',
                    'progress_total'=>count($items),'attempts'=>0,
                    'created_at'=>gmdate('Y-m-d H:i:s')
                ]);
                $jobId=(int)$db->lastInsertId();
                $this->actions->audit($actorId,'RENAME_QUEUED',$batchId,'queued',[
                    'job_id'=>$jobId,'files'=>count($items)
                ]);
                return $jobId;
            });
        } catch(UniqueConstraintViolationException $ex) {
            throw new \DomainException('Another job is active for this profile.',0,$ex);
        }
    }

    public function batches(): array
    {
        return $this->db->fetchAllAssociative(
            "SELECT b.id,b.state,b.requested_at,b.completed_at,b.error_code,".
            "p.name AS profile_name,COUNT(i.id) AS total,".
            "SUM(CASE WHEN i.state='renamed' THEN 1 ELSE 0 END) AS renamed,".
            "SUM(CASE WHEN i.state='failed' THEN 1 ELSE 0 END) AS failed ".
            "FROM file_action_batches b JOIN scan_profiles p ON p.id=b.profile_id ".
            "JOIN file_action_items i ON i.batch_id=b.id ".
            "WHERE b.action_kind='rename' ".
            "GROUP BY b.id,b.state,b.requested_at,b.completed_at,b.error_code,p.name ".
            "ORDER BY b.id DESC LIMIT 25"
        );
    }
}
