<?php
declare(strict_types=1);
namespace App\Module\Jobs\Command;

use App\Module\Jobs\Service\JobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'dup:jobs:cancel',description:'Request cancellation of a queued or running job')]
final class CancelJobCommand extends Command
{
    public function __construct(private readonly JobStore $store) {parent::__construct();}
    protected function configure(): void
    {
        $this->addArgument('job-id',InputArgument::REQUIRED,'Job ID');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $id=filter_var($input->getArgument('job-id'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if($id===false) return Command::INVALID;
        if(!$this->store->requestCancel($id)) {
            $output->writeln('<comment>Job is already finished or not found.</comment>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Cancellation accepted for job #'.$id.'.</info>');
        return Command::SUCCESS;
    }
}
