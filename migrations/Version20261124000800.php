<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000800 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Optional SHA-256 verification for files in existing duplicate groups';
    }
    public function up(Schema $schema):void
    {
        $this->addSql('ALTER TABLE scan_runs ADD COLUMN verification_method VARCHAR(24) DEFAULT NULL');
        $this->addSql('ALTER TABLE scan_runs ADD COLUMN verified_at DATETIME DEFAULT NULL');
        $this->addSql('CREATE TABLE duplicate_verification_hashes ('.
            'job_id INT NOT NULL,file_entry_id INT NOT NULL,sha256 CHAR(64) NOT NULL,'.
            'PRIMARY KEY(job_id,file_entry_id),'.
            'FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE)');
    }
    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Keep verification history associated with scan runs.');
    }
}
