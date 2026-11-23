<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index scan run reporting, flagged file states and associated job events';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_file_entries_run_error ON file_entries (run_id,error_code,id)');
        $this->addSql('CREATE INDEX idx_jobs_run_kind ON jobs (run_id,kind)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Reporting indexes are retained to preserve safe production upgrades.');
    }
}
