<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000900 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Record approved overwritten destination and temporary replacement backup';
    }
    public function up(Schema $schema):void
    {
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN overwrite_target_size BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN overwrite_target_mtime BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN overwrite_backup_path TEXT NULL');
    }
    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Keep overwritten file recovery diagnostics for past operations.');
    }
}
