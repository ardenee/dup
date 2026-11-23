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
            'host_os' => PHP_OS_FAMILY,
        ];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = 'connected';
            $installed = $this->connection->createSchemaManager()->tablesExist(
                ['users','profile_types','scan_profiles','scan_runs','file_entries','jobs','worker_heartbeats']
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
                if($stalled>0) $checks['worker']='stalled';
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
