<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lazy, server-paginated members for inline duplicate browsing.
 * A profile results page never loads every duplicate file into memory.
 */
final class DuplicateMembersController extends AbstractController
{
    private const PER_PAGE = 100;

    #[Route('/duplicates/{id<\\d+>}/members',name:'app_duplicate_members',methods:['GET'])]
    public function index(int $id,Request $request,Connection $db): Response
    {
        $group=$db->fetchAssociative(
            "SELECT g.id,g.file_count,g.size_bytes,g.rules_version,".
            "p.id AS profile_id,p.name AS profile_name ".
            "FROM duplicate_groups g JOIN scan_runs r ON r.id=g.run_id ".
            "JOIN scan_profiles p ON p.id=r.profile_id ".
            "WHERE g.id=? AND r.state='completed' ".
            "AND r.id=(SELECT MAX(id) FROM scan_runs ".
            "WHERE profile_id=r.profile_id AND state='completed')",
            [$id]
        );
        if(!$group) throw $this->createNotFoundException('The duplicate group is no longer in the latest scan.');

        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        if($after!==null && $before!==null)
            throw $this->createNotFoundException('Invalid member pagination.');

        $params=[$id];
        $where=' FROM duplicate_group_members m '.
            'JOIN file_entries f ON f.id=m.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id '.
            'WHERE m.group_id=?';
        $reverse=$before!==null;
        if($after!==null) {
            $where.=' AND m.id>?'; $params[]=$after;
        } elseif($before!==null) {
            $where.=' AND m.id<?'; $params[]=$before;
        }
        $order=$reverse?' DESC':' ASC';
        $members=$db->fetchAllAssociative(
            'SELECT m.id AS member_id,f.id AS file_entry_id,f.filename,'.
            'f.size_bytes,f.mtime_epoch,f.hash_value,f.error_code,d.path '.
            $where.' ORDER BY m.id'.$order.' LIMIT '.(self::PER_PAGE+1),
            $params
        );
        if(count($members)>self::PER_PAGE) array_pop($members);
        if($reverse) $members=array_reverse($members);
        $first=$members?(int)$members[0]['member_id']:null;
        $last=$members?(int)$members[count($members)-1]['member_id']:null;
        $previous=$first!==null && (bool)$db->fetchOne(
            'SELECT 1 FROM duplicate_group_members WHERE group_id=? AND id<? LIMIT 1',
            [$id,$first]
        );
        $next=$last!==null && (bool)$db->fetchOne(
            'SELECT 1 FROM duplicate_group_members WHERE group_id=? AND id>? LIMIT 1',
            [$id,$last]
        );
        $response=$this->render('duplicates/_members.html.twig',[
            'group'=>$group,'members'=>$members,
            'previous'=>$previous?$first:null,'next'=>$next?$last:null
        ]);
        $response->headers->set('Cache-Control','private, no-store');
        return $response;
    }

    private function cursor(Request $request,string $key): ?int
    {
        $value=$request->query->get($key);
        if($value===null || $value==='') return null;
        if(!is_scalar($value))
            throw $this->createNotFoundException('Invalid pagination cursor.');
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) throw $this->createNotFoundException('Invalid pagination cursor.');
        return $id;
    }
}
