<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;
final class Version20261124000500 extends AbstractMigration
{
    public function getDescription():string {return 'Per-profile automatic scan schedules';}
    public function up(Schema $schema):void
    {
        $this->addSql('CREATE TABLE scan_schedules ('.
            'profile_id INT NOT NULL PRIMARY KEY,'.
            'interval_minutes INT NOT NULL,'.
            'next_run_at DATETIME NOT NULL,'.
            'last_queued_at DATETIME DEFAULT NULL,'.
            'last_attempt_at DATETIME DEFAULT NULL,'.
            'last_error VARCHAR(200) DEFAULT NULL,'.
            'updated_at DATETIME NOT NULL,'.
            'FOREIGN KEY (profile_id) REFERENCES scan_profiles(id) ON DELETE CASCADE)');
        $this->addSql('CREATE INDEX idx_scan_schedules_due ON scan_schedules (next_run_at)');
    }
    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Saved automatic scan schedules are retained.');
    }
}
