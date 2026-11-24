<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

/**
 * Process-specific and failure-specific worker diagnostics.
 * Never report the same code for an unrelated job type.
 * Distinguishes process exit from manual heartbeat recovery.
 */
final class WorkerFailureCode
{
    private const COMPONENTS=[
        'scan'=>'SCAN',
        'rebuild-duplicates'=>'REBUILD',
        'duplicate-verify'=>'VERIFY',
        'file-action'=>'FILE-ACTION',
        'file-restore'=>'FILE-RESTORE',
        'file-rename'=>'RENAME',
        'file-move'=>'MOVE',
        'file-move-restore'=>'MOVE-RESTORE',
        'file-link'=>'LINK',
        'file-unlink'=>'UNLINK',
        'bulk-link'=>'BULK-LINK',
        'bulk-quarantine'=>'BULK-QUARANTINE',
        'bulk-restore'=>'BULK-RESTORE',
        'bulk-unlink'=>'BULK-UNLINK',
        'file-delete'=>'DELETE'
    ];

    private const CONDITIONS=[
        1=>'CLI process exited with status 1 without a recorded root exception',
        2=>'CLI process exited with status 137 (commonly SIGKILL), no recorded root exception',
        3=>'CLI process exited with status 143 (commonly SIGTERM), no recorded root exception',
        4=>'CLI process exited with another nonzero status without a recorded root exception',
        5=>'CLI process exited with status 0 while a job remained running',
        21=>'The process exited while cancellation was pending',
        22=>'An unresponsive cancellation was resolved administratively',
        23=>'A running job was resolved after its heartbeat expired'
    ];

    public static function exited(string $kind,int $status,bool $cancelling=false):string
    {
        $condition=$cancelling?21:match($status) {
            0=>5,1=>1,137=>2,143=>3,default=>4
        };
        return self::code($kind,$condition);
    }

    public static function stalled(string $kind,bool $cancelling):string
    {
        return self::code($kind,$cancelling?22:23);
    }

    private static function code(string $kind,int $condition):string
    {
        $component=self::COMPONENTS[$kind]??'OTHER';
        return sprintf('DUP-%s-WORKER-%03d',$component,$condition);
    }

    public static function explain(string $code):?string
    {
        foreach(self::COMPONENTS as $kind=>$component) {
            $prefix='DUP-'.$component.'-WORKER-';
            if(!str_starts_with($code,$prefix))continue;
            $number=substr($code,strlen($prefix));
            if(!preg_match('/^\d{3}$/D',$number))return null;
            $condition=(int)$number;
            if(isset(self::CONDITIONS[$condition]))
                return $kind.' - '.self::CONDITIONS[$condition];
        }
        return null;
    }

    public static function catalog():array
    {
        $catalog=[];
        foreach(self::COMPONENTS as $kind=>$component) {
            foreach(self::CONDITIONS as $condition=>$description) {
                $code=self::code($kind,$condition);
                if(isset($catalog[$code]))
                    throw new \LogicException('Duplicate worker diagnostic code: '.$code);
                $catalog[$code]=$kind.' - '.$description;
            }
        }
        ksort($catalog);
        return $catalog;
    }
}
