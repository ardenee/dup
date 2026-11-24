<?php
declare(strict_types=1);
namespace App\Module\FileActions\Repository;

use Doctrine\DBAL\Connection;

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

    /** @return array{rows:array,total:int,next:?int,previous:?int,limit:int} */
    public function page(
        ?int $profileId = null, ?string $kind = null, ?string $state = null,
        ?int $before = null, ?int $after = null
    ): array {
        if ($before !== null && $after !== null)
            throw new \InvalidArgumentException('Only one paging cursor is allowed.');
        $conditions = [];
        $params = [];
        if ($profileId !== null) {
            $conditions[] = 'b.profile_id=?';
            $params[] = $profileId;
        }
        if ($kind !== null) {
            $conditions[] = 'b.action_kind=?';
            $params[] = $kind;
        }
        if ($state !== null) {
            $conditions[] = 'b.state=?';
            $params[] = $state;
        }
        $filter = $conditions ? ' WHERE '.implode(' AND ', $conditions) : '';
        $total = (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_batches b'.$filter, $params
        );
        $cursor = $conditions;
        $cursorParams = $params;
        if ($before !== null) {
            $cursor[] = 'b.id<?';
            $cursorParams[] = $before;
        }
        if ($after !== null) {
            $cursor[] = 'b.id>?';
            $cursorParams[] = $after;
        }
        $where = $cursor ? ' WHERE '.implode(' AND ', $cursor) : '';
        $direction = $after === null ? 'DESC' : 'ASC';
        $rows = $this->db->fetchAllAssociative(
            'SELECT b.id,b.profile_id,b.action_kind,b.state,b.requested_at,'.
            'b.completed_at,b.error_code,p.name AS profile_name,'.
            'MIN(i.source_path) AS source_path,COUNT(i.id) AS item_count,'.
            "SUM(CASE WHEN i.state IN ('completed','renamed','moved','linked','quarantined','restored') ".
            'THEN 1 ELSE 0 END) AS successful_items,'.
            "SUM(CASE WHEN i.state='failed' THEN 1 ELSE 0 END) AS failed_items ".
            'FROM file_action_batches b '.
            'JOIN scan_profiles p ON p.id=b.profile_id '.
            'LEFT JOIN file_action_items i ON i.batch_id=b.id'.$where.
            ' GROUP BY b.id,b.profile_id,b.action_kind,b.state,b.requested_at,'.
            'b.completed_at,b.error_code,p.name ORDER BY b.id '.$direction.
            ' LIMIT '.self::PAGE_SIZE,
            $cursorParams
        );
        if ($after !== null) $rows = array_reverse($rows);
        if (!$rows) {
            return ['rows'=>[], 'total'=>$total, 'next'=>null, 'previous'=>null, 'limit'=>self::PAGE_SIZE];
        }
        $newest = (int)$rows[0]['id'];
        $oldest = (int)$rows[count($rows)-1]['id'];
        // Probe with matching filters to avoid linking to empty pages.
        $probe = static function (string $operator, int $id) use ($filter,$params): array {
            return [$filter.($filter ? ' AND ' : ' WHERE ').'b.id'.$operator.'?', [...$params,$id]];
        };
        [$olderSql,$olderParams] = $probe('<',$oldest);
        [$newerSql,$newerParams] = $probe('>',$newest);
        $hasOlder = $this->db->fetchOne(
            'SELECT b.id FROM file_action_batches b'.$olderSql.' ORDER BY b.id DESC LIMIT 1',
            $olderParams
        ) !== false;
        $hasNewer = $this->db->fetchOne(
            'SELECT b.id FROM file_action_batches b'.$newerSql.' ORDER BY b.id ASC LIMIT 1',
            $newerParams
        ) !== false;
        return [
            'rows'=>$rows, 'total'=>$total,
            'next'=>$hasOlder ? $oldest : null,
            'previous'=>$hasNewer ? $newest : null,
            'limit'=>self::PAGE_SIZE
        ];
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
