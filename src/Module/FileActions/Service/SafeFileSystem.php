<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;

/** Cross-platform, no shell commands, no hard deletes or cross-device copies. */
final class SafeFileSystem
{
    public function __construct(private readonly DirectoryBrowser $browser) {}

    public function checkedPath(string $directory, string $filename): string
    {
        if($filename==='' || $filename==='.' || $filename==='..' ||
           strpbrk($filename,"/\\\0")!==false)
            throw new \DomainException('Invalid file name in recorded scan.');
        $real=$this->browser->canonicalDirectory($directory);
        if($real===null || !$this->samePath($real,$directory))
            throw new \DomainException('The recorded source directory moved or is no longer permitted.');
        $path=$real.DIRECTORY_SEPARATOR.$filename;
        if(is_link($path) || !is_file($path))
            throw new \DomainException('Source file is absent or is now a symbolic link.');
        return $path;
    }

    public function checkedRoot(string $path): string
    {
        $canonical=$this->browser->canonicalDirectory($path);
        if($canonical===null || !$this->samePath($canonical,$path))
            throw new \DomainException('The destination root is not permitted or has moved.');
        return $canonical;
    }

    public function samePath(string $a,string $b): bool
    {
        $a=str_replace('\\','/',$a);
        $b=str_replace('\\','/',$b);
        if(PHP_OS_FAMILY==='Windows') return mb_strtolower($a)===mb_strtolower($b);
        return $a===$b;
    }

    /** Strictly requires exact size/mtime, rejects symlinks; no filesystem write. */
    public function checkedStat(string $path,int $size,int $mtime): array
    {
        clearstatcache(true,$path);
        if(is_link($path) || !is_file($path)) throw new \DomainException('File is missing or is a symbolic link.');
        $stat=@stat($path);
        if(!is_array($stat) || $stat['size']!=$size || $stat['mtime']!=$mtime)
            throw new \DomainException('File changed since the scan. Rescan before attempting an action.');
        return $stat;
    }

    /** Re-hashes the entire file just before a move/restore; detects changes during reading. */
    public function verifyDigest(
        string $path,int $size,int $mtime,string $expected,callable $checkpoint
    ): array {
        $before=$this->checkedStat($path,$size,$mtime);
        $stream=@fopen($path,'rb');
        if(!$stream) throw new \DomainException('File cannot be opened for verification.');
        try {
            $hash=hash_init('sha256');$seen=0;
            while(!feof($stream)) {
                $chunk=fread($stream,1024*1024);
                if($chunk===false || ($chunk==='' && !feof($stream)))
                    throw new \RuntimeException('Error reading file during SHA-256 verification.');
                if($chunk==='') break;
                hash_update($hash,$chunk);
                $seen+=strlen($chunk);
                $checkpoint();
            }
            $actual=hash_final($hash);
        } finally {
            fclose($stream);
        }
        $after=$this->checkedStat($path,$size,$mtime);
        if($seen!==$size || (string)$before['dev']!==(string)$after['dev'] ||
           (string)$before['ino']!==(string)$after['ino'] ||
           !hash_equals(strtolower($expected),strtolower($actual)))
            throw new \DomainException('File does not match its verified SHA-256 scan record.');
        return $after;
    }

    public function requireSameDevice(string $source,string $targetRoot): void
    {
        $sourceStat=@stat($source);
        $targetStat=@stat($targetRoot);
        if(!is_array($sourceStat) || !is_array($targetStat) ||
           (string)$sourceStat['dev']!==(string)$targetStat['dev'])
            throw new \DomainException(
                'Quarantine requires the same filesystem/volume to avoid unsafe copy-and-delete operations.'
            );
    }

    /** Creates a private, collision-resistant namespace under the configured move root. */
    public function prepareQuarantine(string $moveRoot,int $batchId,int $entryId): string
    {
        $base=$this->checkedRoot($moveRoot);
        foreach([
            '.dup-quarantine',
            'batch-'.$batchId,
            'entry-'.$entryId
        ] as $segment) {
            $next=$base.DIRECTORY_SEPARATOR.$segment;
            clearstatcache(true,$next);
            if(is_link($next) || (file_exists($next) && !is_dir($next)))
                throw new \DomainException('Quarantine directory is obstructed or redirected.');
            if(!is_dir($next) && !@mkdir($next,0700))
                throw new \DomainException('Cannot create the private quarantine directory.');
            $real=realpath($next);
            if($real===false || !$this->samePath($real,$next))
                throw new \DomainException('Quarantine directory changed or became a link.');
            $base=$real;
        }
        return $base;
    }

    /** Stage an approved existing destination in a private unique file next to it. */
    public function stageReplacement(string $target,string $backup,int $size,int $mtime):void
    {
        if(dirname($target)!==dirname($backup) || basename($backup)==='' ||
           !str_starts_with(basename($backup),'.dup-replaced-'))
            throw new \DomainException('Invalid temporary overwrite backup path.');
        if(file_exists($backup) || is_link($backup))
            throw new \DomainException('The temporary overwrite backup location is occupied.');
        $before=$this->checkedStat($target,$size,$mtime);
        $this->renameExactly($target,$backup,$size,$mtime);
        try {
            $after=$this->checkedStat($backup,$size,$mtime);
            if((string)$before['dev']!==(string)$after['dev'] ||
               (string)$before['ino']!==(string)$after['ino'])
                throw new \DomainException('Approved overwrite target changed during staging.');
        } catch(\Throwable $ex) {
            if(is_file($backup) && !is_link($backup) &&
               !file_exists($target) && !is_link($target))
                @rename($backup,$target);
            throw $ex;
        }
    }

    /** Roll back a staged target only if its original pathname is free. */
    public function restoreReplacement(string $target,string $backup,int $size,int $mtime):void
    {
        $this->renameExactly($backup,$target,$size,$mtime);
    }

    /** The approved overwrite is now committed; discard the previous destination. */
    public function finishReplacement(string $backup,int $size,int $mtime):void
    {
        $this->checkedStat($backup,$size,$mtime);
        if(!@unlink($backup))
            throw new \DomainException('Unable to remove the old overwritten destination backup.');
    }

    public function renameExactly(string $source,string $target,int $size,int $mtime): void
    {
        $this->checkedStat($source,$size,$mtime);
        clearstatcache(true,$target);
        if(file_exists($target) || is_link($target))
            throw new \DomainException('Destination already contains a file; no overwrite allowed.');
        if(!@rename($source,$target))
            throw new \DomainException('Atomic rename failed; source was not intentionally deleted.');
        // The caller must record the move before checking the destination,
        // so it can compensate if post-rename verification fails.
    }
}
