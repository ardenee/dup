<?php
declare(strict_types=1);
namespace App\Module\Renaming\Service;

use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\ActionSettings;
use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Index-driven candidate previews and explicitly approved rename batches.
 * Only completed current scan entries may be changed. The CLI worker performs
 * all actual file renames and writes the audit trail.
 */
final class RenameService
{
    public const PREVIEW_SORTS=['id'=>'f.id','file'=>'f.filename',
        'folder'=>'d.path','size'=>'f.size_bytes'];
    public function __construct(
        private readonly Connection $db,
        private readonly SafeFileSystem $fs,
        private readonly FileActionService $actions,
        private readonly ActionSettings $settings
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
        bool $duplicatesOnly=false,?int $after=null,bool $numberCollisions=false,
        string $find='',string $replace='',bool $overwriteCollisions=false,
        string $sort='id',string $direction='asc',string $name=''
    ): array {
        $latest=(int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [$profileId]
        );
        if(!$latest) return ['rows'=>[],'next'=>null,'scanned'=>0];
        if(!isset(self::PREVIEW_SORTS[$sort]) || !in_array($direction,['asc','desc'],true))
            throw new \DomainException('Invalid rename preview sort.');
        if(mb_strlen($name)>200) throw new \DomainException('File filter is too long.');
        $sql='FROM file_entries f JOIN directories d ON d.id=f.directory_id '.
            'WHERE f.run_id=:run AND f.error_code IS NULL AND d.is_ignored=0';
        $args=['run'=>$latest];
        if($duplicatesOnly) {
            $sql.=" AND EXISTS (SELECT 1 FROM duplicate_group_members m ".
                  "JOIN duplicate_groups g ON g.id=m.group_id ".
                  "WHERE m.file_entry_id=f.id AND g.run_id=f.run_id)";
        }
        if($name!=='') {
            $pattern=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($name));
            $sql.=" AND (LOWER(f.filename) LIKE :name_a ESCAPE '!' ".
                "OR LOWER(d.path) LIKE :name_b ESCAPE '!')";
            $args['name_a']='%'.$pattern.'%';
            $args['name_b']='%'.$pattern.'%';
        }
        $page=KeysetPage::fetch(
            $this->db,'f.id,f.filename,f.size_bytes,f.mtime_epoch,d.path AS directory',
            ' '.$sql,$args,'f.id',self::PREVIEW_SORTS[$sort],strtoupper($direction),100,
            $after,null,in_array($sort,['id','size'],true)
        );
        $data=$page['rows'];
        $next=$page['next'];
        $list=[];$reserved=[];
        foreach($data as $row) {
            try {
                $target=RenameRules::cleaned(
                    (string)$row['filename'],$filters,$dots,$unders,$collapse,$find,$replace
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
                $row['overwrite_existing']=$overwriteCollisions &&
                    (file_exists((string)$row['directory'].DIRECTORY_SEPARATOR.$planned) ||
                     is_link((string)$row['directory'].DIRECTORY_SEPARATOR.$planned));
                if($numberCollisions) {
                    $full=rtrim((string)$row['directory'],'/\\').DIRECTORY_SEPARATOR.$planned;
                    $reserved[PHP_OS_FAMILY==='Windows'?mb_strtolower($full):$full]=true;
                }
                $list[]=$row;
            }
        }
        return [
            'rows'=>$list,
            'next'=>$next,
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
        int $dots,int $unders,bool $collapse,array $names=[],
        string $find='',string $replace='',string $collision='fail',bool $numberManual=false
    ): int {
        if(!in_array($collision,['fail','number','overwrite'],true))
            throw new \DomainException('Unknown filename conflict handling.');
        $configured=$this->settings->policy($profileId);
        if($collision!==$configured)
            throw new \DomainException('File Exists policy changed; reload this profile before renaming.');
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
                    (string)$file['filename'],$filters,$dots,$unders,$collapse,$find,$replace
                );
            }
            if($targetName===(string)$file['filename'])
                throw new \DomainException('A selected filename would not change.');
            // Manual renames honor this profile's Append Number policy.
            // Automatic clean-up previews retain their exact approved names:
            // a newly appearing collision must not silently rename them.
            if($collision==='number' && $numberManual)
                $targetName=RenameRules::availableName(
                    (string)$file['directory'],$targetName,$targets
                );
            $target=(string)$file['directory'].DIRECTORY_SEPARATOR.$targetName;
            $key=PHP_OS_FAMILY==='Windows'?mb_strtolower($target):$target;
            $overwrite=null;
            if($collision==='overwrite' && (file_exists($target) || is_link($target))) {
                if(is_link($target) || !is_file($target))
                    throw new \DomainException('Cannot overwrite a link or non-file target.');
                $stat=$this->fs->checkedStat($target,(int)filesize($target),(int)filemtime($target));
                $overwrite=['size'=>(int)$stat['size'],'mtime'=>(int)$stat['mtime']];
            }
            if(isset($targets[$key]) ||
                ($overwrite===null && (file_exists($target) || is_link($target))))
                throw new \DomainException('Rename destination already exists or conflicts with another selection: '.$targetName);
            $targets[$key]=true;
            $items[]=[
                'id'=>$id,'source'=>$source,'target'=>$target,
                'size'=>(int)$file['size_bytes'],'mtime'=>(int)$file['mtime_epoch'],
                'overwrite'=>$overwrite
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
                    $backup=$item['overwrite']===null?null:
                        dirname($item['target']).DIRECTORY_SEPARATOR.
                        '.dup-replaced-'.$batchId.'-'.$item['id'];
                    $db->insert('file_action_items',[
                        'batch_id'=>$batchId,'file_entry_id'=>$item['id'],
                        'source_path'=>$item['source'],'target_path'=>$item['target'],
                        'state'=>'planned','expected_size'=>$item['size'],
                        'expected_mtime'=>$item['mtime'],
                        ...($item['overwrite']!==null?[
                            'overwrite_target_size'=>$item['overwrite']['size'],
                            'overwrite_target_mtime'=>$item['overwrite']['mtime'],
                            'overwrite_backup_path'=>$backup
                        ]:[])
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
