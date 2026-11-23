<?php
declare(strict_types=1);
namespace App\Module\System\Service;

use Doctrine\DBAL\Connection;
use App\Module\Jobs\Service\WorkerHeartbeat;

final class HealthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkerHeartbeat $workers
    ) {}

    public function report(): array
    {
        $checks = [
            'php' => PHP_VERSION,
            'required_extensions' => array_values(array_filter(
                ['pdo','mbstring','ctype','iconv','xml','json','openssl','intl'],
                fn(string $ext) => !extension_loaded($ext)
            )),
            'database' => 'unreachable',
            'schema' => 'not_installed',
            'worker' => 'not_configured',
            'worker_processes' => 0,
            'worker_last_heartbeat' => null,
            'host_os' => \App\Core\Setup\HostEnvironment::label(dirname(__DIR__,4)),
            'queued_jobs'=>0,
            'running_jobs'=>0,
            'failed_jobs_24h'=>0,
            'oldest_queued_at'=>null,
            'needs_review_actions'=>0,
            'stalled_jobs'=>0,
            'recent_failures'=>[],
        ];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = 'connected';
            $installed = $this->connection->createSchemaManager()->tablesExist(
                ['users','profile_types','scan_profiles','scan_runs','file_entries','jobs',
                    'job_events','duplicate_groups','file_action_batches','audit_events',
                    'worker_heartbeats']
            );
            $checks['schema'] = $installed ? 'installed' : 'not_installed';
            if($installed) {
                $state=$this->connection->fetchAssociative(
                    "SELECT COUNT(*) AS total, " .
                    "SUM(CASE WHEN state='queued' THEN 1 ELSE 0 END) AS queued, " .
                    "SUM(CASE WHEN state IN ('running','cancelling') THEN 1 ELSE 0 END) AS active " .
                    'FROM jobs'
                );
                $status=$this->workers->report();
                $checks['worker_processes']=$status['active'];
                $checks['worker_last_heartbeat']=$status['last_heartbeat'];
                $checks['worker']=$state['active'] > 0 ? 'processing' :
                    ($status['active']>0 ? 'ready' :
                    ($state['queued'] > 0 ? 'awaiting_cli_worker' :
                    ($state['total'] > 0 ? 'offline' : 'not_started')));
                $cutoff=gmdate('Y-m-d H:i:s',time()-120);
                $stalled=(int)$this->connection->fetchOne(
                    "SELECT COUNT(*) FROM jobs WHERE state IN ('running','cancelling') " .
                    'AND (heartbeat_at IS NULL OR heartbeat_at<?)',[$cutoff]
                );
                $checks['stalled_jobs']=$stalled;
                if($stalled>0) $checks['worker']='stalled';
                $checks['queued_jobs']=(int)$state['queued'];
                $checks['running_jobs']=(int)$state['active'];
                $checks['oldest_queued_at']=$this->connection->fetchOne(
                    "SELECT MIN(created_at) FROM jobs WHERE state='queued'"
                ) ?: null;
                $checks['failed_jobs_24h']=(int)$this->connection->fetchOne(
                    "SELECT COUNT(*) FROM jobs WHERE state='failed' AND finished_at>=?",
                    [gmdate('Y-m-d H:i:s',time()-86400)]
                );
                $checks['needs_review_actions']=(int)$this->connection->fetchOne(
                    "SELECT COUNT(*) FROM file_action_batches WHERE state='needs_review'"
                );
                $checks['recent_failures']=$this->connection->fetchAllAssociative(
                    "SELECT id,kind,error_code,finished_at FROM jobs WHERE state='failed' " .
                    "ORDER BY id DESC LIMIT 5"
                );
            }
        } catch(\Throwable $ex) {
            $checks['database'] = 'unreachable';
        }
        $ready = $checks['database'] === 'connected'
            && $checks['schema'] === 'installed'
            && $checks['required_extensions'] === [];
        return ['status' => $ready ? 'ready' : 'setup_required',
            'ready' => $ready, 'checks' => $checks];
    }
}
