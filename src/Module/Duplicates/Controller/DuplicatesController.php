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
        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        $where=" FROM duplicate_groups g " .
            "JOIN scan_runs r ON r.id=g.run_id JOIN scan_profiles p ON p.id=r.profile_id " .
            "WHERE r.state='completed' AND r.id=(SELECT MAX(id) FROM scan_runs " .
            "WHERE profile_id=r.profile_id AND state='completed')";
        $params=[];
        if($profile!==null) {
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
            'g.rules_version,g.group_key,p.name AS profile_name,p.id AS profile_id ' .
            $pageWhere.$order.' LIMIT '.(self::PAGE_GROUPS+1),
            $params
        );
        if(count($rows)>self::PAGE_GROUPS) $rows=array_slice($rows,0,self::PAGE_GROUPS);
        if($before!==null) $rows=array_reverse($rows);
        $first=$rows?(int)$rows[0]['id']:null;
        $last=$rows?(int)$rows[count($rows)-1]['id']:null;
        $baseParams=$profile===null?[]:['profile'=>$profile];
        $prior=$first===null?false:(bool)$db->fetchOne(
            'SELECT 1'.$where.' AND g.id<:cursor LIMIT 1',
            array_merge($baseParams,['cursor'=>$first])
        );
        $later=$last===null?false:(bool)$db->fetchOne(
            'SELECT 1'.$where.' AND g.id>:cursor LIMIT 1',
            array_merge($baseParams,['cursor'=>$last])
        );
        return $this->render('duplicates/index.html.twig',[
            'groups'=>$rows,'profiles'=>$profiles,'selected_profile'=>$profile,
            'previous'=>$prior?$first:null,'next'=>$later?$last:null
        ]);
    }

    #[Route('/duplicates/{id<\\d+>}',name:'app_duplicate_group',methods:['GET'])]
    public function group(int $id,Request $request,Connection $db): Response
    {
        $group=$db->fetchAssociative(
            "SELECT g.id,g.group_key,g.size_bytes,g.file_count,g.reclaimable_bytes," .
            "g.rules_version,r.id AS run_id,p.name AS profile_name " .
            "FROM duplicate_groups g JOIN scan_runs r ON r.id=g.run_id " .
            "JOIN scan_profiles p ON p.id=r.profile_id " .
            "WHERE g.id=? AND r.state='completed' AND r.id=(SELECT MAX(id) " .
            "FROM scan_runs WHERE profile_id=r.profile_id AND state='completed')",[$id]
        );
        if(!$group) throw $this->createNotFoundException('Duplicate group not found in the latest completed scan.');

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
            'group'=>$group,'members'=>$members,
            'previous'=>$prior?$first:null,'next'=>$later?$last:null
        ]);
    }
}
