<?php
declare(strict_types=1);
namespace App\Module\Profiles\Service;

/**
 * Browse folders the server account can actually traverse/read.
 * No separate application-maintained root allowlist is required.
 * Filesystem permissions and PHP open_basedir limits remain authoritative.
 */
final class DirectoryBrowser
{
    /** @return list<string> */
    public function roots(): array
    {
        // Synology Web Station and shared hosting may set open_basedir.
        // Use PHP's actual runtime boundaries as browse entry points, rather
        // than requiring users to configure a second list during setup.
        $basedir = trim((string) ini_get('open_basedir'));
        if ($basedir !== '') {
            $roots = [];
            $separator = PHP_OS_FAMILY === 'Windows' ? ';' : ':';
            foreach (explode($separator, $basedir) as $path) {
                $path = trim($path);
                if ($path === '') continue;
                if ($path === '.') $path = getcwd() ?: '';
                $real = $path === '' ? false : @realpath($path);
                if ($real !== false && is_dir($real) && is_readable($real)) {
                    $roots[$real] = $real;
                }
            }
            return array_values($roots);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return is_dir('/') && is_readable('/') ? ['/'] : [];
        }
        $roots = [];
        foreach (range('A', 'Z') as $letter) {
            $drive = $letter.':\\';
            if (is_dir($drive) && is_readable($drive)) {
                $roots[] = $drive;
            }
        }
        return $roots;
    }

    public function canonicalDirectory(string $path): ?string
    {
        if ($path === '' || strlen($path) > 4096 || str_contains($path, "\0")) {
            return null;
        }
        $real = @realpath($path);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            return null;
        }
        // Selecting a whole filesystem/drive is never a valid scan profile.
        // It remains browseable so the administrator can choose a subfolder.
        if ($real === '/' || preg_match('~^[A-Za-z]:[\\\\\\\\/]?$~', $real)) {
            return null;
        }
        return $real;
    }

    /** @return array{path:string|null,can_select:bool,parent:string|null,folders:list<array{path:string,name:string}>} */
    public function list(?string $requested): array
    {
        if ($requested === null || $requested === '') {
            return [
                'path'=>null, 'can_select'=>false, 'parent'=>null,
                'folders'=>array_map(
                    static fn(string $root): array => [
                        'path'=>$root,
                        'name'=>$root === '/' ? '/' : $root
                    ], $this->roots()
                ),
            ];
        }

        $real = @realpath($requested);
        if ($real === false || !is_dir($real) || !is_readable($real)) {
            throw new \DomainException('Directory is not readable by the server account.');
        }
        $isRoot = $real === '/' || (bool) preg_match('~^[A-Za-z]:[\\\\\\\\/]?$~', $real);
        $parent = dirname($real);
        if ($isRoot || $parent === $real || !@is_dir($parent) || !@is_readable($parent)) {
            // Return to the initial available-locations view, rather than
            // trapping the folder picker at a drive or PHP sandbox boundary.
            $parent = '';
        }
        $folders = [];
        try {
            $iterator = new \FilesystemIterator($real, \FilesystemIterator::SKIP_DOTS);
            foreach ($iterator as $entry) {
                if ($entry->isLink() || !$entry->isDir() || !$entry->isReadable()) {
                    continue;
                }
                $candidate = @realpath($entry->getPathname());
                if ($candidate !== false && $this->canonicalDirectory($candidate) !== null) {
                    $folders[] = ['name'=>$entry->getFilename(), 'path'=>$candidate];
                }
            }
        } catch (\UnexpectedValueException $ex) {
            throw new \DomainException('Directory cannot be enumerated by this server account.', 0, $ex);
        }
        usort($folders, static fn(array $a,array $b): int => strnatcasecmp($a['name'],$b['name']));
        return ['path'=>$real, 'can_select'=>!$isRoot, 'parent'=>$parent, 'folders'=>$folders];
    }
}
