<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audited reversible quarantine jobs with immutable file snapshots and expiry';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_action_batches ADD COLUMN expires_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_batches ADD COLUMN completed_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_batches ADD COLUMN error_code VARCHAR(100) DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN expected_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN expected_mtime BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN expected_sha256 VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN survivor_file_entry_id INTEGER DEFAULT NULL');
        $this->addSql('ALTER TABLE jobs ADD COLUMN file_action_batch_id INTEGER DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_jobs_file_action_batch ON jobs (file_action_batch_id)');
        $this->addSql('CREATE INDEX idx_file_action_batches_state ON file_action_batches (state, requested_at)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Recorded file actions must not be discarded by rollback.');
    }
}
