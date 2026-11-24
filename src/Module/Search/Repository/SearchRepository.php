<?php
declare(strict_types=1);
namespace App\Module\Search\Repository;

use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * SQL search against the latest successful snapshot of each profile.
 * Never traverses file paths or opens media files. Pagination is keyed on
 * indexed entry IDs; the backend always limits result-set memory.
 */
final class SearchRepository
{
    private const PAGE=50;
    public const SORTS=[
        'id'=>'f.id','path'=>'f.filename','profile'=>'p.name',
        'size'=>'f.size_bytes','modified'=>'f.mtime_epoch',
        'method'=>"COALESCE(f.hash_method,'')",
        'state'=>"COALESCE(f.error_code,'')",
        'group'=>'COALESCE((SELECT MIN(m.group_id) FROM duplicate_group_members m WHERE m.file_entry_id=f.id),0)'
    ];

    public function __construct(private readonly Connection $db) {}

    public function profiles(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id,name,source_root FROM scan_profiles ORDER BY name,id'
        );
    }

    /** @return array{rows:array,total:int,next:?int,previous:?int} */
    public function find(
        string $term,?int $profile,string $mode,bool $caseSensitive,
        ?int $after=null,?int $before=null,
        string $sort='id',string $direction='desc',string $fileState=''
    ): array {
        if(!in_array($mode,['all','duplicates','moved'],true))
            throw new \InvalidArgumentException('Unsupported search mode.');
        if($after!==null && $before!==null)
            throw new \InvalidArgumentException('Choose only one page cursor.');
        if(!isset(self::SORTS[$sort]) || !in_array($direction,['asc','desc'],true))
            throw new \InvalidArgumentException('Invalid file search sorting.');

        $sql=' FROM file_entries f JOIN directories d ON d.id=f.directory_id '.
            'JOIN scan_runs r ON r.id=f.run_id '.
            'JOIN scan_profiles p ON p.id=r.profile_id '.
            'WHERE r.state=\'completed\' '.
            'AND r.id=(SELECT MAX(latest.id) FROM scan_runs latest '.
            'WHERE latest.profile_id=p.id AND latest.state=\'completed\')';
        $params=[];
        if($profile!==null) {
            $sql.=' AND p.id=:profile';
            $params['profile']=$profile;
        }
        if($term!=='') {
            if($caseSensitive) {
                // The BINARY modifier makes MySQL string comparison
                // truly case-sensitive regardless of database collation.
                if($this->db->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                    $sql.=' AND (INSTR(BINARY f.filename,BINARY :term)>0 '.
                        'OR INSTR(BINARY d.path,BINARY :term)>0)';
                } else {
                    $sql.=' AND (instr(f.filename,:term)>0 OR instr(d.path,:term)>0)';
                }
                $params['term']=$term;
            } else {
                $pattern=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($term));
                $sql.=" AND (LOWER(f.filename) LIKE :term ESCAPE '!'";
                $sql.=" OR LOWER(d.path) LIKE :term ESCAPE '!')";
                $params['term']='%'.$pattern.'%';
            }
        }
        if($mode==='duplicates') {
            $sql.=' AND EXISTS (SELECT 1 FROM duplicate_group_members m '.
                'JOIN duplicate_groups g ON g.id=m.group_id '.
                'WHERE m.file_entry_id=f.id AND g.run_id=r.id)';
        } elseif($mode==='moved') {
            $sql.=" AND f.error_code IN ('QUARANTINED','MOVED','LINKED')";
        }
        if($fileState!=='') {
            $stateSql=match($fileState) {
                'indexed'=>'f.error_code IS NULL AND f.is_symlink=0 AND d.is_ignored=0',
                'ignored'=>'d.is_ignored=1',
                'symlink'=>'f.is_symlink=1',
                'moved'=>"f.error_code='MOVED'",
                'linked'=>"f.error_code='LINKED'",
                'quarantined'=>"f.error_code='QUARANTINED'",
                'error'=>'f.error_code IS NOT NULL',
                default=>throw new \InvalidArgumentException('Unsupported file status filter.')
            };
            $sql.=' AND '.$stateSql;
        }
        $targetSelect=$mode==='moved'?
            "(SELECT CASE WHEN b.action_kind='link' THEN i.link_target_path ".
            "ELSE i.target_path END FROM file_action_items i ".
            "JOIN file_action_batches b ON b.id=i.batch_id ".
            "WHERE i.file_entry_id=f.id AND ".
            "((f.error_code='MOVED' AND i.state='moved' AND b.action_kind='move') OR ".
            "(f.error_code='LINKED' AND i.state='linked' AND b.action_kind='link') OR ".
            "(f.error_code='QUARANTINED' AND i.state='quarantined' AND b.action_kind='quarantine')) ".
            "ORDER BY i.id DESC LIMIT 1) AS action_target,":
            'NULL AS action_target,';
        $expr=$sort==='path' ?
            ($this->db->getDatabasePlatform() instanceof AbstractMySQLPlatform
                ? "LOWER(CONCAT(d.path,'/',f.filename))"
                : "LOWER(d.path || '/' || f.filename)") :
            self::SORTS[$sort];
        $result=KeysetPage::fetch(
            $this->db,
            $targetSelect.'f.id,f.filename,f.size_bytes,f.mtime_epoch,f.hash_method,'.
            'f.is_symlink,f.link_target,f.hash_value,f.error_code,'.
            'd.path AS directory,d.is_ignored,'.
            'p.id AS profile_id,p.name AS profile_name,'.
            '(SELECT MIN(m.group_id) FROM duplicate_group_members m '.
            'WHERE m.file_entry_id=f.id) AS group_id',
            $sql,$params,'f.id',$expr,strtoupper($direction),
            self::PAGE,$after,$before,in_array($sort,['id','size','modified','group'],true)
        );
        // Search URLs historically use after for the NEXT (older) page
        // and before for the PREVIOUS page; preserve their names.
        return [
            'rows'=>$result['rows'],'total'=>$result['total'],
            'next'=>$result['next'],'previous'=>$result['previous']
        ];
    }
}
