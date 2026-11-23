<?php
declare(strict_types=1);

namespace App\Core\Setup;

/**
 * Translate low-level database exceptions to useful, credential-free messages.
 * Never display or log the PDO exception message (may contain user, schema or DSN).
 */
final class DatabaseDiagnostic
{
    public static function driverCode(\Throwable $exception): ?int
    {
        for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof \PDOException && isset($current->errorInfo[1])
                && is_numeric($current->errorInfo[1])) {
                return (int) $current->errorInfo[1];
            }
            $code = (string) $current->getCode();
            if (ctype_digit($code) && (int) $code > 0) {
                return (int) $code;
            }
        }
        return null;
    }

    public static function explain(\Throwable $exception, bool $synology, string $transport): string
    {
        $code = self::driverCode($exception);
        $suffix = $code === null ? '' : ' (MariaDB error '.$code.')';
        return match ($code) {
            1045, 1698 => 'MariaDB rejected the database username or password'.$suffix.
                '. phpMyAdmin may authenticate through a different host or local socket. Check the account grants.',
            1044, 1142, 1227 => 'The database account lacks privileges for this database'.$suffix.
                '. Grant the dedicated DUP user permission to create and manage tables in its empty database.',
            1049 => 'The selected database does not exist'.$suffix.
                '. DUP will try to create it automatically when the database account has CREATE privileges.',
            2002, 2003, 2005 => 'Could not reach MariaDB using '.$transport.$suffix.'. '.
                ($synology
                    ? 'Synology MariaDB 10 commonly uses TCP port 3307; verify TCP/IP is enabled or that its local socket is accessible.'
                    : 'Check the database host, port, and whether the server accepts connections.'),
            1040 => 'MariaDB has reached its connection limit'.$suffix.'. Retry after checking the database server.',
            default => 'Database connection failed using '.$transport.$suffix.
                '. Check the database host, port, credentials, and permissions. No credentials were logged.',
        };
    }

    public static function explainCreation(\Throwable $exception): string
    {
        $code = self::driverCode($exception);
        $suffix = $code === null ? '' : ' (MariaDB error '.$code.')';
        return match ($code) {
            1044, 1142, 1227 => 'The database does not exist and the supplied MariaDB account cannot create it'.
                $suffix.'. Use an account with CREATE DATABASE privileges for this database, or grant CREATE to the application account.',
            1045, 1698 => 'MariaDB rejected the credentials while trying to create the missing database'.
                $suffix.'. Verify this account can sign in to the database server, not only to phpMyAdmin.',
            1007 => 'The database was created by another process. Please retry setup.',
            default => 'DUP could not create the missing database'.$suffix.
                '. Check that this account has CREATE DATABASE permission and that the server is accessible.',
        };
    }

    public static function logFailure(\Throwable $exception, string $transport): void
    {
        $code = self::driverCode($exception);
        error_log('DUP setup database check failed; transport='.$transport.
            '; driver_code='.($code === null ? 'unknown' : (string) $code));
    }
}
