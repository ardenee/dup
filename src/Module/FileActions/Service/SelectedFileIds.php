<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Symfony\Component\HttpFoundation\Request;

/** Strict, untruncated file selection for HTTP preview endpoints. */
final class SelectedFileIds
{
    /** @return list<int> */
    public static function fromRequest(Request $request):array
    {
        $compact=$request->request->get('file_ids','');
        if($compact!=='' && $compact!==null) {
            if(!is_string($compact) || !preg_match('/^[1-9][0-9]*(,[1-9][0-9]*)*$/D',$compact))
                throw new \DomainException('Invalid file selection. Select the files again.');
            $raw=explode(',',$compact);
        } else {
            $raw=$request->request->all('files');
        }
        if(!$raw) throw new \DomainException('Select at least one file.');
        $ids=[];$seen=[];
        foreach($raw as $value) {
            if(!is_string($value) && !is_int($value))
                throw new \DomainException('Invalid file selection.');
            $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false || isset($seen[$id]))
                throw new \DomainException('Invalid or repeated file ID.');
            $seen[$id]=true;
            $ids[]=$id;
        }
        return $ids;
    }
}
