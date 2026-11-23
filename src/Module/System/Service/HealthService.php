<?php
declare(strict_types=1);
namespace App\Module\System\Service;

use Doctrine\DBAL\Connection;

final class HealthService
{
    public function __construct(private readonly Connection $connection) {}

    public function report(): array
    {
        $checks = [
            'php' => PHP_VERSION,
            'required_extensions' => array_values(array_filter(
                ['pdo','mbstring','ctype','iconv','xml','json','openssl'],
                fn(string $ext) => !extension_loaded($ext)
            )),
            'database' => 'unreachable',
            'schema' => 'not_installed',
            'worker' => 'not_configured',
        ];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = 'connected';
            $installed = $this->connection->createSchemaManager()->tablesExist(
                ['users','profile_types','scan_profiles','scan_runs','file_entries','jobs']
            );
            $checks['schema'] = $installed ? 'installed' : 'not_installed';
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
