<?php
declare(strict_types=1);

use App\Core\Setup\Installer;

require dirname(__DIR__).'/vendor/autoload.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, private');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('Content-Type: text/html; charset=UTF-8');

$https=($_SERVER['HTTPS']??'')==='on' || ($_SERVER['SERVER_PORT']??'')==='443';
$remote=(string)($_SERVER['REMOTE_ADDR']??'');
$local=in_array($remote,['127.0.0.1','::1'],true);
if(!$https && !$local) {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>HTTPS required</title>'.
         '<main><h1>HTTPS required</h1><p>DUP setup cannot transmit database or administrator credentials over an unencrypted connection. Configure HTTPS or use localhost.</p></main></html>';
    exit;
}
session_set_cookie_params([
    'lifetime'=>0,
    'path'=>rtrim(str_replace('\\','/',dirname((string)($_SERVER['SCRIPT_NAME']??'/'))),'/').'/',
    'secure'=>$https,'httponly'=>true,'samesite'=>'Strict'
]);
session_start();

$installer=new Installer(dirname(__DIR__));
$errors=[];$complete=false;$preflight=[];
$installed=$installer->installed();
$existing=$installer->hasPrivateConfig();
try {
    if(!$installed) {
        $installer->ensureToken();
        $preflight=$installer->requirements();
    }
} catch(\Throwable) {
    $errors[]='The private application storage is unavailable. Check directory ownership and write permissions.';
}

if(!isset($_SESSION['dup_setup_csrf'])) $_SESSION['dup_setup_csrf']=bin2hex(random_bytes(32));
if(!$installed && !$existing && !$errors && ($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
    $csrf=$_POST['_csrf']??null;
    $validCsrf=is_string($csrf) && hash_equals((string)$_SESSION['dup_setup_csrf'],$csrf);
    $attempts=(int)($_SESSION['dup_setup_attempts']??0);
    $since=(int)($_SESSION['dup_setup_attempts_since']??0);
    if(time()-$since>900) {
        $_SESSION['dup_setup_attempts']=0;
        $_SESSION['dup_setup_attempts_since']=time();
        $attempts=0;
    }
    if($attempts>=10) {
        http_response_code(429);
        $errors[]='Too many setup attempts. Close the browser and contact the server administrator.';
    } elseif(!$validCsrf) {
        http_response_code(403);
        $errors[]='The setup form expired. Refresh the page and try again.';
    } else {
        $_SESSION['dup_setup_attempts']=$attempts+1;
        $fields=['setup_code','driver','db_host','db_port','db_name','db_user','db_password',
                 'admin_user','admin_password','confirm_password','roots'];
        $input=[];
        foreach($fields as $field) {
            $value=$_POST[$field]??'';
            $input[$field]=is_string($value)?$value:'';
        }
        try {
            $installer->install($input);
            $complete=true;
            $installed=true;
            $existing=true;
            unset($_SESSION['dup_setup_csrf'],$_SESSION['dup_setup_attempts']);
            session_regenerate_id(true);
        } catch(\DomainException $ex) {
            $errors[]=$ex->getMessage();
        } catch(\Throwable) {
            http_response_code(500);
            $errors[]='Setup could not finish. Check private file permissions, the database permissions, and the server PHP error log.';
        }
    }
}

function show(string|int|float|null $value): string
{
    return htmlspecialchars((string)($value??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
$old=static function(string $name,string $fallback=''):string {
    $value=$_POST[$name]??$fallback;
    return is_string($value)?$value:$fallback;
};
$ready=$preflight && $preflight['missing']===[] && ($preflight['mysql'] || $preflight['sqlite']);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>DUP v2 · First-run setup</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="icon" type="image/png" sizes="256x256" href="assets/dup.png?v=20261123">
</head>
<body>
<div class="setup-shell">
<header class="setup-title"><img class="brand-logo-original" src="assets/dup.png?v=20261123" width="32" height="32" alt="DUP icon"><div>
  <h1>DUP v2 setup</h1><p>One-time configuration for Windows, Linux and Synology web hosts</p>
</div></header>
<?php if($installed): ?>
  <section class="panel panel-content">
    <h2><?= $complete?'Installation completed':'DUP is already configured' ?></h2>
    <p>The private configuration and administrator account have been created. The setup code is no longer valid.</p>
    <p><a class="primary link-button" href="./login">Continue to sign in</a></p>
    <p class="muted">Configure a PHP CLI job worker with <code>php bin/console dup:jobs:work --loop</code> or schedule <code>dup:jobs:work --max-jobs=1</code>.</p>
  </section>
<?php elseif($existing): ?>
  <section class="panel panel-content">
    <h2>Existing private configuration detected</h2>
    <p>Setup will not overwrite an existing <code>.env.local</code>. Administrators should check the installation and its database manually.</p>
  </section>
<?php else: ?>
  <?php foreach($errors as $error): ?>
    <div class="notice notice-error" role="alert"><?= show($error) ?></div>
  <?php endforeach; ?>
  <section class="panel panel-content">
    <h2>1. Server readiness</h2>
    <p>PHP <?= show($preflight['php']??PHP_VERSION) ?> · <?= show($preflight['os']??PHP_OS_FAMILY) ?>.
    <?= $https?'Secure HTTPS connection':'Localhost development connection' ?>.</p>
    <?php if(!empty($preflight['missing'])): ?>
      <div class="notice notice-error">Missing required PHP extensions: <?= show(implode(', ',$preflight['missing'])) ?>.</div>
    <?php endif; ?>
    <p class="muted">MariaDB/MySQL: <?= !empty($preflight['mysql'])?'Available':'pdo_mysql missing' ?>;
    SQLite: <?= !empty($preflight['sqlite'])?'Available':'pdo_sqlite missing' ?>.
    Private storage: <?= !empty($preflight['private_storage'])?'Writable':'Not writable' ?>.</p>
    <p>For security, setup requires the pairing code stored at
      <code>var/setup.token</code> in the private application directory. Read it with SSH, FTP or your hosting file manager.
      The code is never exposed by this page.</p>
  </section>
  <form class="panel panel-content" method="post" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= show($_SESSION['dup_setup_csrf']) ?>">
    <h2>2. Database</h2>
    <p class="muted">Use a dedicated <strong>empty</strong> database. The installer never alters an existing populated database.</p>
    <div class="field"><label for="driver">Database type</label>
      <select id="driver" name="driver">
        <option value="mysql" <?= $old('driver','mysql')==='mysql'?'selected':'' ?>>MariaDB / MySQL (recommended)</option>
        <option value="sqlite" <?= $old('driver')==='sqlite'?'selected':'' ?>>SQLite (small local or development use)</option>
      </select></div>
    <div class="setup-fields">
      <div class="field"><label for="db_host">Database host</label>
        <input id="db_host" name="db_host" value="<?= show($old('db_host','127.0.0.1')) ?>" maxlength="255"></div>
      <div class="field"><label for="db_port">Port</label>
        <input id="db_port" name="db_port" inputmode="numeric" value="<?= show($old('db_port','3306')) ?>" maxlength="5"></div>
      <div class="field"><label for="db_name">Empty database name</label>
        <input id="db_name" name="db_name" value="<?= show($old('db_name','dup_v2')) ?>" maxlength="64"></div>
      <div class="field"><label for="db_user">Database user</label>
        <input id="db_user" name="db_user" value="<?= show($old('db_user')) ?>" maxlength="128"></div>
      <div class="field"><label for="db_password">Database password</label>
        <input id="db_password" name="db_password" type="password" autocomplete="new-password"></div>
    </div>
    <h2>3. Administrator</h2>
    <div class="setup-fields">
      <div class="field"><label for="admin_user">Username</label>
        <input id="admin_user" name="admin_user" value="<?= show($old('admin_user','admin')) ?>" required maxlength="64"></div>
      <div class="field"><label for="admin_password">Password (12+ characters)</label>
        <input id="admin_password" name="admin_password" type="password" required minlength="12" autocomplete="new-password"></div>
      <div class="field"><label for="confirm_password">Confirm password</label>
        <input id="confirm_password" name="confirm_password" type="password" required minlength="12" autocomplete="new-password"></div>
    </div>
    <h2>4. Storage</h2>
    <div class="field"><label for="roots">Allowed scan directories (one full server path per line)</label>
      <textarea id="roots" name="roots" rows="4" placeholder="Linux / DSM: /volume1/Music&#10;Windows: D:\Music"><?= show($old('roots')) ?></textarea>
      <p class="muted">Optional at setup; no directories can be browsed or scanned until roots are configured. These are server paths, not browser or mapped-drive paths.</p></div>
    <h2>5. Authorization</h2>
    <div class="field"><label for="setup_code">Private setup pairing code</label>
      <input id="setup_code" name="setup_code" required minlength="48" maxlength="48" autocomplete="off" spellcheck="false"></div>
    <div class="form-actions">
      <button type="submit" class="primary" <?= !$ready?'disabled':'' ?>>Install DUP v2</button>
    </div>
    <p class="muted">This action creates the initial database schema and administrator account, writes a private configuration file, then permanently closes the installer.</p>
  </form>
<?php endif; ?>
<footer class="app-footer">DUP v2 · <a href="assets/license.txt">MIT License</a></footer>
</div>
</body>
</html>
