<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261123000800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Restore account disable/restore and independent configurable safe file-viewer settings';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD COLUMN disabled_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE viewer_settings ('.
            'setting_key VARCHAR(40) NOT NULL PRIMARY KEY,'.
            'setting_value TEXT NOT NULL,'.
            'updated_at DATETIME NOT NULL)');
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('Account history and saved viewer preferences must be preserved.');
    }
}
