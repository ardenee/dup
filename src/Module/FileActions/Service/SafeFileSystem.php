<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use App\Module\Jobs\Service\JobStore;
use App\Core\Diagnostics\CodedDomainFailure;
use App\Module\Profiles\Service\DirectoryBrowser;

/** Cross-platform, no shell commands, no hard deletes or cross-device copies. */
final class SafeFileSystem
{
    public function __construct(private readonly DirectoryBrowser $browser) {}

    public function checkedPath(string $directory, string $filename): string
    {
        if($filename==='' || $filename==='.' || $filename==='..' ||
           strpbrk($filename,"/\\\0")!==false)
            throw new CodedDomainFailure('DUP-FS-101','Invalid file name in recorded scan.');
        $real=$this->browser->canonicalDirectory($directory);
        if($real===null || !$this->samePath($real,$directory))
            throw new CodedDomainFailure('DUP-FS-102','The recorded source directory moved or is no longer permitted.');
        $path=$real.DIRECTORY_SEPARATOR.$filename;
        if(is_link($path))
            throw new CodedDomainFailure('DUP-FS-103','Source path is now a symbolic link.');
        if(!is_file($path))
            throw new CodedDomainFailure('DUP-FS-104','Source file is absent.');
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

    /**
     * Remove an already-approved file without rereading its contents.
     * On failure, preserve the filesystem's real error and the effective
     * account/directory writability instead of hiding them behind @unlink.
     */
    public function unlinkApproved(string $path):void
    {
        error_clear_last();
        if(@unlink($path)) return;
        $raw=(string)(error_get_last()['message']??'');
        $reason=trim((string)preg_replace('/^unlink\\(.*\\):\\s*/s','',$raw));
        if($reason==='')$reason='The operating system did not report a cause';
        $directory=dirname($path);
        $canWrite=@is_writable($directory);
        $account='unknown';
        if(function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info=@posix_getpwuid(posix_geteuid());
            if(is_array($info) && isset($info['name']))$account=(string)$info['name'];
        } elseif(PHP_OS_FAMILY==='Windows') {
            $account=(string)(getenv('USERNAME')?:'unknown');
        }
        $httpGroup='unknown';
        if(function_exists('posix_getgroups') && function_exists('posix_getgrnam')) {
            $http=@posix_getgrnam('http');
            $groups=@posix_getgroups();
            if(is_array($http) && isset($http['gid']) && is_array($groups))
                $httpGroup=in_array((int)$http['gid'],$groups,true)?'yes':'no';
        }
        $hint='';
        if(preg_match('/permission denied|access is denied|operation not permitted/i',$reason)) {
            $hint='Check write/delete permission on the containing directory and any shared-folder ACL.';
        } elseif(preg_match('/read.only file system|read.only filesystem/i',$reason)) {
            $hint='Check whether the shared volume is mounted read-only.';
        }
        throw new CodedDomainFailure('DUP-FS-105',
            'Filesystem deletion failed: '.mb_substr($reason,0,115).
            '. Worker='.$account.'; http group='.$httpGroup.
            '; containing folder writable='.($canWrite?'yes':'no').
            ($hint!==''?'. '.$hint:''));
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
