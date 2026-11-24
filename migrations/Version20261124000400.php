<?php
declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\IrreversibleMigration;

final class Version20261124000400 extends AbstractMigration
{
    public function getDescription():string
    {
        return 'Support paginated filtered jobs and event timelines';
    }

    public function up(Schema $schema):void
    {
        $this->addSql('CREATE INDEX idx_jobs_profile_state_id ON jobs (profile_id,state,id)');
        $this->addSql('CREATE INDEX idx_jobs_state_id ON jobs (state,id)');
        $this->addSql('CREATE INDEX idx_job_events_job_id_id ON job_events (job_id,id)');
    }

    public function down(Schema $schema):void
    {
        throw new IrreversibleMigration('Job history query indexes are retained.');
    }
}
