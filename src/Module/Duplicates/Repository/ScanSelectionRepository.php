<?php
declare(strict_types=1);

namespace App\Module\Duplicates\Repository;

use Doctrine\DBAL\Connection;

/**
 * Navigation-only reads. Never uses historical scan paths for filesystem
 * actions; the action services independently require the current run.
 */
final class ScanSelectionRepository
{
    private const VISIBLE_RUNS = 75;

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function profiles():array
    {
        return $this->db->fetchAllAssociative(
            'SELECT p.id,p.name,r.id AS run_id,r.duplicate_groups '.
            'FROM scan_profiles p LEFT JOIN scan_runs r ON r.id='.
            "(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') ".
            'ORDER BY p.name,p.id'
        );
    }

    /** @return array{rows:list<array<string,mixed>>,more:bool} */
    public function runs(int $profileId,?int $before=null):array
    {
        $where="FROM scan_runs r WHERE r.profile_id=? AND r.state IN ('completed','archived')";
        $params=[$profileId];
        if($before!==null) {
            $where.=' AND r.id<?';
            $params[]=$before;
        }
        $rows=$this->db->fetchAllAssociative(
            'SELECT r.id,r.profile_id,r.state,r.started_at,r.finished_at,'.
            'r.duplicate_groups,r.files_indexed '.
            $where.' ORDER BY r.id DESC LIMIT '.(self::VISIBLE_RUNS+1),
            $params
        );
        $more=count($rows)>self::VISIBLE_RUNS;
        if($more) array_pop($rows);
        return ['rows'=>$rows,'more'=>$more];
    }

    /** @return array<string,mixed>|false */
    public function selectedRun(int $runId,?int $profileId):array|false
    {
        $run=$this->db->fetchAssociative(
            'SELECT r.id,r.profile_id,r.state,r.started_at,r.finished_at,'.
            'r.duplicate_groups,r.files_indexed,r.verification_method,r.verified_at,p.name AS profile_name '.
            'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id '.
            "WHERE r.id=? AND r.state IN ('completed','archived')",
            [$runId]
        );
        if(!$run || ($profileId!==null && $profileId!==(int)$run['profile_id']))
            return false;
        $current=(int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [(int)$run['profile_id']]
        );
        $run['is_historical']=$run['state']!=='completed' || $current!==$runId;
        return $run;
    }
}
