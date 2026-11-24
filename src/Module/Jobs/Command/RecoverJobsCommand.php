<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;
use App\Module\Jobs\Service\JobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
#[AsCommand(name:'dup:jobs:recover',description:'Mark workers with expired heartbeats as stalled')]
final class RecoverJobsCommand extends Command
{
    public function __construct(private readonly JobStore $store) {parent::__construct();}
    protected function configure(): void
    {
        $this->addOption('after',null,InputOption::VALUE_REQUIRED,'Heartbeat expiry in seconds (default 10 minutes)',600);
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $seconds=filter_var($input->getOption('after'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>30]]);
        if($seconds===false) return Command::INVALID;
        $count=$this->store->recoverStalled($seconds);
        $output->writeln('Stalled jobs recovered: '.$count);
        return Command::SUCCESS;
    }
}
