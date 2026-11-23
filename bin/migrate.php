<?php
declare(strict_types=1);
/** CLI-only, non-destructive DUP database upgrade.
 * php bin/migrate.php --check    # read-only preview
 * php bin/migrate.php --apply    # back up accounts, then update schema
 */
if(PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once dirname(__DIR__) . '/runtime-paths.php';
require_once dirname(__DIR__) . '/database-migrations.php';
$command = $argv[1] ?? '';
if(!in_array($command, ['--check', '--apply'], true) || count($argv) !== 2)
{
    fwrite(STDERR, "Usage: php bin/migrate.php --check|--apply\n");
    exit(2);
}
$config = DupPrivateDir() . '/config.local.php';
if(!is_file($config)) $config = dirname(__DIR__) . '/config.local.php';
if(!is_file($config))
{
    fwrite(STDERR, "No existing DUP database configuration.\n");
    exit(2);
}
$settings = (static function(string $file): array {
    require $file;
    return [
        'sqlite' => (int)($dbfile ?? 0),
        'host' => (string)($dbhost ?? '127.0.0.1'),
        'port' => (string)($dbport ?? ''),
        'name' => (string)($dbname ?? 'dup'),
        'user' => (string)($dbuser ?? ''),
        'pass' => (string)($dbpass ?? ''),
    ];
})($config);
if($settings['sqlite'] !== 0)
{
    fwrite(STDERR, "MariaDB only; SQLite is unchanged.\n");
    exit(2);
}
try
{
    $dsn = 'mysql:host=' . $settings['host'] .
        ($settings['port'] !== '' ? ';port=' . $settings['port'] : '') .
        ';dbname=' . $settings['name'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $plan = DupMigrationPlan($pdo);
    echo "DUP migration 20261123_auth_and_logs\n";
    echo "Accounts: " . $plan['account_count'] . "\n";
    echo "Password column width: " . $plan['password_size'] . "\n";
    if($plan['steps'] === []) echo "Schema already up to date.\n";
    foreach($plan['steps'] as $step) echo " - " . $step . "\n";
    if($command === '--check')
    {
        echo "Dry-run: database unchanged.\n";
        exit(0);
    }
    $result = DupApplyMigrations($pdo, static function(string $message): void {
        echo $message . "\n";
    });
    echo "Verified password width: " . $result['password_size'] . "\n";
    exit(0);
}
catch(Throwable $ex)
{
    fwrite(STDERR, "Migration failed (" . get_class($ex) . "): " . $ex->getMessage() . "\n");
    exit(1);
}
