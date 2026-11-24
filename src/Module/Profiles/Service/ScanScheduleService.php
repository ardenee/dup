<?php
declare(strict_types=1);
namespace App\Module\Profiles\Service;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Polling-based schedules work with DSM, Windows services and Linux without
 * spawning a cron process from PHP-FPM. The CLI worker is the sole dispatcher.
 */
final class ScanScheduleService
{
    public const INTERVALS=[0,60,360,720,1440,10080];

    public function __construct(private readonly Connection $db,private readonly JobStore $jobs) {}

    public function save(int $profileId,int $minutes,int $actorId):void
    {
        if(!in_array($minutes,self::INTERVALS,true))
            throw new \DomainException('Choose a supported automatic scan interval.');
        $this->db->transactional(function(Connection $db) use ($profileId,$minutes,$actorId):void {
            if(!$db->fetchOne('SELECT id FROM scan_profiles WHERE id=?',[$profileId]))
                throw new \DomainException('Scan profile not found.');
            if($minutes===0) {
                $db->delete('scan_schedules',['profile_id'=>$profileId]);
            } else {
                $now=gmdate('Y-m-d H:i:s');
                $fields=[
                    'interval_minutes'=>$minutes,
                    'next_run_at'=>gmdate('Y-m-d H:i:s',time()+$minutes*60),
                    'updated_at'=>$now,'last_error'=>null
                ];
                if($db->update('scan_schedules',$fields,['profile_id'=>$profileId])===0 &&
                    !$db->fetchOne('SELECT profile_id FROM scan_schedules WHERE profile_id=?',[$profileId]))
                    $db->insert('scan_schedules',['profile_id'=>$profileId]+$fields);
            }
            $db->insert('audit_events',[
                'actor_id'=>$actorId,'action'=>'PROFILE_SCAN_SCHEDULE_CHANGED',
                'entity_type'=>'scan_profile','entity_id'=>(string)$profileId,
                'correlation_id'=>bin2hex(random_bytes(16)),
                'outcome'=>'completed','created_at'=>gmdate('Y-m-d H:i:s'),
                'context'=>json_encode(['interval_minutes'=>$minutes],JSON_THROW_ON_ERROR)
            ]);
        });
    }

    /** @return list<int> Job IDs successfully queued. */
    public function dispatchDue(int $limit=10):array
    {
        $queued=[];
        $now=gmdate('Y-m-d H:i:s');
        $rows=$this->db->fetchAllAssociative(
            'SELECT s.profile_id FROM scan_schedules s '.
            'JOIN scan_profiles p ON p.id=s.profile_id AND p.enabled=1 '.
            'WHERE s.next_run_at<=? ORDER BY s.next_run_at,s.profile_id LIMIT '.
            max(1,min(20,$limit)),[$now]
        );
        foreach($rows as $row) {
            $profileId=(int)$row['profile_id'];
            try {
                $job=$this->db->transactional(function(Connection $db) use ($profileId,$now):?int {
                    if($db->getDatabasePlatform() instanceof AbstractMySQLPlatform)
                        $db->fetchOne('SELECT id FROM scan_profiles WHERE id=? FOR UPDATE',[$profileId]);
                    $schedule=$db->fetchAssociative(
                        'SELECT s.interval_minutes,s.next_run_at,p.enabled FROM scan_schedules s '.
                        'JOIN scan_profiles p ON p.id=s.profile_id WHERE s.profile_id=?',
                        [$profileId]
                    );
                    if(!$schedule || !(bool)$schedule['enabled'] ||
                        $schedule['next_run_at']>$now) return null;
                    $interval=(int)$schedule['interval_minutes'];
                    if(!in_array($interval,self::INTERVALS,true) || $interval===0)
                        throw new \DomainException('Automatic scan interval is invalid.');
                    if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=?',[$profileId])) {
                        // A scan is already running; retry on a subsequent cycle.
                        return null;
                    }
                    $jobId=$this->jobs->queueScan($profileId);
                    $db->executeStatement(
                        'UPDATE scan_schedules SET last_queued_at=?,next_run_at=?,updated_at=?,'.
                        'last_attempt_at=?,last_error=NULL WHERE profile_id=? AND next_run_at<=?',
                        [$now,gmdate('Y-m-d H:i:s',time()+$interval*60),$now,$now,
                         $profileId,$now]
                    );
                    $this->jobs->event($jobId,'info','SCHEDULED_SCAN',[
                        'profile_id'=>$profileId,'interval_minutes'=>$interval
                    ]);
                    return $jobId;
                });
                if($job!==null) $queued[]=$job;
            } catch(\DomainException $ex) {
                // Visible error and bounded retry prevent a bad profile from
                // spinning in the worker loop. Do not overwrite newly edited schedules.
                $this->db->executeStatement(
                    'UPDATE scan_schedules SET last_error=?,last_attempt_at=?,next_run_at=? '.
                    'WHERE profile_id=? AND next_run_at<=?',
                    [mb_substr($ex->getMessage(),0,190),$now,
                     gmdate('Y-m-d H:i:s',time()+300),$profileId,$now]
                );
            }
        }
        return $queued;
    }
}
