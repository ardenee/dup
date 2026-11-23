<?php
declare(strict_types=1);
// The DSM portal must direct an unconfigured installation to the setup
// wizard before Symfony requires database credentials or sessions.
if (!is_file('/var/packages/dup/var/runtime/setup.complete')) {
    header('Location: /dup/setup.php', true, 302);
    header('Cache-Control: no-store');
    exit;
}
// Use a DUP-specific session cookie, scoped to the DSM web portal.
// The setup wizard previously used PHPSESSID at /dup/, whereas Symfony's
// default PHPSESSID was scoped to /. Two cookies with the same name can
// cause a successful login to be lost on the following request.
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.name', 'DUPSESSID');
    ini_set('session.cookie_path', '/dup/');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
}

// Symfony Runtime evaluates SCRIPT_FILENAME when resolving its entry point.
// Point it at the real private controller, not this public Web Station wrapper.
$_SERVER['SCRIPT_FILENAME'] = '/var/packages/dup/target/app/public/index.php';
require $_SERVER['SCRIPT_FILENAME'];
