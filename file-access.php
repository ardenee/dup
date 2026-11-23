<?php
declare(strict_types=1);

/** Keep queued filesystem operations within the profile's configured roots. */
function DupSafePath(string $path): string
{
    if($path === '' || str_contains($path, "\0") || preg_match('/[\x00-\x1f]/', $path))
        throw new InvalidArgumentException('Invalid empty/control-character file path.');
    $path = str_replace('\\', '/', $path);
    if(!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:\//', $path))
        throw new InvalidArgumentException('File path must be absolute.');
    if(preg_match('~(^|/)(?:\.|\.\.)(?:/|$)~', $path))
        throw new InvalidArgumentException('Dot segments are not permitted in file paths.');
    $path = preg_replace('~/+~', '/', $path);
    return rtrim((string)$path, '/');
}

function DupSafeRoots(array $profile, bool $allowMissing = false): array
{
    $roots = [];
    foreach(['path', 'mpath'] as $key)
    {
        $configured = trim((string)($profile[$key] ?? ''));
        if($configured === '' || $configured === '/') continue;
        try { $configured = DupSafePath($configured); }
        catch(InvalidArgumentException $ex) { continue; }
        $canonical = realpath($configured);
        if($canonical === false)
        {
            if(!$allowMissing || file_exists($configured) || is_link($configured)) continue;
            $ancestor = dirname($configured);
            while(!file_exists($ancestor) && !is_link($ancestor))
            {
                $next = dirname($ancestor);
                if($next === $ancestor) break;
                $ancestor = $next;
            }
            $realAncestor = realpath($ancestor);
            if($realAncestor === false || str_replace('\\', '/', $realAncestor) !== $ancestor) continue;
            $canonical = $configured;
        }
        $roots[] = rtrim(str_replace('\\', '/', $canonical), '/');
    }
    return array_values(array_unique($roots));
}

function DupUnderRoot(string $path, string $root): bool
{
    if(PHP_OS_FAMILY === 'Windows')
    {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    return $path === $root || str_starts_with($path, $root . '/');
}

function DupSourceInProfile(string $path, array $profile): bool
{
    try { $path = DupSafePath($path); }
    catch(InvalidArgumentException $ex) { return false; }
    $actual = realpath($path);
    if($actual === false || !is_file($actual)) return false;
    $actual = str_replace('\\', '/', $actual);
    foreach(DupSafeRoots($profile) as $root)
        if(DupUnderRoot($actual, $root) && $actual !== $root)
            return true;
    return false;
}

function DupTargetInProfile(string $path, array $profile): bool
{
    try { $path = DupSafePath($path); }
    catch(InvalidArgumentException $ex) { return false; }

    if(is_link($path)) return false;
    $roots = DupSafeRoots($profile, true);
    if($roots === []) return false;

    // Evaluate the real nearest existing parent. This rejects symlink escapes
    // even when the final destination or intermediate folders do not exist yet.
    $parent = dirname($path);
    while(!file_exists($parent) && !is_link($parent))
    {
        $next = dirname($parent);
        if($next === $parent || $next === '.') return false;
        $parent = $next;
    }
    $existing = realpath($parent);
    if($existing === false) return false;
    $existing = str_replace('\\', '/', $existing);

    foreach($roots as $root)
    {
        // The target's literal name must be under the same configured root.
        $literalRoot = str_replace('\\', '/', $root);
        if(DupUnderRoot($path, $literalRoot) &&
           (DupUnderRoot($existing, $root) || DupUnderRoot($root, $existing)))
            return true;
    }
    return false;
}

function DupRenameNameAllowed(string $name): bool
{
    return $name !== '' && $name !== '.' && $name !== '..'
        && !str_contains($name, '/') && !str_contains($name, '\\')
        && !str_contains($name, "\0")
        && !preg_match('/[\x00-\x1f]/', $name);
}

function DupValidateActionPath(string $action, string $source, string $target, array $profile): bool
{
    if(!DupSourceInProfile($source, $profile)) return false;
    return match($action)
    {
        'Delete' => true,
        'Rename' => DupRenameNameAllowed($target),
        'Move', 'Link' => DupTargetInProfile($target, $profile),
        default => false,
    };
}
