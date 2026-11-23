<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cross-platform worker heartbeat registry for idle and active services';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE worker_heartbeats (' .
            'id VARCHAR(64) NOT NULL PRIMARY KEY,' .
            'host_name VARCHAR(191) NOT NULL,' .
            'process_id INTEGER NOT NULL,' .
            'os_family VARCHAR(32) NOT NULL,' .
            'updated_at DATETIME NOT NULL' .
            ')');
        $this->addSql('CREATE INDEX idx_workers_updated ON worker_heartbeats (updated_at)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Worker status history is not dropped on downgrade.');
    }
}
