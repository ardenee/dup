<?php
declare(strict_types=1);
namespace App\Module\System\Service;

use Doctrine\DBAL\Connection;
use App\Module\Jobs\Service\WorkerHeartbeat;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

final class HealthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly WorkerHeartbeat $workers
    ) {}

    /** Detailed deployment versions without shell commands or secrets. */
    private static function platformDetails():array
    {
        $root=dirname(__DIR__,4);
        $dsm=null;$os=PHP_OS;
        if(\App\Core\Setup\HostEnvironment::isSynology($root)) {
            $raw=@file_get_contents('/etc.defaults/VERSION');
            if(is_string($raw)) {
                $values=[];
                foreach(explode("\n",$raw) as $line) {
                    if(preg_match('/^(majorversion|minorversion|buildnumber|smallfixnumber)="?([^"\r\n]*)"?$/',
                        trim($line),$m))
                        $values[$m[1]]=$m[2];
                }
                if(isset($values['majorversion'],$values['minorversion']))
                    $dsm=$values['majorversion'].'.'.$values['minorversion'].
                        (isset($values['buildnumber'])?'-'.$values['buildnumber']:'').
                        (isset($values['smallfixnumber'])?' Update '.$values['smallfixnumber']:'');
            }
        } elseif(PHP_OS_FAMILY==='Linux' && is_readable('/etc/os-release')) {
            $raw=@file_get_contents('/etc/os-release');
            if(is_string($raw) && preg_match('/^PRETTY_NAME="?([^"\n]+)"?/m',$raw,$m))
                $os=$m[1];
        }
        return ['dsm_version'=>$dsm,'os_version'=>$os,
            'host_os'=>\App\Core\Setup\HostEnvironment::label($root)];
    }

    public function report(): array
    {
        $platform=self::platformDetails();
        $driver=(string)($this->connection->getParams()['driver']??'');
        $mysql=str_contains($driver,'mysql');
        $required=['pdo',($mysql?'pdo_mysql':'pdo_sqlite'),
            'mbstring','ctype','iconv','xml','dom','json','openssl','intl','hash'];
        $extensionChecks=[];
        foreach($required as $name)
            $extensionChecks[]=['name'=>$name,'loaded'=>extension_loaded($name),
                'version'=>extension_loaded($name)?phpversion($name)?:null:null];
        $checks = [
            'php' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'php_ini' => basename((string)(php_ini_loaded_file()?:'Not configured')),
            'php_memory_limit'=>ini_get('memory_limit')?:'Not set',
            'php_extensions'=>$extensionChecks,
            'database_name'=>'Unavailable',
            'database_engine'=>$mysql?'MySQL / MariaDB':'SQLite',
            'database_server_version'=>'Unavailable',
            'schema_version'=>'Unavailable',
            'schema_latest_available'=>'Unavailable',
            'schema_pending'=>null,
            'os_version'=>$platform['os_version'],
            'dsm_version'=>$platform['dsm_version'],
            'required_extensions'=>array_values(array_map(
                static fn(array $e):string=>$e['name'],
                array_filter($extensionChecks,static fn(array $e):bool=>!$e['loaded'])
            )),
            'database' => 'unreachable',
            'schema' => 'not_installed',
            'worker' => 'not_configured',
            'worker_processes' => 0,
            'worker_last_heartbeat' => null,
            'host_os' => $platform['host_os'],
            'queued_jobs'=>0,
            'running_jobs'=>0,
            'failed_jobs_24h'=>0,
            'oldest_queued_at'=>null,
            'needs_review_actions'=>0,
            'stalled_jobs'=>0,
            'scheduled_profiles'=>0,
            'schedules_with_errors'=>0,
            'overdue_schedules'=>0,
            'recent_failures'=>[],
        ];
        try {
            $this->connection->executeQuery('SELECT 1')->fetchOne();
            $checks['database'] = 'connected';
            if($mysql) {
                $checks['database_name']=(string)($this->connection->fetchOne('SELECT DATABASE()')?:'Unknown');
                try {
                    $checks['database_server_version']=(string)(
                        $this->connection->fetchOne('SELECT VERSION()')?:'Unavailable');
                } catch(\Throwable) {}
            } else {
                $name=(string)($this->connection->getParams()['path']??':memory:');
                $checks['database_name']=$name===':memory:'?'In-memory SQLite':basename($name);
                $checks['database_server_version']=(string)($this->connection->fetchOne(
                    'SELECT sqlite_version()')?:'Unavailable');
            }
            $available=glob(dirname(__DIR__,4).'/migrations/Version*.php')?:[];
            $latest=$available?basename((string)max($available),'.php'):null;
            $checks['schema_latest_available']=$latest??'Unavailable';
            if($this->connection->createSchemaManager()->tablesExist(['doctrine_migration_versions'])) {
                $applied=$this->connection->fetchFirstColumn(
                    'SELECT version FROM doctrine_migration_versions ORDER BY version DESC');
                $versions=array_map(static function(mixed $value):string {
                    $parts=explode('\\',(string)$value);
                    return (string)end($parts);
                },$applied);
                $checks['schema_version']=$versions[0]??'None applied';
                $checks['schema_pending']=count(array_filter($available,
                    static fn(string $path):bool=>!in_array(basename($path,'.php'),$versions,true)));
            }
            $installed = $this->connection->createSchemaManager()->tablesExist(
                ['users','profile_types','scan_profiles','scan_runs','file_entries','jobs',
                    'job_events','duplicate_groups','file_action_batches','audit_events',
                    'worker_heartbeats']
            );
            $checks['schema'] = $installed ? 'installed' : 'not_installed';
            if($installed) {
                if($this->connection->createSchemaManager()->tablesExist(['scan_schedules'])) {
                    $schedule=$this->connection->fetchAssociative(
                        'SELECT COUNT(*) AS scheduled,'.
                        'SUM(CASE WHEN s.last_error IS NOT NULL THEN 1 ELSE 0 END) AS failed,'.
                        'SUM(CASE WHEN s.next_run_at<? AND p.enabled=1 THEN 1 ELSE 0 END) AS overdue '.
                        'FROM scan_schedules s JOIN scan_profiles p ON p.id=s.profile_id',
                        [gmdate('Y-m-d H:i:s')]
                    );
                    $checks['scheduled_profiles']=(int)($schedule['scheduled']??0);
                    $checks['schedules_with_errors']=(int)($schedule['failed']??0);
                    $checks['overdue_schedules']=(int)($schedule['overdue']??0);
                }
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
            && $checks['required_extensions'] === []
            && ($checks['schema_pending']===null || $checks['schema_pending']===0);
        $status=$ready?'ready':($checks['schema_pending']>0?'upgrade_required':'setup_required');
        return ['status' => $status,
            'ready' => $ready, 'checks' => $checks];
    }
}
