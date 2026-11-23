<?php
declare(strict_types=1);

namespace App\Core\Setup;

/** Host detection for presentation and install defaults; core code stays OS-neutral. */
final class HostEnvironment
{
    public static function isSynology(string $appRoot, ?string $osFamily = null): bool
    {
        if (($osFamily ?? PHP_OS_FAMILY) !== 'Linux') {
            return false;
        }
        $root = str_replace('\\', '/', $appRoot);
        return @is_file('/etc/synoinfo.conf')
            || @is_file('/etc.defaults/synoinfo.conf')
            || str_contains($root, '/var/packages/dup/')
            || str_contains($root, '/@appstore/dup/')
            || str_contains($root, '/web_packages/dup/');
    }

    public static function label(string $appRoot, ?string $osFamily = null): string
    {
        if (self::isSynology($appRoot, $osFamily)) {
            return 'Synology DSM';
        }
        return match ($osFamily ?? PHP_OS_FAMILY) {
            'Windows' => 'Windows',
            'Linux' => 'Linux',
            'Darwin', 'BSD' => 'Unix',
            default => $osFamily ?? PHP_OS_FAMILY,
        };
    }

    public static function mysqlPort(string $appRoot, ?string $osFamily = null): int
    {
        return self::isSynology($appRoot, $osFamily) ? 3307 : 3306;
    }

    public static function localMariaDbSocket(string $appRoot): ?string
    {
        if (!self::isSynology($appRoot)) {
            return null;
        }
        $socket = '/run/mysqld/mysqld10.sock';
        return @file_exists($socket) ? $socket : null;
    }
}
