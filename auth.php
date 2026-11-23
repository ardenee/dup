<?php
declare(strict_types=1);

/**
 * DUP authentication and CSRF helpers.
 * Used by the web UI and login endpoint. Never authenticates CLI workers.
 */
require_once __DIR__ . '/runtime-paths.php';

function DupWebRequest(): bool
{
    return PHP_SAPI !== 'cli' && !empty($_SERVER['REMOTE_ADDR']);
}

function DupSessionStart(): void
{
    if(!DupWebRequest() || session_status() === PHP_SESSION_ACTIVE)
        return;

    if(headers_sent())
        throw new RuntimeException('Cannot start a secure session after output.');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '10800');
    session_save_path(DupPrivateSubdir('sessions'));
    session_name('DUPSESSID');

    $base = str_replace('\\', '/', dirname(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/'))));
    $path = '/' . trim($base, '/') . '/';
    if($path === '//') $path = '/';
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0, 'path' => $path, 'secure' => $secure,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
    if(!session_start())
        throw new RuntimeException('Session storage is not available.');
}

function DupCsrfToken(): string
{
    DupSessionStart();
    if(!isset($_SESSION['csrf']) || !is_string($_SESSION['csrf']))
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function DupRequirePostCsrf(): void
{
    if(!DupWebRequest() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST')
    {
        http_response_code(405);
        exit('POST required.');
    }
    DupSessionStart();
    $submitted = $_POST['csrf'] ?? '';
    $existing = $_SESSION['csrf'] ?? '';
    if(!is_string($submitted) || !is_string($existing) || $existing === '' ||
       !hash_equals($existing, $submitted))
    {
        http_response_code(403);
        exit('Invalid request token. Reload the page and try again.');
    }
}

function DupForgetSession(): void
{
    DupSessionStart();
    if(session_status() !== PHP_SESSION_ACTIVE)
        return;
    $_SESSION = [];
    if(ini_get('session.use_cookies'))
    {
        $options = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $options['path'],
            'secure' => $options['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
    session_destroy();
}

function DupSessionIdentity(PDO $pdo): ?array
{
    if(!DupWebRequest())
        return null;
    DupSessionStart();
    $id = filter_var($_SESSION['account_id'] ?? null, FILTER_VALIDATE_INT);
    $signature = $_SESSION['account_signature'] ?? '';
    $lastActivity = (int)($_SESSION['last_activity'] ?? 0);
    if(!$id || !is_string($signature) || $signature === '' ||
       $lastActivity <= 0 || time() - $lastActivity > 10800)
    {
        if($id || $signature !== '') DupForgetSession();
        return null;
    }

    $statement = $pdo->prepare('SELECT id, name, level, password FROM accounts WHERE id=:id LIMIT 1');
    $statement->execute([':id' => $id]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    if(!$account || !hash_equals(
        hash('sha256', (string)$account['password']), $signature))
    {
        DupForgetSession();
        return null;
    }
    $_SESSION['last_activity'] = time();
    return [
        'id' => (int)$account['id'],
        'name' => (string)$account['name'],
        'level' => (int)$account['level'],
    ];
}

function DupRequireAdmin(): void
{
    global $dblink;
    $user = ($dblink instanceof PDO) ? DupSessionIdentity($dblink) : null;
    if(!$user || $user['level'] !== 1)
    {
        http_response_code(403);
        exit('Administrator access required.');
    }
}

function DupVerifyPassword(string $plain, string $stored): bool
{
    if(preg_match('/^[0-9a-f]{32}$/i', $stored))
        return hash_equals(strtolower($stored), md5('sault**' . $plain));

    return password_verify($plain, $stored);
}

function DupLogin(PDO $pdo, string $name, string $password): bool
{
    DupSessionStart();
    $name = trim($name);
    if(strlen($name) < 1 || strlen($name) > 64 ||
       strlen($password) < 1 || strlen($password) > 256)
        return false;

    $statement = $pdo->prepare('SELECT id, name, level, password FROM accounts WHERE name=:name LIMIT 1');
    $statement->execute([':name' => $name]);
    $account = $statement->fetch(PDO::FETCH_ASSOC);
    $statement->closeCursor();
    if(!$account || !DupVerifyPassword($password, (string)$account['password']))
        return false;

    $stored = (string)$account['password'];
    if(preg_match('/^[0-9a-f]{32}$/i', $stored) || password_needs_rehash($stored, PASSWORD_DEFAULT))
    {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare('UPDATE accounts SET password=:hash WHERE id=:id AND password=:old');
        $update->execute([':hash' => $hash, ':id' => (int)$account['id'], ':old' => $stored]);
        if($update->rowCount() !== 1)
            return false;
        $stored = $hash;
    }

    session_regenerate_id(true);
    $_SESSION = [
        'account_id' => (int)$account['id'],
        'account_signature' => hash('sha256', $stored),
        'last_activity' => time(),
        'csrf' => bin2hex(random_bytes(32)),
    ];
    return true;
}

function DupHashNewPassword(string $password): string
{
    if(strlen($password) < 12 || strlen($password) > 72)
        throw new InvalidArgumentException('Password must be 12 to 72 bytes long.');
    return password_hash($password, PASSWORD_DEFAULT);
}
