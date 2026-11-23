<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

/**
 * Queue exclusivity is enforced by a unique nullable profile slot.
 * A terminal job frees the slot. The run captures immutable scan settings.
 */
final class Version20261123000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Capture scan settings, guarantee one active scan per profile, seed ignored names';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_runs ADD COLUMN settings_json TEXT');
        $this->addSql('ALTER TABLE jobs ADD COLUMN active_profile_id INTEGER DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_jobs_active_profile ON jobs (active_profile_id)');
        $this->addSql('INSERT INTO profile_ignore_rules (profile_id,folder_name,enabled) VALUES (NULL, ?, 1)', ['@eaDir']);
        $this->addSql('INSERT INTO profile_ignore_rules (profile_id,folder_name,enabled) VALUES (NULL, ?, 1)', ['#recycle']);
    }

    public function down(Schema $schema): void
    {
        throw new IrreversibleMigration('DUP scan history is never dropped during an automatic downgrade.');
    }
}
