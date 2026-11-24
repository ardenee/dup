<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Global preferences become independent per-profile choices on upgrade. */
final class Version20261124001000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store File Exists policy independently on each scan profile';
    }

    public function up(Schema $schema):void
    {
        $this->addSql("ALTER TABLE scan_profiles ADD COLUMN file_exists_policy VARCHAR(16) NOT NULL DEFAULT 'fail'");
        // Preserve the user's existing global preference for current profiles
        // once. All subsequently created profiles start at the safe default.
        $this->addSql("UPDATE scan_profiles SET file_exists_policy=(".
            "SELECT setting_value FROM action_settings WHERE setting_key='destination_exists') ".
            "WHERE EXISTS (SELECT 1 FROM action_settings WHERE setting_key='destination_exists' ".
            "AND setting_value IN ('fail','number','overwrite'))");
    }

    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Independent per-profile preferences cannot be collapsed safely.');
    }
}
