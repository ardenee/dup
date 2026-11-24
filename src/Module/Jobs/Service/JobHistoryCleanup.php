<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Remove completed scan job logs and proven resolved filesystem job logs.
 * An action job becomes eligible only after its batch is fully restored (or
 * its rename is complete) AND the guarded action history has been retired.
 * Scan snapshots and the global audit log always remain.
 */
final class JobHistoryCleanup
{
    private const KINDS=['scan','rebuild-duplicates'];
    private const STATES=['completed','failed','cancelled'];
    public const DAYS=[30,90,180,365];

    public function __construct(private readonly Connection $db) {}

    public function inspect(int $id):array|false
    {
        $job=$this->db->fetchAssociative(
            'SELECT j.id,j.kind,j.state,j.active_profile_id,j.file_action_batch_id,'.
            'j.run_id,j.finished_at,j.history_prunable_at,p.name AS profile_name '.
            'FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id WHERE j.id=?',[$id]
        );
        if(!$job) return false;
        $job['events']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM job_events WHERE job_id=?',[$id]
        );
        $job['can_remove']=(in_array($job['kind'],self::KINDS,true) ||
            $job['history_prunable_at']!==null) &&
            in_array($job['state'],self::STATES,true) &&
            $job['active_profile_id']===null && $job['file_action_batch_id']===null;
        $job['blocked_reason']=$job['can_remove']?'':(
            !in_array($job['kind'],self::KINDS,true)?
            'The filesystem action is still recorded or recoverable. Restore the original files, then retire the resolved action history before removing its job log.':
            'The job is not finished or is still attached to an active profile operation.'
        );
        return $job;
    }

    public function remove(int $id,int $actorId,string $confirm):void
    {
        if($confirm!=='DELETE')
            throw new \DomainException('Type DELETE to remove this job log.');
        $this->db->transactional(function(Connection $db) use ($id,$actorId):void {
            if($db->getDatabasePlatform() instanceof AbstractMySQLPlatform)
                $db->fetchOne('SELECT id FROM jobs WHERE id=? FOR UPDATE',[$id]);
            $job=$this->inspect($id);
            if(!$job || !$job['can_remove'])
                throw new \DomainException($job['blocked_reason']??'Job not found.');
            $this->record($actorId,'JOB_HISTORY_REMOVED',[
                'job_id'=>$id,'run_id'=>$job['run_id'],
                'kind'=>$job['kind'],'events_removed'=>$job['events']
            ]);
            // FK job_events.job_id is ON DELETE CASCADE.
            $deleted=$db->executeStatement(
                "DELETE FROM jobs WHERE id=? AND (kind IN ('scan','rebuild-duplicates') ".
                "OR history_prunable_at IS NOT NULL) ".
                "AND state IN ('completed','failed','cancelled') AND active_profile_id IS NULL ".
                "AND file_action_batch_id IS NULL",[$id]
            );
            if($deleted!==1) throw new \DomainException('The job changed during cleanup.');
        });
    }

    public function estimate(int $days):int
    {
        if(!in_array($days,self::DAYS,true))
            throw new \DomainException('Choose a retention period.');
        return (int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE (kind IN ('scan','rebuild-duplicates') ".
            "OR history_prunable_at IS NOT NULL) ".
            "AND state IN ('completed','failed','cancelled') AND active_profile_id IS NULL ".
            "AND file_action_batch_id IS NULL AND finished_at<?",
            [gmdate('Y-m-d H:i:s',time()-$days*86400)]
        );
    }

    /** Delete up to 500 old, safe job logs per request. */
    public function prune(int $days,int $actorId,string $confirm):int
    {
        if($confirm!=='PRUNE')
            throw new \DomainException('Type PRUNE to remove old job logs.');
        $this->estimate($days); // validate bounded retention values
        return $this->db->transactional(function(Connection $db) use ($days,$actorId):int {
            $cutoff=gmdate('Y-m-d H:i:s',time()-$days*86400);
            $candidates=$db->fetchFirstColumn(
                "SELECT id FROM jobs WHERE (kind IN ('scan','rebuild-duplicates') ".
                "OR history_prunable_at IS NOT NULL) ".
                "AND state IN ('completed','failed','cancelled') AND active_profile_id IS NULL ".
                "AND file_action_batch_id IS NULL AND finished_at<? ORDER BY id LIMIT 500",
                [$cutoff]
            );
            $ids=[];
            foreach($candidates as $id) {
                if($db->getDatabasePlatform() instanceof AbstractMySQLPlatform)
                    $db->fetchOne('SELECT id FROM jobs WHERE id=? FOR UPDATE',[(int)$id]);
                $job=$this->inspect((int)$id);
                if($job && $job['can_remove'] && $job['finished_at']<$cutoff)
                    $ids[]=(int)$id;
            }
            if(!$ids) return 0;
            $placeholders=implode(',',array_fill(0,count($ids),'?'));
            $affected=$db->executeStatement('DELETE FROM jobs WHERE id IN ('.$placeholders.')',
                $ids);
            if($affected!==count($ids))
                throw new \DomainException('Job history changed during cleanup.');
            $this->record($actorId,'JOB_HISTORY_PRUNED',[
                'days'=>$days,'job_count'=>$affected,'first_job_id'=>min($ids),
                'last_job_id'=>max($ids)
            ]);
            return $affected;
        });
    }

    private function record(int $actorId,string $action,array $context):void
    {
        $this->db->insert('audit_events',[
            'actor_id'=>$actorId,'action'=>$action,'entity_type'=>'job_history',
            'entity_id'=>null,'correlation_id'=>bin2hex(random_bytes(16)),
            'outcome'=>'completed','context'=>json_encode($context,JSON_THROW_ON_ERROR),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }
}
