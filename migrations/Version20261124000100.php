<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record symlink metadata safely, and index file-action history across scan snapshots';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_entries ADD COLUMN link_target TEXT DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_file_action_items_history ON file_action_items (file_entry_id, id)');
        $this->addSql('CREATE INDEX idx_file_action_batch_history ON file_action_batches (profile_id, action_kind)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Historical action records and indexed link targets must be preserved.');
    }
}
