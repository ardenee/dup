<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use App\Module\Duplicates\Service\DuplicateVerificationService;
use Doctrine\DBAL\Connection;

/**
 * Explicit administrator maintenance. Status checks are read-only; recovery
 * is never performed just because a worker registration looks old.
 */
final class WorkerMaintenance
{
    public function __construct(
        private readonly Connection $db,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly JobStore $jobs,
        private readonly DuplicateVerificationService $verification
    ) {}

    public function recover(int $actorId):int
    {
        $status=$this->heartbeat->report();
        if($status['active']>0 || $status['jobs_responding']>0)
            throw new \DomainException(
                'A worker or job is responding. Recovery is disabled while work is active.'
            );
        if($status['recovery_candidates']<1)
            throw new \DomainException(
                'No unfinished job has been without a heartbeat for at least 10 minutes.'
            );
        $recovered=$this->jobs->recoverStalled(600);
        if($recovered>0) {
            $this->db->insert('audit_events',[
                'actor_id'=>$actorId,
                'action'=>'STALE_JOBS_RECOVERED',
                'entity_type'=>'jobs',
                'entity_id'=>null,
                'correlation_id'=>bin2hex(random_bytes(16)),
                'outcome'=>'completed',
                'context'=>json_encode(['count'=>$recovered,'timeout_seconds'=>600],
                    JSON_THROW_ON_ERROR),
                'created_at'=>gmdate('Y-m-d H:i:s')
            ]);
        }
        return $recovered;
    }

    /**
     * Only the process that originally claimed THIS job can veto resolution.
     * New idle workers and workers processing other jobs must not prevent
     * cleanup of an unrelated abandoned lease.
     *
     * Older CLAIMED events may lack worker identity; absence is not proof
     * the process exited, which is why the admin confirmation remains.
     */
    public function ownerActive(int $jobId):bool
    {
        $context=$this->db->fetchOne(
            "SELECT context FROM job_events WHERE job_id=? AND code='WORKER_CLAIMED' ".
            'ORDER BY id DESC LIMIT 1',[$jobId]
        );
        if(!is_string($context)) return false;
        $owner=json_decode($context,true);
        if(!is_array($owner)) return false;
        $pid=filter_var($owner['worker_pid']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $host=$owner['worker_host']??null;
        if($pid===false || !is_string($host) || $host==='') return false;
        // Process registry follows the worker's host and PID, avoiding
        // false positives from another machine with the same PID.
        return (int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM worker_heartbeats WHERE host_name=? AND process_id=? '.
            'AND updated_at>=?',
            [$host,$pid,gmdate('Y-m-d H:i:s',time()-90)]
        )>0;
    }

    /**
     * Resolve ONE old scan/verification cancellation without changing other
     * jobs or running filesystem actions. This is not a process kill.
     */
    public function resolveOne(int $jobId,int $actorId):string
    {
        $job=$this->db->fetchAssociative(
            'SELECT id,kind,state,heartbeat_at FROM jobs WHERE id=?',[$jobId]
        );
        if(!$job) throw new \DomainException('Job does not exist.');
        if(!in_array($job['kind'],['scan','rebuild-duplicates','duplicate-verify'],true))
            throw new \DomainException(
                'A running file action cannot be resolved here. Review its recovery history instead.'
            );
        if(!in_array($job['state'],['running','cancelling'],true))
            throw new \DomainException('Job is already finished. Refresh to view its status.');
        if($job['heartbeat_at']!==null &&
            $job['heartbeat_at']>=gmdate('Y-m-d H:i:s',time()-600))
            throw new \DomainException(
                'The job heartbeat is less than 10 minutes old. '.
                'Wait for its cancellation checkpoint; refresh to check progress.'
            );
        if($this->ownerActive($jobId))
            throw new \DomainException(
                'The worker that claimed this job is still responding. '.
                'Wait for cancellation or stop that process before resolving the job.'
            );
        $changed=$this->jobs->recoverStalled(600,$jobId);
        if($changed!==1)
            throw new \DomainException(
                'The job changed while resolving it. Refresh and check its current state.'
            );
        $state=$job['state']==='cancelling'?'cancelled':'failed';
        $this->db->insert('audit_events',[
            'actor_id'=>$actorId,
            'action'=>'STALLED_JOB_RESOLVED',
            'entity_type'=>'job','entity_id'=>(string)$jobId,
            'correlation_id'=>bin2hex(random_bytes(16)),
            'outcome'=>$state,
            'context'=>json_encode([
                'job_kind'=>$job['kind'],'prior_state'=>$job['state'],
                'resolved_state'=>$state,'last_heartbeat'=>$job['heartbeat_at'],
                'timeout_seconds'=>600
            ],JSON_THROW_ON_ERROR),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
        return $state;
    }

    /** Creates a new job; does not resume or overwrite the historical job. */
    public function retry(int $jobId,int $actorId):int
    {
        $previous=$this->db->fetchAssociative(
            'SELECT id,kind,state,profile_id FROM jobs WHERE id=?',[$jobId]
        );
        if(!$previous || !in_array($previous['state'],['failed','cancelled'],true))
            throw new \DomainException('Only failed or cancelled scan jobs can be retried.');
        $profileId=(int)$previous['profile_id'];
        if($profileId<1)
            throw new \DomainException('The original scan profile is unavailable.');
        $newId=match($previous['kind']) {
            'scan'=>$this->jobs->queueScan($profileId),
            'duplicate-verify'=>$this->verification->queue($profileId,$actorId),
            default=>throw new \DomainException(
                'This operation requires review of its original file-action history.'
            )
        };
        $this->db->insert('audit_events',[
            'actor_id'=>$actorId,'action'=>'JOB_RETRY_QUEUED',
            'entity_type'=>'job','entity_id'=>(string)$jobId,
            'correlation_id'=>bin2hex(random_bytes(16)),
            'outcome'=>'queued',
            'context'=>json_encode([
                'new_job_id'=>$newId,'profile_id'=>$profileId,
                'previous_kind'=>$previous['kind']
            ],JSON_THROW_ON_ERROR),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
        return $newId;
    }
}
