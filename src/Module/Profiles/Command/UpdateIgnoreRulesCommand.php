<?php
declare(strict_types=1);
namespace App\Module\Profiles\Command;

use App\Module\Profiles\Service\IgnoreRuleService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name:'dup:settings:ignore',description:'Update ignored folder names and queue database-only result recalculations')]
final class UpdateIgnoreRulesCommand extends Command
{
    public function __construct(private readonly IgnoreRuleService $rules) {parent::__construct();}
    protected function configure(): void
    {
        $this->addOption('names',null,InputOption::VALUE_REQUIRED,
            'Comma-separated folder names; use empty string to clear all rules');
    }
    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $names=$input->getOption('names');
        if($names===null) {
            $output->writeln('<error>Provide --names, including --names="" to clear.</error>');
            return Command::INVALID;
        }
        try {
            $count=$this->rules->save((string)$names);
            $output->writeln('<info>Ignore rules saved. '.$count.' recalculation jobs queued.</info>');
            return Command::SUCCESS;
        } catch(\DomainException $ex) {
            $output->writeln('<error>'.$ex->getMessage().'</error>');
            return Command::FAILURE;
        }
    }
}
