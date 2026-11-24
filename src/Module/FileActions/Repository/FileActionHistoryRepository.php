<?php
declare(strict_types=1);
namespace App\Module\FileActions\Repository;

use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;

/** Read-only, keyset-paginated audit history, shared by every action type. */
final class FileActionHistoryRepository
{
    private const PAGE_SIZE = 25;

    public function __construct(private readonly Connection $db) {}

    public function profiles(): array
    {
        return $this->db->fetchAllAssociative('SELECT id,name FROM scan_profiles ORDER BY name,id');
    }

    public function states(): array
    {
        return $this->db->fetchFirstColumn('SELECT DISTINCT state FROM file_action_batches ORDER BY state');
    }

    public const SORTS=[
        'id'=>'b.id',
        'type'=>'b.action_kind',
        'profile'=>'p.name',
        'files'=>'(SELECT COUNT(*) FROM file_action_items items WHERE items.batch_id=b.id)',
        'status'=>'b.state',
        'requested'=>"COALESCE(b.requested_at,'')",
        'completed'=>"COALESCE(b.completed_at,'')"
    ];

    /** @return array{rows:array,total:int,next:?int,previous:?int,limit:int} */
    public function page(
        ?int $profileId=null,?string $kind=null,?string $state=null,
        ?int $before=null,?int $after=null,
        string $sort='id',string $direction='desc'
    ):array {
        if($before!==null && $after!==null)
            throw new \InvalidArgumentException('Only one paging cursor is allowed.');
        if(!isset(self::SORTS[$sort]) || !in_array($direction,['asc','desc'],true))
            throw new \DomainException('Invalid file-action sort order.');
        $where=' FROM file_action_batches b JOIN scan_profiles p ON p.id=b.profile_id WHERE 1=1';
        $params=[];
        if($profileId!==null) {
            $where.=' AND b.profile_id=:profile';
            $params['profile']=$profileId;
        }
        if($kind!==null && $kind!=='') {
            $where.=' AND b.action_kind=:kind';
            $params['kind']=$kind;
        }
        if($state!==null && $state!=='') {
            $where.=' AND b.state=:state';
            $params['state']=$state;
        }
        $result=KeysetPage::fetch(
            $this->db,
            'b.id,b.profile_id,b.action_kind,b.state,b.requested_at,b.completed_at,'.
            'b.error_code,p.name AS profile_name,'.
            '(SELECT MIN(i.source_path) FROM file_action_items i WHERE i.batch_id=b.id) AS source_path,'.
            '(SELECT COUNT(*) FROM file_action_items i WHERE i.batch_id=b.id) AS item_count,'.
            "(SELECT COUNT(*) FROM file_action_items i WHERE i.batch_id=b.id AND ".
            "i.state IN ('completed','renamed','moved','linked','quarantined','restored','deleted')) AS successful_items,".
            "(SELECT COUNT(*) FROM file_action_items i WHERE i.batch_id=b.id AND ".
            "i.state='failed') AS failed_items",
            $where,$params,'b.id',self::SORTS[$sort],strtoupper($direction),
            self::PAGE_SIZE,$before,$after,in_array($sort,['id','files'],true)
        );
        $result['limit']=self::PAGE_SIZE;
        return $result;
    }

    /** Inspect every renamed/failed file instead of redirecting to the rename form. */
    public function renameDetails(int $id): array|false
    {
        $batch = $this->db->fetchAssociative(
            'SELECT b.*,p.name AS profile_name,u.username AS actor_name '.
            'FROM file_action_batches b JOIN scan_profiles p ON p.id=b.profile_id '.
            'LEFT JOIN users u ON u.id=b.actor_id '.
            "WHERE b.id=? AND b.action_kind='rename'",[$id]
        );
        if (!$batch) return false;
        $batch['items'] = $this->db->fetchAllAssociative(
            'SELECT id,file_entry_id,source_path,target_path,state,result_code,'.
            'expected_size,expected_sha256 FROM file_action_items '.
            'WHERE batch_id=? ORDER BY id',[$id]
        );
        return $batch;
    }
}
