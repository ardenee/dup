<?php
declare(strict_types=1);
namespace App\Module\Search\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * SQL search against the latest successful snapshot of each profile.
 * Never traverses file paths or opens media files. Pagination is keyed on
 * indexed entry IDs; the backend always limits result-set memory.
 */
final class SearchRepository
{
    private const PAGE=50;

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
        ?int $after=null,?int $before=null
    ): array {
        if(!in_array($mode,['all','duplicates','moved'],true))
            throw new \InvalidArgumentException('Unsupported search mode.');
        if($after!==null && $before!==null)
            throw new \InvalidArgumentException('Choose only one page cursor.');

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
            // The new action history currently records quarantined files.
            // Legacy move-to-folder functionality is not yet implemented.
            $sql.=" AND f.error_code='QUARANTINED'";
        }
        $total=(int)$this->db->fetchOne('SELECT COUNT(*)'.$sql,$params);
        $paged=$sql;
        $cursor=$params;
        $reverse=$before!==null;
        if($after!==null) {
            $paged.=' AND f.id<:after';
            $cursor['after']=$after;
        } elseif($before!==null) {
            $paged.=' AND f.id>:before';
            $cursor['before']=$before;
        }
        $paged.=' ORDER BY f.id '.($reverse?'ASC':'DESC').
            ' LIMIT '.(self::PAGE+1);
        $rows=$this->db->fetchAllAssociative(
            'SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,f.hash_method,'.
            'f.hash_value,f.error_code,d.path AS directory,d.is_ignored,'.
            'p.id AS profile_id,p.name AS profile_name,'.
            '(SELECT MIN(m.group_id) FROM duplicate_group_members m '.
            'WHERE m.file_entry_id=f.id) AS group_id'.$paged,
            $cursor
        );
        $extra=count($rows)>self::PAGE;
        if($extra) array_pop($rows);
        if($reverse) $rows=array_reverse($rows);
        $first=$rows?(int)$rows[0]['id']:null;
        $last=$rows?(int)$rows[count($rows)-1]['id']:null;
        return [
            'rows'=>$rows,'total'=>$total,
            'next'=>$last!==null && (!$reverse?$extra:true)?$last:null,
            'previous'=>$first!==null && ($reverse?$extra:$after!==null)?$first:null
        ];
    }
}
