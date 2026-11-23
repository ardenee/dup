<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Persist verified symbolic-link targets for reversible duplicate replacement';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE file_action_items ADD COLUMN link_target_path TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Existing link audit and recovery records must be retained.');
    }
}
