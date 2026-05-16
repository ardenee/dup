<?php
header('Content-Type: text/plain; charset=utf-8');
echo "dup PHP test OK\n";
echo "PHP_VERSION=" . PHP_VERSION . "\n";
echo "USER=" . get_current_user() . "\n";
echo "__DIR__=" . __DIR__ . "\n";
echo "sqlite3=" . (extension_loaded('sqlite3') ? 'yes' : 'no') . "\n";
echo "pdo_sqlite=" . (extension_loaded('pdo_sqlite') ? 'yes' : 'no') . "\n";
echo "open_basedir=" . ini_get('open_basedir') . "\n";
