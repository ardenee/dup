<?php
declare(strict_types=1);
namespace DoctrineMigrations;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/** Detailed verification progress and last failure reason without log scraping. */
final class Version20261124001100 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Record byte-level SHA-256 verification progress and useful job diagnostics';
    }
    public function up(Schema $schema):void
    {
        $this->addSql('ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0');
        $this->addSql('ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL');
        $this->addSql('ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL');
    }
    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Keep diagnostic progress and failure explanations for historic jobs.');
    }
}
