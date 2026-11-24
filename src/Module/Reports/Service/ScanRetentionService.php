<?php
declare(strict_types=1);
namespace App\Module\Reports\Service;
use Doctrine\DBAL\Connection;

/**
 * Batch pruning of genuinely historical scan snapshots only.
 * Individual ScanHistoryCleanup guards are rechecked before every deletion.
 * No filesystem operation is ever performed.
 */
final class ScanRetentionService
{
    public const DAYS=[30,90,180,365];
    public function __construct(
        private readonly Connection $db,
        private readonly ScanHistoryCleanup $cleanup
    ) {}

    private function base(?int $profileId):array
    {
        $sql=" FROM scan_runs r WHERE r.state IN ('completed','archived','failed','cancelled') ".
            'AND COALESCE(r.finished_at,r.started_at)<? '.
            "AND NOT (r.state='completed' AND r.id=(".
                "SELECT MAX(current.id) FROM scan_runs current WHERE current.profile_id=r.profile_id ".
                "AND current.state='completed')) ".
            "AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.active_profile_id=r.profile_id ".
                "OR (j.run_id=r.id AND j.state NOT IN ('completed','failed','cancelled'))) ".
            'AND NOT EXISTS (SELECT 1 FROM file_action_items i JOIN file_entries f '.
                'ON f.id=i.file_entry_id WHERE f.run_id=r.id) '.
            'AND NOT EXISTS (SELECT 1 FROM file_action_items i JOIN file_entries f '.
                'ON f.id=i.survivor_file_entry_id WHERE f.run_id=r.id) ';
        $params=[];
        if($profileId!==null) {
            $sql.='AND r.profile_id=? ';
            $params[]=$profileId;
        }
        return [$sql,$params];
    }

    private function eligible(int $days,?int $profileId):array
    {
        if(!in_array($days,self::DAYS,true))
            throw new \DomainException('Choose a supported scan retention period.');
        [$base,$params]=$this->base($profileId);
        array_unshift($params,gmdate('Y-m-d H:i:s',time()-$days*86400));
        return [$base,$params];
    }

    public function estimate(int $days,?int $profileId=null):int
    {
        [$base,$params]=$this->eligible($days,$profileId);
        return (int)$this->db->fetchOne('SELECT COUNT(*)'.$base,$params);
    }

    /** Up to 50 safe historical runs per request; scan results only. */
    public function prune(int $days,?int $profileId,int $actorId,string $confirm):int
    {
        if($confirm!=='PRUNE SCANS')
            throw new \DomainException('Type PRUNE SCANS to approve cleanup.');
        [$base,$params]=$this->eligible($days,$profileId);
        $ids=$this->db->fetchFirstColumn(
            'SELECT r.id'.$base.'ORDER BY r.id LIMIT 50',$params
        );
        $removed=0;
        foreach($ids as $id) {
            try {
                $this->cleanup->remove((int)$id,$actorId,'DELETE');
                $removed++;
            } catch(\DomainException) {
                // A concurrent scan/action can change eligibility; individual
                // transactional guards always win over a stale preview.
            }
        }
        return $removed;
    }
}
