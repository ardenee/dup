<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

/** Constrain moves to an existing configured root and preserve folder hierarchy. */
final class MovePathPlanner
{
    public function __construct(private readonly SafeFileSystem $fs) {}

    public function destination(string $sourceRoot,string $moveRoot,string $directory,string $filename,bool $requireSource=true): string
    {
        $sourceRoot=$this->fs->checkedRoot($sourceRoot);
        $moveRoot=$this->fs->checkedRoot($moveRoot);
        $directory=$this->fs->checkedRoot($directory);
        if($filename==='' || $filename==='.' || $filename==='..' ||
           strpbrk($filename,"/\\\0")!==false)
            throw new \DomainException('Invalid indexed filename.');
        if($requireSource) $this->fs->checkedPath($directory,$filename);
        $norm=static fn(string $path):string => rtrim(str_replace('\\','/',$path),'/');
        $src=$norm($sourceRoot);
        $dir=$norm($directory);
        $dest=$norm($moveRoot);
        $cmp=static fn(string $p):string => PHP_OS_FAMILY==='Windows'?mb_strtolower($p):$p;
        if($cmp($dir)!==$cmp($src) && !str_starts_with($cmp($dir).'/', $cmp($src).'/'))
            throw new \DomainException('The file is outside its configured scan root.');
        if($cmp($src)===$cmp($dest))
            throw new \DomainException('The move root must differ from the scan root.');
        // Avoid moving files into a child of the scanned root. That would
        // cause them to be indexed again in a subsequent scan.
        if(str_starts_with($cmp($dest).'/', $cmp($src).'/'))
            throw new \DomainException('The destination must be outside the scan root.');
        $relative=$cmp($dir)===$cmp($src)?'':ltrim(substr($dir,strlen($src)),'/');
        if($relative!=='' && (str_contains($relative,'..') || str_contains($relative,"\0")))
            throw new \DomainException('Invalid relative folder structure.');
        $suffix=$relative===''?'':str_replace('/',DIRECTORY_SEPARATOR,$relative).DIRECTORY_SEPARATOR;
        return $moveRoot.DIRECTORY_SEPARATOR.$suffix.$filename;
    }

    /** Validate each parent and create missing folders without traversing links. */
    public function ensureParent(string $moveRoot,string $destination): string
    {
        $root=$this->fs->checkedRoot($moveRoot);
        $parent=dirname($destination);
        $prefix=rtrim(str_replace('\\','/',$root),'/');
        $target=str_replace('\\','/',$parent);
        $cmp=static fn(string $p):string => PHP_OS_FAMILY==='Windows'?mb_strtolower($p):$p;
        if($cmp($target)===$cmp($prefix)) return $root;
        if(!str_starts_with($cmp($target).'/', $cmp($prefix).'/'))
            throw new \DomainException('Destination folder escapes its configured root.');
        $relative=ltrim(substr($target,strlen($prefix)),'/');
        $current=$root;
        foreach(explode('/',$relative) as $segment) {
            if($segment==='' || $segment==='.' || $segment==='..' ||
               strpbrk($segment,"\\/\0")!==false)
                throw new \DomainException('Invalid destination folder.');
            $next=$current.DIRECTORY_SEPARATOR.$segment;
            clearstatcache(true,$next);
            if(is_link($next) || (file_exists($next) && !is_dir($next)))
                throw new \DomainException('Move destination contains an obstructing file or symlink.');
            if(!is_dir($next) && !@mkdir($next,0750))
                throw new \DomainException('Unable to create a destination folder.');
            $real=realpath($next);
            if($real===false || !$this->fs->samePath($real,$next))
                throw new \DomainException('Move destination folder changed or is a symlink.');
            $current=$real;
        }
        if(!$this->fs->samePath($current,$parent))
            throw new \DomainException('The destination parent changed since planning.');
        return $current;
    }
}
