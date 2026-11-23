<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__).'/vendor/autoload.php';

use App\Core\Setup\Installer;

$installer=new Installer(dirname(__DIR__));
try {
    if($installer->installed()) {
        fwrite(STDOUT,"DUP v2 is already installed.\n");
        exit(0);
    }
    if($installer->hasPrivateConfig()) {
        fwrite(STDERR,"Private configuration already exists; no setup code was generated.\n");
        exit(1);
    }
    $code=$installer->ensureToken();
    fwrite(STDOUT,"DUP v2 first-run pairing code: ".$code."\n");
    fwrite(STDOUT,"This code is required at https://your-host/setup.php and is invalidated after setup.\n");
    fwrite(STDOUT,"Private code file: ".$installer->tokenPath()."\n");
} catch(\Throwable $ex) {
    fwrite(STDERR,"Unable to initialize setup: ".$ex->getMessage()."\n");
    exit(1);
}
