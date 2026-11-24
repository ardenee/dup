<?php
declare(strict_types=1);
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000300 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Save safe file-exists policy for move and rename actions';
    }

    public function up(Schema $schema):void
    {
        $this->addSql('CREATE TABLE action_settings ('.
            'setting_key VARCHAR(40) NOT NULL PRIMARY KEY,'.
            'setting_value VARCHAR(40) NOT NULL,'.
            'updated_at DATETIME NOT NULL)');
    }

    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('File-action preferences are retained.');
    }
}
