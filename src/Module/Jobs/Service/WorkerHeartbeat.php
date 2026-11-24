<?php
declare(strict_types=1);

namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;

/**
 * Distinguishes the optional process registration from active job progress.
 * A busy CLI process may remain inside one scan for many hours.
 */
final class WorkerHeartbeat
{
    private readonly string $id;
    private bool $registered=false;
    private int $lastTouch=0;

    public function __construct(private readonly Connection $db)
    {
        $this->id=bin2hex(random_bytes(16));
    }

    public function touch():void
    {
        $time=time();
        if($this->registered && $time-$this->lastTouch<5) return;
        $now=gmdate('Y-m-d H:i:s',$time);
        if($this->registered) {
            $changed=$this->db->executeStatement(
                'UPDATE worker_heartbeats SET updated_at=? WHERE id=?',[$now,$this->id]
            );
            if($changed===1) {
                $this->lastTouch=$time;
                return;
            }
            $this->registered=false;
        }
        $this->db->insert('worker_heartbeats',[
            'id'=>$this->id,
            'host_name'=>mb_substr((string)(gethostname()?:'host'),0,191),
            'process_id'=>(int)getmypid(),
            'os_family'=>PHP_OS_FAMILY,
            'updated_at'=>$now
        ]);
        $this->registered=true;
        $this->lastTouch=$time;
    }

    public function stop():void
    {
        if(!$this->registered) return;
        $this->db->delete('worker_heartbeats',['id'=>$this->id]);
        $this->registered=false;
        $this->lastTouch=0;
    }

    /** @return array<string,int|string|null> */
    public function report():array
    {
        $now=time();
        $registeredSince=gmdate('Y-m-d H:i:s',$now-90);
        $jobSince=gmdate('Y-m-d H:i:s',$now-180);
        $recoverSince=gmdate('Y-m-d H:i:s',$now-600);
        $process=$this->db->fetchAssociative(
            'SELECT COUNT(CASE WHEN updated_at>=? THEN 1 END) AS active,'.
            'MAX(updated_at) AS last_heartbeat FROM worker_heartbeats',
            [$registeredSince]
        );
        $jobs=$this->db->fetchAssociative(
            "SELECT SUM(CASE WHEN state IN ('running','cancelling') THEN 1 ELSE 0 END) AS running,".
            "SUM(CASE WHEN state IN ('running','cancelling') AND heartbeat_at>=? THEN 1 ELSE 0 END) AS responding,".
            "SUM(CASE WHEN state IN ('running','cancelling') AND ".
            "(heartbeat_at IS NULL OR heartbeat_at<?) THEN 1 ELSE 0 END) AS stale,".
            "SUM(CASE WHEN state IN ('running','cancelling') AND ".
            "(heartbeat_at IS NULL OR heartbeat_at<?) THEN 1 ELSE 0 END) AS recoverable,".
            "SUM(CASE WHEN state='queued' THEN 1 ELSE 0 END) AS queued,".
            "MAX(CASE WHEN state IN ('running','cancelling') THEN heartbeat_at END) AS last_active ".
            "FROM jobs WHERE state IN ('queued','running','cancelling')",[$jobSince,$jobSince,$recoverSince]
        );
        $active=(int)($process['active']??0);
        $responding=(int)($jobs['responding']??0);
        $stale=(int)($jobs['stale']??0);
        $queued=(int)($jobs['queued']??0);
        $state= $responding>0 ? 'working' :
            ($stale>0 ? 'stalled' :
            ($active>0 ? 'ready' :
            ($queued>0 ? 'waiting' : 'not_reporting')));
        return [
            'active'=>$active,
            'last_heartbeat'=>$process['last_heartbeat']??null,
            'jobs_running'=>(int)($jobs['running']??0),
            'jobs_responding'=>$responding,
            'jobs_stalled'=>$stale,
            'queued_jobs'=>$queued,
            'recovery_candidates'=>(int)($jobs['recoverable']??0),
            'last_job_heartbeat'=>$jobs['last_active']??null,
            'state'=>$state
        ];
    }
}
