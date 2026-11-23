<?php
declare(strict_types=1);
namespace App\Module\Scans\Command;

use App\Module\Jobs\Service\JobStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'dup:scan:queue',description:'Queue a new scan from a configured profile')]
final class QueueScanCommand extends Command
{
    public function __construct(private readonly JobStore $store) {parent::__construct();}
    protected function configure(): void
    {
        $this->addArgument('profile-id',InputArgument::REQUIRED,'Scan profile ID');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $id=filter_var($input->getArgument('profile-id'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if($id===false) {
            $output->writeln('<error>Profile ID must be a positive integer.</error>');
            return Command::INVALID;
        }
        try {
            $job=$this->store->queueScan($id);
        } catch(\DomainException $ex) {
            $output->writeln('<error>'.$ex->getMessage().'</error>');
            return Command::FAILURE;
        }
        $output->writeln('<info>Scan queued as job #'.$job.'.</info>');
        return Command::SUCCESS;
    }
}
