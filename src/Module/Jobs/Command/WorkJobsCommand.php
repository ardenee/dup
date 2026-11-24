<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;

use App\Module\Jobs\Service\JobStore;
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
        private readonly WorkerHeartbeat $heartbeat
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
                $job=$this->jobs->claimNext($jobId);
                if(!$job) break;
                $id=(int)$job['id'];
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
                    $output->writeln('<error>Job #'.$id.' failed: '.$ex->getMessage().'</error>');
                    $failed=true;
                }
                $state=(string)$this->db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id]);
                $output->writeln('Job #'.$id.' finished with state: '.$state);
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
            $this->heartbeat->stop();
        }
        if(!$loop) $output->writeln('Processed: '.$processed.', failures: '.($failed?'yes':'none'));
        return $failed?Command::FAILURE:Command::SUCCESS;
    }
}
