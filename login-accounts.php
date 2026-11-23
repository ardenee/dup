<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
{
    http_response_code(405);
    header('Allow: POST');
    exit;
}
DupRequirePostCsrf();

if(!is_string($_POST['user'] ?? null) || !is_string($_POST['password'] ?? null))
{
    http_response_code(400);
    exit('Invalid login request.');
}
$name = strtolower(trim($_POST['user']));
$password = $_POST['password'];
$addr = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
$rateKey = hash('sha256', $addr . ':' . $name);
$ratePath = DupPrivateSubdir('auth-rate') . '/' . $rateKey . '.json';
$rate = @fopen($ratePath, 'c+');
if($rate === false || !flock($rate, LOCK_EX))
{
    http_response_code(503);
    exit('Authentication service unavailable.');
}

$stored = json_decode(stream_get_contents($rate) ?: '{}', true);
$now = time();
if(!is_array($stored) || $now - (int)($stored['start'] ?? 0) > 900)
    $stored = ['start' => $now, 'attempts' => 0];

if((int)$stored['attempts'] >= 5)
{
    flock($rate, LOCK_UN);
    fclose($rate);
    http_response_code(429);
    header('Retry-After: 900');
    exit('Too many login attempts. Try again later.');
}

$success = false;
try
{
    $config = DupPrivateDir() . '/config.local.php';
    if(!is_file($config))
        $config = __DIR__ . '/config.local.php';
    if(!is_file($config))
        throw new RuntimeException('Database is not configured.');

    // Isolate local database variables from the global request namespace.
    $settings = (static function(string $file): array {
        require $file;
        return [
            'host' => (string)($dbhost ?? '127.0.0.1'),
            'port' => (string)($dbport ?? ''),
            'db' => (string)($dbname ?? 'dup'),
            'user' => (string)($dbuser ?? ''),
            'pass' => (string)($dbpass ?? ''),
            'sqlite' => (int)($dbfile ?? 0),
            'dir' => (string)($dbdir ?? ''),
        ];
    })($config);

    if($settings['sqlite'] === 0)
    {
        $dsn = 'mysql:host=' . $settings['host'] .
            ($settings['port'] !== '' ? ';port=' . $settings['port'] : '') .
            ';dbname=' . $settings['db'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $settings['user'], $settings['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    else
    {
        $pdo = new PDO('sqlite:' . $settings['dir'] . '/dup.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    $success = DupLogin($pdo, $name, $password);
}
catch(Throwable $ex)
{
    error_log('DUP login service error: ' . get_class($ex));
    flock($rate, LOCK_UN);
    fclose($rate);
    http_response_code(503);
    exit('Authentication service unavailable.');
}

$stored['attempts'] = $success ? 0 : ((int)$stored['attempts'] + 1);
$stored['start'] = $success ? $now : $stored['start'];
rewind($rate);
ftruncate($rate, 0);
fwrite($rate, json_encode($stored));
fflush($rate);
flock($rate, LOCK_UN);
fclose($rate);

if($success)
{
    // Discard the old client-side username/hash cookies.
    foreach(['uname', 'password'] as $cookie)
        setcookie($cookie, '', ['expires' => time() - 3600, 'path' => '/',
            'httponly' => true, 'samesite' => 'Strict']);
}

header('Location: ' . ($success ? 'index.php' : 'accounts-login.php?error=1'), true, 303);
exit;
