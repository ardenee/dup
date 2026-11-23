<?php
declare(strict_types=1);
namespace App\Module\Profiles\Service;

/** Browse only explicitly configured storage roots, never the server filesystem. */
final class DirectoryBrowser
{
    public function __construct(private readonly string $configuredRoots) {}

    /** @return list<string> */
    public function roots(): array
    {
        $roots=[];
        foreach (explode(';',$this->configuredRoots) as $candidate) {
            $candidate=trim($candidate);
            if($candidate==='') continue;
            $real=realpath($candidate);
            if($real===false || !is_dir($real) || !is_readable($real)) continue;
            if($real==='/' || preg_match('~^[A-Za-z]:[\\\\/]?$~',$real)) continue;
            $roots[$real]=$real;
        }
        return array_values($roots);
    }

    public function canonicalDirectory(string $path): ?string
    {
        if($path==='' || strlen($path)>4096 || str_contains($path,"\0")) return null;
        $real=realpath($path);
        if($real===false || !is_dir($real) || !is_readable($real)) return null;
        foreach($this->roots() as $root) {
            if($this->contains($root,$real)) return $real;
        }
        return null;
    }

    private function contains(string $root,string $directory): bool
    {
        $root=rtrim(str_replace('\\','/',$root),'/');
        $directory=str_replace('\\','/',$directory);
        if(PHP_OS_FAMILY==='Windows') {
            $root=strtolower($root);
            $directory=strtolower($directory);
        }
        return $directory===$root || str_starts_with($directory,$root.'/');
    }

    /** @return array{path:string|null,can_select:bool,parent:string|null,folders:list<array{path:string,name:string}>} */
    public function list(?string $requested): array
    {
        if($requested===null || $requested==='') {
            return [
                'path'=>null,'can_select'=>false,'parent'=>null,
                'folders'=>array_map(
                    static fn(string $root): array => ['path'=>$root,'name'=>basename($root)],
                    $this->roots()
                ),
            ];
        }
        $current=$this->canonicalDirectory($requested);
        if($current===null) throw new \DomainException('Folder is outside allowed scan roots.');
        $parent=dirname($current);
        if($parent===$current || $this->canonicalDirectory($parent)===null) $parent='';
        $folders=[];
        $iterator=new \FilesystemIterator($current,\FilesystemIterator::SKIP_DOTS);
        foreach($iterator as $entry) {
            if($entry->isLink() || !$entry->isDir() || !$entry->isReadable()) continue;
            $real=$this->canonicalDirectory($entry->getPathname());
            if($real!==null) $folders[]=['name'=>$entry->getFilename(),'path'=>$real];
        }
        usort($folders,static fn(array $a,array $b):int => strnatcasecmp($a['name'],$b['name']));
        return ['path'=>$current,'can_select'=>true,'parent'=>$parent,'folders'=>$folders];
    }
}
