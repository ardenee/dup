<?php

use App\Kernel;

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
