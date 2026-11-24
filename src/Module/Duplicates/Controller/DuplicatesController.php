<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DuplicatesController extends AbstractController
{
    private const PAGE_GROUPS=50;
    private const PAGE_MEMBERS=100;

    /** Parse keyset cursor. No unbounded OFFSET and no hidden form input limits. */
    private function cursor(Request $request,string $key): ?int
    {
        $value=$request->query->get($key);
        if($value===null) return null;
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false?null:$id;
    }

    #[Route('/duplicates',name:'app_duplicates',methods:['GET'])]
    public function index(Request $request,Connection $db): Response
    {
        $profiles=$db->fetchAllAssociative(
            "SELECT p.id,p.name,r.id AS run_id,r.duplicate_groups " .
            "FROM scan_profiles p LEFT JOIN scan_runs r ON r.id=" .
            "(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') " .
            'ORDER BY p.name'
        );
        $profile=$this->cursor($request,'profile');
        $selectedRun=$this->cursor($request,'run');
        $historical=false;
        $runInfo=null;
        if($selectedRun!==null) {
            $runInfo=$db->fetchAssociative(
                'SELECT r.id,r.profile_id,r.state,r.finished_at,p.name AS profile_name '.
                'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id '.
                "WHERE r.id=? AND r.state IN ('completed','archived')",[$selectedRun]
            );
            if(!$runInfo || ($profile!==null && $profile!==(int)$runInfo['profile_id']))
                throw $this->createNotFoundException('No completed scan matches this selection.');
            $profile=(int)$runInfo['profile_id'];
            $currentId=(int)$db->fetchOne(
                "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
                [$profile]
            );
            $historical=$currentId!==$selectedRun || $runInfo['state']!=='completed';
        }
        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        $where=" FROM duplicate_groups g " .
            "JOIN scan_runs r ON r.id=g.run_id JOIN scan_profiles p ON p.id=r.profile_id " .
            "LEFT JOIN duplicate_group_members firstm ON firstm.id=" .
            "(SELECT MIN(fm.id) FROM duplicate_group_members fm WHERE fm.group_id=g.id) " .
            "LEFT JOIN file_entries firstf ON firstf.id=firstm.file_entry_id " .
            "LEFT JOIN directories firstd ON firstd.id=firstf.directory_id " .
            ($selectedRun!==null
                ? 'WHERE g.run_id=:selected_run'
                : "WHERE r.state='completed' AND r.id=(SELECT MAX(id) FROM scan_runs " .
                  "WHERE profile_id=r.profile_id AND state='completed')");
        $params=$selectedRun!==null?['selected_run'=>$selectedRun]:[];
        if($profile!==null && $selectedRun===null) {
            $where.=' AND p.id=:profile';
            $params['profile']=$profile;
        }
        $pageWhere=$where;
        if($before!==null) {
            $pageWhere.=' AND g.id<:before';
            $params['before']=$before;
            $order=' ORDER BY g.id DESC';
        } elseif($after!==null) {
            $pageWhere.=' AND g.id>:after';
            $params['after']=$after;
            $order=' ORDER BY g.id ASC';
        } else $order=' ORDER BY g.id ASC';

        $rows=$db->fetchAllAssociative(
            'SELECT g.id,g.size_bytes,g.file_count,g.reclaimable_bytes,' .
            'g.rules_version,p.name AS profile_name,p.id AS profile_id,' .
            'p.source_root,firstf.id AS first_file_id,' .
            'firstf.filename AS first_filename,firstf.mtime_epoch AS first_mtime_epoch,' .
            'firstf.hash_value AS first_hash_value,firstf.error_code AS first_error_code,' .
            'firstd.path AS first_directory ' .
            $pageWhere.$order.' LIMIT '.(self::PAGE_GROUPS+1),
            $params
        );
        if(count($rows)>self::PAGE_GROUPS) $rows=array_slice($rows,0,self::PAGE_GROUPS);
        if($before!==null) $rows=array_reverse($rows);
        foreach($rows as &$row) {
            $directory=str_replace('\\','/',(string)($row['first_directory']??''));
            $root=rtrim(str_replace('\\','/',(string)$row['source_root']),'/');
            $filename=(string)($row['first_filename']??'');
            $relative=$directory===$root?'':(
                $root!=='' && str_starts_with($directory,$root.'/')
                    ? ltrim(substr($directory,strlen($root)),'/').'/'
                    : ltrim($directory,'/').'/'
            );
            $row['relative_path']=$relative.$filename;
            $row['full_path']=rtrim($directory,'/').'/'.$filename;
        }
        unset($row);
        $first=$rows?(int)$rows[0]['id']:null;
        $last=$rows?(int)$rows[count($rows)-1]['id']:null;
        $baseParams=$selectedRun!==null?['selected_run'=>$selectedRun]:($profile===null?[]:['profile'=>$profile]);
        $prior=$first===null?false:(bool)$db->fetchOne(
            'SELECT 1'.$where.' AND g.id<:cursor LIMIT 1',
            array_merge($baseParams,['cursor'=>$first])
        );
        $later=$last===null?false:(bool)$db->fetchOne(
            'SELECT 1'.$where.' AND g.id>:cursor LIMIT 1',
            array_merge($baseParams,['cursor'=>$last])
        );
        $totals=$db->fetchAssociative(
            'SELECT COUNT(*) AS total_groups, '.
            'COALESCE(SUM(g.file_count),0) AS total_members, '.
            'COALESCE(SUM(g.reclaimable_bytes),0) AS reclaimable_bytes '.
            $where,$baseParams
        );
        $pageStart=$first===null?0:1+(int)$db->fetchOne(
            'SELECT COUNT(*)'.$where.' AND g.id<:cursor',
            array_merge($baseParams,['cursor'=>$first])
        );
        $pageEnd=$rows?$pageStart+count($rows)-1:0;
        $pageRows=array_sum(array_map(static fn(array $g)=>(int)$g['file_count'],$rows));
        $pageListedBytes=array_sum(array_map(
            static fn(array $g)=>(int)$g['file_count']*(int)$g['size_bytes'],$rows
        ));
        $pageReclaimable=array_sum(array_map(
            static fn(array $g)=>(int)$g['reclaimable_bytes'],$rows
        ));
        return $this->render('duplicates/index.html.twig',[
            'groups'=>$rows,'profiles'=>$profiles,'selected_profile'=>$profile,
            'selected_run'=>$selectedRun,'run_info'=>$runInfo,'historical'=>$historical,
            'previous'=>$prior?$first:null,'next'=>$later?$last:null,
            'totals'=>$totals ?: ['total_groups'=>0,'total_members'=>0,'reclaimable_bytes'=>0],
            'page_start'=>$pageStart,'page_end'=>$pageEnd,'page_rows'=>$pageRows,
            'page_listed_bytes'=>$pageListedBytes,'page_reclaimable'=>$pageReclaimable
        ]);
    }

    #[Route('/duplicates/{id<\\d+>}',name:'app_duplicate_group',methods:['GET'])]
    public function group(int $id,Request $request,Connection $db): Response
    {
        $group=$db->fetchAssociative(
            "SELECT g.id,g.group_key,g.size_bytes,g.file_count,g.reclaimable_bytes," .
            "g.rules_version,r.id AS run_id,r.state AS run_state,p.id AS profile_id," .
            "p.name AS profile_name " .
            "FROM duplicate_groups g JOIN scan_runs r ON r.id=g.run_id " .
            "JOIN scan_profiles p ON p.id=r.profile_id " .
            "WHERE g.id=? AND r.state IN ('completed','archived')",[$id]
        );
        if(!$group) throw $this->createNotFoundException('Duplicate group not found.');
        $currentId=(int)$db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [(int)$group['profile_id']]
        );
        $historical=$currentId!==(int)$group['run_id'] || $group['run_state']!=='completed';

        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        $where=' FROM duplicate_group_members m JOIN file_entries f ON f.id=m.file_entry_id ' .
            'JOIN directories d ON d.id=f.directory_id WHERE m.group_id=?';
        $params=[$id];
        if($before!==null) {
            $where.=' AND m.id<?';
            $params[]=$before;
            $order=' ORDER BY m.id DESC';
        } elseif($after!==null) {
            $where.=' AND m.id>?';
            $params[]=$after;
            $order=' ORDER BY m.id ASC';
        } else $order=' ORDER BY m.id ASC';

        $members=$db->fetchAllAssociative(
            'SELECT m.id AS member_id,f.id AS file_entry_id,d.path,f.filename,f.size_bytes,f.mtime_epoch,' .
            'f.hash_value,f.error_code'.$where.$order.' LIMIT '.(self::PAGE_MEMBERS+1),$params
        );
        if(count($members)>self::PAGE_MEMBERS) $members=array_slice($members,0,self::PAGE_MEMBERS);
        if($before!==null) $members=array_reverse($members);
        $first=$members?(int)$members[0]['member_id']:null;
        $last=$members?(int)$members[count($members)-1]['member_id']:null;
        $prior=$first===null?false:(bool)$db->fetchOne(
            'SELECT 1 FROM duplicate_group_members WHERE group_id=? AND id<? LIMIT 1',[$id,$first]
        );
        $later=$last===null?false:(bool)$db->fetchOne(
            'SELECT 1 FROM duplicate_group_members WHERE group_id=? AND id>? LIMIT 1',[$id,$last]
        );
        return $this->render('duplicates/group.html.twig',[
            'group'=>$group,'members'=>$members,'historical'=>$historical,
            'previous'=>$prior?$first:null,'next'=>$later?$last:null
        ]);
    }
}
