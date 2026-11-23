<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use Doctrine\DBAL\Connection;

/**
 * Rebuilds duplicate groups using ONLY already indexed filenames, sizes and
 * hashes. Does not open/read source files, including previously ignored ones.
 */
final class DuplicateRebuilder
{
    public function __construct(private readonly Connection $db) {}

    /** Returns the count of directory rows whose ignored status changed. */
    public function updateIgnoredDirectories(int $runId, array $folderNames, callable $checkpoint): int
    {
        $names=[];
        foreach($folderNames as $name) $names[mb_strtolower((string)$name)]=true;
        $rows=$this->db->executeQuery(
            'SELECT id,path,is_ignored FROM directories WHERE run_id=? ORDER BY id',[$runId]
        );
        $updates=[0=>[],1=>[]];$changed=0;$processed=0;
        while(($row=$rows->fetchAssociative())!==false) {
            $processed++;
            $ignored=false;
            $segments=preg_split('~[\\\\/]~u',(string)$row['path'],-1,PREG_SPLIT_NO_EMPTY);
            foreach($segments as $segment) {
                if(isset($names[mb_strtolower($segment)])) {$ignored=true;break;}
            }
            $flag=$ignored?1:0;
            if($flag!==(int)$row['is_ignored']) {
                $updates[$flag][]=(int)$row['id'];
                $changed++;
            }
            if(($processed%250)===0) $checkpoint($processed);
        }
        foreach($updates as $flag=>$ids) {
            foreach(array_chunk($ids,250) as $chunk) {
                $marks=implode(',',array_fill(0,count($chunk),'?'));
                $this->db->executeStatement(
                    "UPDATE directories SET is_ignored=? WHERE id IN ($marks)",
                    array_merge([$flag],$chunk)
                );
                $checkpoint($processed);
            }
        }
        return $changed;
    }

    /**
     * Must be invoked within an application transaction for an existing
     * completed run. The complete previous group set survives on failure.
     */
    public function rebuild(int $runId,string $mode,callable $checkpoint): int
    {
        if(!in_array($mode,['sha256','size'],true))
            throw new \DomainException('Unknown scan duplicate-matching mode.');

        $this->db->executeStatement(
            'DELETE FROM duplicate_group_members WHERE group_id IN ' .
            '(SELECT id FROM duplicate_groups WHERE run_id=?)',[$runId]
        );
        $this->db->executeStatement('DELETE FROM duplicate_groups WHERE run_id=?',[$runId]);

        $verified=$mode==='sha256';
        $filter=$verified?' AND f.hash_value IS NOT NULL':'';
        $columns=$verified?'f.size_bytes,f.hash_value':'f.size_bytes';
        $groups=$this->db->executeQuery(
            'SELECT '.$columns.',COUNT(*) AS members FROM file_entries f ' .
            'INNER JOIN directories d ON d.id=f.directory_id ' .
            'WHERE f.run_id=? AND d.is_ignored=0 AND f.error_code IS NULL'.$filter .
            ' GROUP BY '.$columns.' HAVING COUNT(*)>1 ORDER BY f.size_bytes',[$runId]
        );
        $count=0;
        while(($row=$groups->fetchAssociative())!==false) {
            $checkpoint($count);
            $size=(int)$row['size_bytes'];
            $members=(int)$row['members'];
            $hash=$verified?(string)$row['hash_value']:null;
            $this->db->insert('duplicate_groups',[
                'run_id'=>$runId,'group_key'=>$verified?'sha256:'.$hash:'size:'.$size,
                'size_bytes'=>$size,'file_count'=>$members,
                'reclaimable_bytes'=>($members-1)*$size,'rules_version'=>$verified?1:0
            ]);
            $groupId=(int)$this->db->lastInsertId();
            $sql='SELECT f.id FROM file_entries f INNER JOIN directories d ON d.id=f.directory_id ' .
                'WHERE f.run_id=? AND f.size_bytes=? AND d.is_ignored=0 AND f.error_code IS NULL';
            $args=[$runId,$size];
            if($verified) {$sql.=' AND f.hash_value=?';$args[]=$hash;}
            $files=$this->db->executeQuery($sql.' ORDER BY f.id',$args);
            $batch=[];
            while(($file=$files->fetchAssociative())!==false) {
                $batch[]=(int)$file['id'];
                if(count($batch)===200) {
                    $this->insertMembers($groupId,$batch);
                    $batch=[];
                    $checkpoint($count);
                }
            }
            if($batch) $this->insertMembers($groupId,$batch);
            $count++;
        }
        return $count;
    }

    private function insertMembers(int $groupId,array $ids): void
    {
        $args=[];$marks=[];
        foreach($ids as $id) {
            $marks[]='(?,?)';
            $args[]=$groupId;
            $args[]=$id;
        }
        $this->db->executeStatement(
            'INSERT INTO duplicate_group_members (group_id,file_entry_id) VALUES '.implode(',',$marks),
            $args
        );
    }
}
