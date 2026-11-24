<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\JobDebugLog;
use App\Module\Jobs\Service\WorkerFailureCode;
use App\Module\Jobs\Service\ScanCancelled;
use App\Core\Diagnostics\DiagnosticFailure;
use App\Module\Duplicates\Service\VerificationFailureCode;
use App\Module\Profiles\Service\ScanScheduleService;
use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Scans\Service\ScanEngine;
use App\Module\Duplicates\Service\IgnoreRebuildWorker;
use App\Module\Duplicates\Service\DuplicateVerificationWorker;
use App\Module\FileActions\Service\FileActionWorker;
use App\Module\FileActions\Service\FileMoveWorker;
use App\Module\FileActions\Service\FileLinkWorker;
use App\Module\FileActions\Service\BulkDuplicateWorker;
use App\Module\FileActions\Service\FileDeletionWorker;
use App\Module\Renaming\Service\RenameWorker;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'dup:jobs:work',description:'Process DUP jobs (once for schedulers or continuously for system services)')]
final class WorkJobsCommand extends Command
{
    private bool $stopping=false;

    public function __construct(
        private readonly JobStore $jobs,
        private readonly ScanScheduleService $schedules,
        private readonly ScanEngine $scanner,
        private readonly IgnoreRebuildWorker $rebuild,
        private readonly DuplicateVerificationWorker $verification,
        private readonly FileActionWorker $fileActions,
        private readonly FileMoveWorker $moves,
        private readonly FileLinkWorker $links,
        private readonly BulkDuplicateWorker $bulkDuplicates,
        private readonly FileDeletionWorker $deletions,
        private readonly RenameWorker $renames,
        private readonly Connection $db,
        private readonly WorkerHeartbeat $heartbeat,
        private readonly JobDebugLog $debug
    ) {parent::__construct();}

    protected function configure(): void
    {
        $this->addOption('max-jobs',null,InputOption::VALUE_REQUIRED,
            'Jobs to process per cycle (once by default)',1);
        $this->addOption('job',null,InputOption::VALUE_REQUIRED,'One specific queued job ID',null);
        $this->addOption('loop',null,InputOption::VALUE_NONE,
            'Keep processing jobs, suitable for Windows/Linux/DSM service managers');
        $this->addOption('idle-sleep',null,InputOption::VALUE_REQUIRED,
            'Seconds to wait between loop cycles (1-60)',5);
        $this->addOption('max-cycles',null,InputOption::VALUE_REQUIRED,
            'Optional finite cycle count for health checks; 0 means unlimited',0);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $max=filter_var($input->getOption('max-jobs'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>100]]);
        $raw=$input->getOption('job');
        $jobId=$raw===null?null:filter_var($raw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $delay=filter_var($input->getOption('idle-sleep'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>60]]);
        $maxCycles=filter_var($input->getOption('max-cycles'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0,'max_range'=>100000]]);
        $loop=(bool)$input->getOption('loop');
        if($max===false || $jobId===false || $delay===false || $maxCycles===false ||
           (!$loop && $maxCycles!==0) || ($loop && $jobId!==null)) {
            $output->writeln('<error>Invalid job worker options.</error>');
            return Command::INVALID;
        }
        $this->stopping=false;
        $bootLogWritable=$this->debug->write(0,'info','worker.boot',[
            'php_version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'memory_limit'=>ini_get('memory_limit'),
            'php_ini'=>php_ini_loaded_file(),'extensions'=>[
                'pdo_mysql'=>extension_loaded('pdo_mysql'),
                'mbstring'=>extension_loaded('mbstring'),
                'hash'=>extension_loaded('hash')
            ],'loop'=>$loop
        ]);
        if(!$bootLogWritable) {
            $message='DUP CLI DEBUG LOG UNAVAILABLE: '.
                ($this->debug->lastWriteError()??'write verification failed').
                '. Worker refuses to claim jobs without independent diagnostics.';
            $output->writeln('<error>'.$message.'</error>');
            error_log($message);
            return Command::FAILURE;
        }
        $activeId=null;
        register_shutdown_function(function()use(&$activeId):void {
            $last=error_get_last();
            if($activeId!==null && is_array($last) &&
               in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) {
                $this->debug->write($activeId,'error','worker.php_fatal',[
                    'error_code'=>'DUP-WORKER-002',
                    'operation'=>'PHP fatal error at worker shutdown',
                    'message'=>$last['message'],'file'=>$last['file'],
                    'line'=>$last['line']
                ]);
            }
        });
        if($loop && function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM,fn() => $this->stopping=true);
            pcntl_signal(SIGINT,fn() => $this->stopping=true);
        }
        $cycles=0;
        $failed=false;
        $this->jobs->registerWorkerHeartbeat($this->heartbeat);
        try {
        do {
            $this->heartbeat->touch();
            $scheduled=$this->schedules->dispatchDue();
            if($scheduled) $output->writeln('<info>Scheduled scan jobs: '.implode(',',$scheduled).'</info>');
            // Do not automatically invalidate running filesystem or hashing
            // jobs solely because their database heartbeat is old. A worker
            // may be blocked inside a file read, native I/O or a transaction.
            // Explicit maintenance can recover such jobs after inspection.
            $processed=0;
            while($processed<$max && !$this->stopping) {
                $claimStart=microtime(true);
                $this->debug->write(0,'debug','worker.claim.db.start',[
                    'requested_job_id'=>$jobId
                ]);
                $job=$this->jobs->claimNext($jobId);
                $this->debug->write(0,'debug','worker.claim.db.end',[
                    'elapsed_ms'=>round((microtime(true)-$claimStart)*1000,2),
                    'job_id'=>$job['id']??null
                ]);
                if(!$job) break;
                $id=(int)$job['id'];
                $activeId=$id;
                $this->debug->write($id,'info','worker.job.claimed',[
                    'kind'=>$job['kind'],'run_id'=>$job['run_id']??null,
                    'profile_id'=>$job['profile_id']??null
                ]);
                $output->writeln('<info>Running '.$job['kind'].' job #'.$id.'...</info>');
                try {
                    match($job['kind']) {
                        'scan' => $this->scanner->run($job),
                        'rebuild-duplicates' => $this->rebuild->run($job),
                        'duplicate-verify' => $this->verification->run($job),
                        'file-action', 'file-restore' => $this->fileActions->run($job),
                        'file-rename' => $this->renames->run($job),
                        'file-move','file-move-restore' => $this->moves->run($job),
                        'file-link','file-unlink' => $this->links->run($job),
                        'bulk-link','bulk-quarantine','bulk-restore','bulk-unlink' => $this->bulkDuplicates->run($job),
                        'file-delete' => $this->deletions->run($job),
                        default => throw new \LogicException('Unknown job type.')
                    };
                } catch(\Throwable $ex) {
                    $errorCode=$ex instanceof DiagnosticFailure?
                        $ex->errorCode():WorkerFailureCode::unhandled((string)$job['kind']);
                    $this->debug->write($id,'error','worker.job.exception',[
                        'error_code'=>$errorCode,
                        'operation'=>VerificationFailureCode::explain($errorCode)??'Outer worker exception',
                        'exception'=>get_class($ex),'message'=>$ex->getMessage(),
                        'file'=>$ex->getFile(),'line'=>$ex->getLine(),
                        'trace'=>$ex->getTraceAsString()
                    ]);
                    // A worker exception must not strand the lease as running
                    // on generic Windows/Linux services without a DSM supervisor.
                    // stop() is guarded by the original worker token, so a
                    // genuinely recovered/stolen lease is never overwritten.
                    try {
                        $this->jobs->stop($id,(string)$job['worker_token'],
                            $ex instanceof ScanCancelled?'cancelled':'failed',$errorCode,[
                                'reason'=>mb_substr($ex->getMessage(),0,1800),
                                'exception_type'=>get_class($ex),
                                'source_file'=>$ex->getFile(),
                                'source_line'=>$ex->getLine(),
                                'original_exception_code'=>$errorCode
                            ]);
                    } catch(\Throwable $finishError) {
                        // Never claim another job after the terminal-state
                        // write failed: recovery must inspect the old lease.
                        $this->stopping=true;
                        $this->debug->write($id,'error','worker.job.finalization_failed',[
                            'error_code'=>$errorCode,
                            'exception'=>get_class($finishError),
                            'message'=>$finishError->getMessage(),
                            'original_exception'=>get_class($ex)
                        ]);
                    }
                    $output->writeln('<error>Job #'.$id.' failed: '.$ex->getMessage().'</error>');
                    $failed=true;
                }
                $state=(string)$this->db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id]);
                $this->debug->write($id,'info','worker.job.finished',['state'=>$state]);
                $output->writeln('Job #'.$id.' finished with state: '.$state);
                if($job['kind']==='duplicate-verify' && $state!=='completed') {
                    // A cancellation signal can race with an I/O exception.
                    // Leave the worker loop after ANY unfinished/failed
                    // verification so a delayed supervisor stop never kills
                    // an unrelated next job running in this same PID.
                    $this->debug->write($id,'info','worker.exit.after_verification_failure_or_cancel',[
                        'final_state'=>$state,
                        'note'=>'Exit before claiming another job; the DSM supervisor may still be stopping this worker.'
                    ]);
                    $this->stopping=true;
                }
                $activeId=null;
                if($state==='failed') $failed=true;
                $processed++;
                if($jobId!==null) break;
            }
            $cycles++;
            if(!$loop || $this->stopping || ($maxCycles!==0 && $cycles>=$maxCycles)) break;
            // Do not spin when the queue is empty, and provide fair pacing
            // even when another service continuously submits work.
            sleep($processed===0?$delay:1);
        } while(!$this->stopping);
        } finally {
            $this->debug->write(0,'info','worker.shutdown',[
                'stop_requested'=>$this->stopping,'active_job'=>$activeId
            ]);
            $this->heartbeat->stop();
        }
        if(!$loop) $output->writeln('Processed: '.$processed.', failures: '.($failed?'yes':'none'));
        return $failed?Command::FAILURE:Command::SUCCESS;
    }
}
