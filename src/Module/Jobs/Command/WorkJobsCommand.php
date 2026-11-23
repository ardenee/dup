<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;

use App\Module\Jobs\Service\JobStore;
use App\Module\Scans\Service\ScanEngine;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'dup:jobs:work',description:'Process queued DUP scan jobs using a PHP CLI worker')]
final class WorkJobsCommand extends Command
{
    public function __construct(
        private readonly JobStore $jobs,
        private readonly ScanEngine $scanner,
        private readonly Connection $db
    ) {parent::__construct();}

    protected function configure(): void
    {
        $this->addOption('max-jobs',null,InputOption::VALUE_REQUIRED,
            'Maximum jobs to process in this invocation (cron-friendly)',1);
        $this->addOption('job',null,InputOption::VALUE_REQUIRED,'Process one specific queued job',null);
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $max=filter_var($input->getOption('max-jobs'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1,'max_range'=>100]]);
        $jobRaw=$input->getOption('job');
        $jobId=$jobRaw===null?null:filter_var($jobRaw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if($max===false || $jobId===false) {
            $output->writeln('<error>Invalid job limit or ID.</error>');
            return Command::INVALID;
        }
        $recovered=$this->jobs->recoverStalled(120);
        if($recovered) $output->writeln('<comment>Marked '.$recovered.' stale job(s) failed.</comment>');
        $processed=0;$errors=0;
        while($processed<$max) {
            $job=$this->jobs->claimNext($jobId);
            if(!$job) break;
            $id=(int)$job['id'];
            $output->writeln('<info>Running scan job #'.$id.'...</info>');
            try {
                $this->scanner->run($job);
            } catch(\Throwable $ex) {
                $output->writeln('<error>Job #'.$id.' failed: '.$ex->getMessage().'</error>');
            }
            $state=(string)$this->db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id]);
            $output->writeln('Job #'.$id.' finished with state: '.$state);
            if($state==='failed') $errors++;
            $processed++;
            if($jobId!==null) break;
        }
        $output->writeln('Processed: '.$processed.', failures: '.($errors>0?'yes':'none'));
        return $errors>0?Command::FAILURE:Command::SUCCESS;
    }
}
