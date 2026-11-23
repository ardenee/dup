<?php

use App\Kernel;

// Fresh installations must enter the one-time installer before Symfony
// attempts to load database credentials or its security/session services.
// This also works behind a reverse proxy or Synology's alias portal because
// SCRIPT_NAME identifies the exposed entrypoint, not the private PHP file.
if (PHP_SAPI !== 'cli' && !is_file(dirname(__DIR__).'/var/setup.complete')) {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    $prefix = rtrim(dirname($script), '/.');
    header('Location: '.$prefix.'/setup.php', true, 302);
    header('Cache-Control: no-store');
    exit;
}

// On some PHP SAPIs (including the Linux built-in server), process
// environment variables are available via getenv() but are absent from
// $_SERVER/$_ENV. Symfony Runtime's dotenv bootstrap primarily reads the
// superglobals. Preserve any explicit server value and copy only missing
// application settings, without exposing credentials in HTTP responses.
foreach (['APP_ENV','APP_DEBUG','APP_SECRET','DATABASE_URL','DUP_ALLOWED_SCAN_ROOTS'] as $name) {
    if (!array_key_exists($name, $_SERVER)) {
        $value = getenv($name);
        if ($value !== false) {
            $_SERVER[$name] = $value;
            $_ENV[$name] ??= $value;
        }
    }
}

require_once dirname(__DIR__).'/vendor/autoload_runtime.php';

return static function (array $context) {
    return new Kernel($context['APP_ENV'], (bool) $context['APP_DEBUG']);
};
