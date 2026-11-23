<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record when saved ignore-folder rules were applied to duplicate results';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_runs ADD COLUMN ignore_applied_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Ignore recalculation history is not automatically discarded.');
    }
}
