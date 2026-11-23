<?php
declare(strict_types=1);
namespace App\Module\Renaming\Service;

/**
 * DUP's legacy filename cleanup rules, separated from disk operations so
 * previews and worker planning always agree. File extensions are retained.
 */
final class RenameRules
{
    public static function cleaned(string $filename,string $filters,int $dots,int $unders,bool $collapse): string
    {
        if(strlen($filters)>1000 || !in_array($dots,[0,1,2],true) || !in_array($unders,[0,1,2],true))
            throw new \DomainException('Invalid filename cleanup options.');
        self::validateName($filename);
        $point=strrpos($filename,'.');
        $base=$point!==false && $point>0 ? substr($filename,0,$point):$filename;
        $extension=$point!==false && $point>0 ? substr($filename,$point):'';
        foreach(explode(',',$filters) as $filter) {
            $filter=trim($filter);
            if($filter!=='') $base=str_ireplace($filter,'',$base);
        }
        if($dots===1) $base=str_replace('.',' ',$base);
        if($dots===2) $base=str_replace('.','_',$base);
        if($unders===1) $base=str_replace('_',' ',$base);
        if($unders===2) $base=str_replace('_','.',$base);
        if($collapse) $base=(string)preg_replace('/ {2,}/u',' ',$base);
        $new=trim($base).$extension;
        self::validateName($new);
        return $new;
    }

    public static function validateName(string $name): void
    {
        if($name==='' || $name==='.' || $name==='..' || strlen($name)>255
            || preg_match('~[/\\\\\\x00-\\x1f<>:"|?*]~u',$name)
            || rtrim($name," .")!==$name)
            throw new \DomainException('Invalid target filename (or filename is empty after cleanup).');
        $stem=(string)pathinfo($name,PATHINFO_FILENAME);
        if(preg_match('/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i',$stem))
            throw new \DomainException('The target filename is reserved on Windows.');
    }
}
