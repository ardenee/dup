<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;

use App\Module\Jobs\Service\JobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service-manager hook: invoked only after wait(2) has reaped a crashed worker.
 * Never guesses liveness from a stale database timestamp.
 */
#[AsCommand(name:'dup:jobs:worker-exited',
    description:'Record unfinished jobs after a confirmed CLI worker process exit')]
final class WorkerExitedCommand extends Command
{
    public function __construct(private readonly JobStore $jobs)
    {
        parent::__construct();
    }

    protected function configure():void
    {
        $this->addOption('pid',null,InputOption::VALUE_REQUIRED,'Exited worker process ID');
        $this->addOption('status',null,InputOption::VALUE_REQUIRED,'Worker exit status (0–255)');
    }

    protected function execute(InputInterface $input,OutputInterface $output):int
    {
        $pid=filter_var($input->getOption('pid'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $status=filter_var($input->getOption('status'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0,'max_range'=>255]]);
        if($pid===false || $status===false) {
            $output->writeln('<error>Valid worker PID and exit status required.</error>');
            return Command::INVALID;
        }
        $host=(string)(gethostname()?:'unknown');
        $resolved=$this->jobs->markWorkerExited($pid,$status,$host);
        $output->writeln('Worker PID '.$pid.' exited with status '.$status.
            '; unfinished jobs resolved: '.$resolved);
        return Command::SUCCESS;
    }
}
