<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;

/**
 * DSM: send a cancellation request to the package supervisor.
 *
 * The supervisor is the PARENT of the PHP worker and is the only process
 * allowed to signal/terminate it. Web PHP writes an authenticated request
 * to a private directory, never executes a shell command or kills a PID.
 * Applies to read-only SHA-256 verification ONLY.
 */
final class VerificationCancelSignal
{
    private readonly string $directory;
    private readonly bool $supported;

    public function __construct(
        private readonly Connection $db,
        private readonly JobDebugLog $debug,
        ?string $directory=null,
        ?bool $supported=null
    ) {
        $this->directory=$directory??dirname(__DIR__,4).'/var/worker-control';
        $this->supported=$supported??(
            PHP_OS_FAMILY==='Linux' &&
            is_file('/var/packages/dup/target/app/worker-loop.sh')
        );
    }

    /** @return array{sent:bool,message:string,pid?:int} */
    public function notify(int $jobId):array
    {
        if(!$this->supported)
            return ['sent'=>false,'message'=>'This host has no DSM supervisor signalling service. The cancellation request remains recorded for the worker.'];
        $job=$this->db->fetchAssociative(
            'SELECT kind,state,worker_token FROM jobs WHERE id=?',[$jobId]
        );
        if(!$job || $job['kind']!=='duplicate-verify' ||
            $job['state']!=='cancelling' || !$job['worker_token'])
            return ['sent'=>false,'message'=>'This job is not an active SHA-256 verification awaiting cancellation.'];

        $raw=$this->db->fetchOne(
            "SELECT context FROM job_events WHERE job_id=? AND code='WORKER_CLAIMED' ".
            'ORDER BY id DESC LIMIT 1',[$jobId]
        );
        $claimed=is_string($raw)?json_decode($raw,true):null;
        if(!is_array($claimed))
            return ['sent'=>false,'message'=>'Cannot find the original worker process identity. Use stalled-job recovery after confirming the process has stopped.'];

        $pid=filter_var($claimed['worker_pid']??null,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $host=$claimed['worker_host']??null;
        if($pid===false || !is_string($host) || $host!==(string)gethostname())
            return ['sent'=>false,'message'=>'The claimed worker process is not on this DSM host; no signal sent.'];
        if($pid===getmypid())
            return ['sent'=>false,'message'=>'Refusing to signal the current web process.'];

        $path=$this->directory.'/verify-'.$pid.'.request';
        try {
            if(!is_dir($this->directory) &&
                !@mkdir($this->directory,0700,true) &&
                !is_dir($this->directory))
                throw new \RuntimeException('Cannot create private supervisor control directory.');
            // Atomic create + rename prevents partial commands.
            $temp=@tempnam($this->directory,'cancel-');
            if($temp===false)throw new \RuntimeException('Cannot allocate private cancellation request.');
            try {
                if(@file_put_contents($temp,(string)$jobId."\n",LOCK_EX)===false)
                    throw new \RuntimeException('Cannot write private cancellation request.');
                @chmod($temp,0600);
                if(!@rename($temp,$path))
                    throw new \RuntimeException('Cannot publish cancellation request to supervisor.');
            } finally {
                if(is_file($temp))@unlink($temp);
            }
            try {
                $this->db->insert('job_events',[
                    'job_id'=>$jobId,
                    'severity'=>'warning',
                    'code'=>'VERIFICATION_SUPERVISOR_STOP_REQUESTED',
                    'context'=>json_encode([
                        'description'=>'The DSM supervisor was instructed to stop its own PHP verification worker.',
                        'worker_pid'=>$pid,
                        'grace_seconds'=>8,
                        'escalation'=>'TERM followed by KILL if the worker does not exit',
                        'source_files'=>'The request does not delete source files.',
                    ],JSON_THROW_ON_ERROR),
                    'created_at'=>gmdate('Y-m-d H:i:s')
                ]);
            } catch(\Throwable $logError) {
                $this->debug->write($jobId,'error','verification.cancel.event_write_failed',[
                    'message'=>$logError->getMessage()
                ]);
            }
            $this->debug->write($jobId,'warning','verification.cancel.signal_queued',[
                'supervisor_pid'=>$pid,
                'note'=>'DSM supervisor will signal its own child worker; no shell command is executed by Web Station.'
            ]);
            return ['sent'=>true,'message'=>'Stop request delivered to the DSM supervisor for worker PID '.
                $pid.'. It will attempt graceful cancellation and, if blocked, terminate that worker after an 8-second grace period.',
                'pid'=>$pid];
        } catch(\Throwable $e) {
            $this->debug->write($jobId,'error','verification.cancel.signal_failed',[
                'error'=>$e->getMessage(),'worker_pid'=>$pid,'path'=>$path
            ]);
            return ['sent'=>false,'message'=>'Cannot deliver supervisor cancellation: '.
                $e->getMessage().'. Use the stalled-job recovery action once the old worker is stopped.'];
        }
    }
}
