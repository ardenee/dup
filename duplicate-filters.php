<?php
/**
 * Shared duplicate visibility rules. Ignored folders remain indexed; they are
 * excluded only from duplicate comparisons, counts and result pages.
 * SQL functions used here (INSTR, LOWER, REPLACE, CHAR) work on MariaDB/SQLite.
 */
function DupIgnoredFolders($raw)
{
    $result = array();
    foreach(explode(',', (string)$raw) as $part)
    {
        $name = strtolower(trim(trim($part), "/\\ \t\r\n"));
        // The setting contains folder names, not paths or glob patterns.
        if($name !== '' && !str_contains($name, '/') && !str_contains($name, '\\'))
            $result[$name] = $name;
    }
    return array_values($result);
}

function DupVisibilityFilter(array $settings, array $profile, string $prefix = 'visible')
{
    $params = array();
    $where = '';
    // Lowercase before REPLACE: on MariaDB, CHAR(92) makes REPLACE's
    // result binary, so LOWER(REPLACE(...)) does not fold case.
    $pathSql = "REPLACE(LOWER(file), CHAR(92), '/')";
    foreach(DupIgnoredFolders($settings['ifolders'] ?? '') as $index => $name)
    {
        $key = ':' . $prefix . '_ignore_' . $index;
        $where .= ' AND INSTR(' . $pathSql . ', ' . $key . ')=0';
        $params[$key] = '/' . $name . '/';
    }

    if((int)($settings['displaymoved'] ?? 0) === 0)
    {
        $moved = strtolower(str_replace('\\', '/', trim((string)($profile['mpath'] ?? ''))));
        $moved = rtrim($moved, '/');
        if($moved !== '' && $moved !== '.')
        {
            $key = ':' . $prefix . '_moved';
            $where .= ' AND INSTR(' . $pathSql . ', ' . $key . ')<>1';
            $params[$key] = $moved . '/';
        }
    }
    return array('where' => $where, 'params' => $params);
}

function DupBindVisibility(PDOStatement $stmt, array $params): void
{
    foreach($params as $key => $value)
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
}

function DupCountVisibleGroups(PDO $pdo, int $profileId, array $settings, array $profile): int
{
    if($profileId <= 0) return 0;
    $column = ((int)($profile['crc32'] ?? 0) > 0) ? 'crc32' : 'size';
    $filter = DupVisibilityFilter($settings, $profile, 'count');
    $validHash = $column === 'crc32' ? " AND crc32!='unknown' AND crc32!=''" : '';
    $sql = "SELECT COUNT(*) FROM (
        SELECT $column FROM files WHERE profile=:profile
          $validHash " . $filter['where'] . "
        GROUP BY $column HAVING COUNT(*)>1
    ) AS duplicate_groups";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':profile', $profileId, PDO::PARAM_INT);
    DupBindVisibility($stmt, $filter['params']);
    $stmt->execute();
    $count = (int)$stmt->fetchColumn();
    $stmt->closeCursor();
    return $count;
}

// Refresh only cached duplicate totals and page offsets; never delete indexed files.
function DupRefreshAllProfileCounts(PDO $pdo, array $settings): int
{
    $query = $pdo->query('SELECT id, crc32, mpath, scan FROM profiles ORDER BY id');
    $profiles = $query->fetchAll(PDO::FETCH_ASSOC);
    $query->closeCursor();

    $update = $pdo->prepare('UPDATE profiles SET dups=:dups WHERE id=:profile');
    $refreshed = 0;
    foreach($profiles as $profile)
    {
        // Avoid overwriting the counts of a scan in progress.
        if((int)($profile['scan'] ?? 0) !== 0) continue;
        $id = (int)$profile['id'];
        $count = DupCountVisibleGroups($pdo, $id, $settings, $profile);
        $update->bindValue(':dups', $count, PDO::PARAM_INT);
        $update->bindValue(':profile', $id, PDO::PARAM_INT);
        $update->execute();
        $refreshed++;
    }
    $update->closeCursor();
    $pdo->exec('DELETE FROM viewpages');
    return $refreshed;
}
