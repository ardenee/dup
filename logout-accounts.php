<?php
require_once __DIR__ . '/auth.php';
DupForgetSession();
foreach(['uname', 'password'] as $cookie)
    setcookie($cookie, '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
header('Location: accounts-login.php', true, 303);
exit;
