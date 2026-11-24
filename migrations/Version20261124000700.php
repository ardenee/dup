<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Preserve the audit and action batch rows when an administrator removes a login. */
final class Version20261124000700 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Allow a deleted user login to be detached from stored file-action batches';
    }

    public function up(Schema $schema):void
    {
        $this->addSql('ALTER TABLE file_action_batches MODIFY actor_id INT DEFAULT NULL');
    }

    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Deleted account references cannot be reconstructed.');
    }
}
