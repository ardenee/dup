<?php
declare(strict_types=1);

// Synology exposes only public assets here. Private setup is kept in the SPK.
if (!is_file('/var/packages/dup/var/runtime/setup.complete')) {
    require '/var/packages/dup/target/app/public/setup.php';
    return;
}

// Once setup is complete, show a concise confirmation page. There is no need
// to display CLI worker commands: DSM manages the background worker itself.
require_once '/var/packages/dup/target/app/src/Core/Http/HttpsGuard.php';
\App\Core\Http\HttpsGuard::enforce();
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; img-src 'self'; form-action 'none'; frame-ancestors 'none'; base-uri 'none'");
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>DUP · Installation completed</title>
  <link rel="stylesheet" href="assets/app.css">
  <link rel="icon" type="image/png" sizes="120x120" href="assets/dup.png">
</head>
<body>
  <div class="setup-shell">
    <header class="setup-title">
      <img class="brand-logo-original" src="assets/dup.png" width="32" height="32" alt="DUP icon">
      <div><h1>DUP setup</h1><p>Installation completed</p></div>
    </header>
    <section class="panel panel-content">
      <h2>Installation completed</h2>
      <p>DUP has been configured and is ready to use.</p>
      <div class="form-actions">
        <a class="primary link-button" href="./login">Continue to sign in</a>
      </div>
    </section>
    <footer class="app-footer">DUP 0.7.14 · <a href="assets/license.txt">MIT License</a></footer>
  </div>
</body>
</html>
