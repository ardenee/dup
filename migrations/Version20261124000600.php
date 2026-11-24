<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000600 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Mark resolved file-action jobs as eligible for later history cleanup';
    }

    public function up(Schema $schema):void
    {
        $this->addSql('ALTER TABLE jobs ADD COLUMN history_prunable_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_jobs_history_prunable ON jobs (history_prunable_at)');
    }

    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration(
            'Preserve the proof that linked filesystem actions were fully resolved.'
        );
    }
}
