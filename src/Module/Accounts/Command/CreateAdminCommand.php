<?php
declare(strict_types=1);
namespace App\Module\Accounts\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'dup:admin:create', description: 'Create the first DUP administrator without putting a password on the command line')]
final class CreateAdminCommand extends Command
{
    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('username',InputArgument::REQUIRED,'Username');
        $this->addOption('password-stdin',null,InputOption::VALUE_NONE,
            'Read the password from stdin (for automated setup; never from argv)');
    }

    protected function execute(InputInterface $input,OutputInterface $output): int
    {
        $username=trim((string)$input->getArgument('username'));
        if(!preg_match('/^[a-zA-Z0-9_.-]{3,64}$/',$username)) {
            $output->writeln('<error>Username must be 3–64 letters, digits, dot, dash or underscore.</error>');
            return Command::FAILURE;
        }
        if($input->getOption('password-stdin')) {
            $password=trim((string)fgets(STDIN),"\r\n");
        } else {
            $question=new Question('New administrator password (not displayed): ');
            $question->setHidden(true);
            $question->setHiddenFallback(false);
            $password=(string)$this->getHelper('question')->ask($input,$output,$question);
        }
        if(strlen($password)<12 || strlen($password)>72) {
            $output->writeln('<error>Password must contain 12–72 bytes.</error>');
            return Command::FAILURE;
        }
        if(!$this->connection->createSchemaManager()->tablesExist(['users'])) {
            $output->writeln('<error>Run doctrine:migrations:migrate first.</error>');
            return Command::FAILURE;
        }
        if((int)$this->connection->fetchOne('SELECT COUNT(*) FROM users WHERE username=?',[$username])>0) {
            $output->writeln('<error>Username already exists.</error>');
            return Command::FAILURE;
        }
        $this->connection->insert('users',[
            'username'=>$username,
            'password_hash'=>password_hash($password,PASSWORD_DEFAULT),
            'roles'=>'["ROLE_ADMIN"]',
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
        $output->writeln('<info>Administrator created.</info>');
        return Command::SUCCESS;
    }
}
