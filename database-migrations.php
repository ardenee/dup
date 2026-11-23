<?php
declare(strict_types=1);

/**
 * DUP additive database migrations.
 * This file performs no work when included. Existing scans and accounts remain intact.
 */
function DupDatabaseColumn(PDO $pdo, string $table, string $column): ?array
{
    if(!in_array($table, ['accounts', 'logs'], true) ||
       !in_array($column, ['password', 'line', 'user_id'], true))
        throw new InvalidArgumentException('Unsupported schema inspection.');

    $query = $pdo->query('SHOW COLUMNS FROM `' . $table . '` LIKE ' . $pdo->quote($column));
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    return $row ?: null;
}

function DupDatabaseIndex(PDO $pdo, string $table, string $index): bool
{
    if($table !== 'logs' || $index !== 'logs_user_id')
        throw new InvalidArgumentException('Unsupported schema index.');
    $query = $pdo->query("SHOW INDEX FROM `logs` WHERE Key_name='logs_user_id'");
    $found = (bool)$query->fetch(PDO::FETCH_ASSOC);
    $query->closeCursor();
    return $found;
}

function DupMigrationPlan(PDO $pdo): array
{
    $password = DupDatabaseColumn($pdo, 'accounts', 'password');
    if(!$password || !preg_match('/^varchar\((\d+)\)$/i', (string)$password['Type'], $matches))
        throw new RuntimeException('Unexpected accounts.password type; manual review required.');
    $length = (int)$matches[1];
    if($length < 50 || $length > 16383)
        throw new RuntimeException('Unexpected accounts.password size; manual review required.');

    $steps = [];
    if($length < 255)
        $steps[] = "Back up accounts to dup_backup_accounts_20261123 and widen password to VARCHAR(255).";
    if(DupDatabaseColumn($pdo, 'logs', 'line') === null)
        $steps[] = 'Add missing logs.line column.';
    if(DupDatabaseColumn($pdo, 'logs', 'user_id') === null)
        $steps[] = 'Add missing logs.user_id column.';
    if(!DupDatabaseIndex($pdo, 'logs', 'logs_user_id'))
        $steps[] = 'Add logs_user_id index.';

    return [
        'password_size' => $length,
        'account_count' => (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn(),
        'steps' => $steps,
    ];
}

/** Run only from a specifically requested CLI --apply command. */
function DupApplyMigrations(PDO $pdo, callable $report): array
{
    $plan = DupMigrationPlan($pdo);
    if($plan['password_size'] < 255)
    {
        $backupName = 'dup_backup_accounts_20261123';
        $report('Creating a private database backup of existing accounts.');
        $pdo->exec("CREATE TABLE IF NOT EXISTS `$backupName` LIKE `accounts`");
        $backupCount = (int)$pdo->query("SELECT COUNT(*) FROM `$backupName`")->fetchColumn();
        if($backupCount === 0)
        {
            $pdo->exec("INSERT INTO `$backupName` SELECT * FROM `accounts`");
            $backupCount = (int)$pdo->query("SELECT COUNT(*) FROM `$backupName`")->fetchColumn();
        }
        $sourceCount = (int)$pdo->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
        if($backupCount !== $sourceCount)
            throw new RuntimeException('The backup count differs from current accounts; migration stopped.');

        // A matching count alone is not proof that an older backup is current.
        $missing = (int)$pdo->query(
            "SELECT COUNT(*) FROM accounts AS source
             LEFT JOIN `$backupName` AS backup
               ON source.id=backup.id
              AND BINARY source.password=BINARY backup.password
              AND source.name=backup.name AND source.level=backup.level
             WHERE backup.id IS NULL"
        )->fetchColumn();
        if($missing !== 0)
            throw new RuntimeException('The account backup does not exactly match current credentials; migration stopped.');

        $report('Account backup verified (' . $backupCount . ' rows). Widening password column.');
        $pdo->exec('ALTER TABLE `accounts` MODIFY COLUMN `password` VARCHAR(255) NOT NULL');
    }

    if(DupDatabaseColumn($pdo, 'logs', 'line') === null)
    {
        $report('Adding logs.line.');
        $pdo->exec('ALTER TABLE `logs` ADD COLUMN `line` INT NOT NULL DEFAULT 0');
    }

    if(DupDatabaseColumn($pdo, 'logs', 'user_id') === null)
    {
        $report('Adding logs.user_id.');
        $pdo->exec('ALTER TABLE `logs` ADD COLUMN `user_id` INT NOT NULL DEFAULT 0');
    }

    if(!DupDatabaseIndex($pdo, 'logs', 'logs_user_id'))
    {
        $report('Creating logs_user_id index.');
        $pdo->exec('CREATE INDEX `logs_user_id` ON `logs` (`user_id`)');
    }

    $remaining = DupMigrationPlan($pdo);
    if($remaining['steps'] !== [])
        throw new RuntimeException('Migration verification failed: steps remain.');

    $pdo->exec('CREATE TABLE IF NOT EXISTS `dup_migrations` (
        `name` VARCHAR(100) NOT NULL PRIMARY KEY,
        `applied_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB');
    $statement = $pdo->prepare('INSERT IGNORE INTO `dup_migrations` (`name`) VALUES (?)');
    $statement->execute(['20261123_auth_and_logs']);

    $report('Migration complete; no accounts, profiles, or scan results were deleted.');
    return $remaining;
}
