<?php
declare(strict_types=1);

namespace App\Core\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * Provision a missing MySQL/MariaDB database during first-run setup.
 *
 * Uses the application's submitted credentials and transport. No separate
 * administrative password is saved; no existing database is dropped, reset,
 * or modified by the provisioning step.
 */
final class DatabaseProvisioner
{
    public static function create(array $connectionOptions, string $databaseName): void
    {
        $statement = self::createStatement($databaseName);
        $server = DriverManager::getConnection(self::serverOptions($connectionOptions));
        try {
            // Connect without selecting a database: selecting a missing
            // schema raises SQLSTATE 42000 / MySQL error 1049.
            $server->executeQuery('SELECT 1')->fetchOne();
            $server->executeStatement($statement);
        } finally {
            $server->close();
        }
    }

    /** Database creation connects to the server without selecting a schema. */
    public static function serverOptions(array $connectionOptions): array
    {
        unset($connectionOptions['dbname']);
        return $connectionOptions;
    }

    public static function createStatement(string $databaseName): string
    {
        // The same identifier validation is applied by the installer.
        // Backtick quoting protects names containing a hyphen or underscore.
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $databaseName)) {
            throw new \InvalidArgumentException('Invalid database name.');
        }
        return 'CREATE DATABASE `'.$databaseName.
            '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
