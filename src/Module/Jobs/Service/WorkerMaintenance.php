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
