<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

/**
 * Read-only bounded log tail for authenticated administrators.
 * Never accepts a user-supplied filesystem path or command.
 */
final class WorkerLogReader
{
    public const DSM_PATH='/var/packages/dup/var/worker.log';
    private const MAX_BYTES=65536;
    private const MAX_LINES=140;

    public function latest():array
    {
        $path=self::DSM_PATH;
        if(PHP_OS_FAMILY!=='Linux' || !is_file($path) || !is_readable($path))
            return [
                'available'=>false,'path'=>$path,'text'=>'',
                'reason'=>'Worker log cannot be read from this PHP process. '.
                    'On DSM, check the DUP package service and its runtime log path.'
            ];
        $handle=@fopen($path,'rb');
        if($handle===false)
            return ['available'=>false,'path'=>$path,'text'=>'',
                'reason'=>'The worker log is not readable by the Web Station PHP account.'];
        try {
            $stat=fstat($handle);
            $size=max(0,(int)($stat['size']??0));
            if($size>self::MAX_BYTES) fseek($handle,$size-self::MAX_BYTES);
            $raw=(string)stream_get_contents($handle,self::MAX_BYTES);
        } finally {
            fclose($handle);
        }
        if($size>self::MAX_BYTES) {
            $offset=strpos($raw,"\n");
            if($offset!==false)$raw=substr($raw,$offset+1);
        }
        // Escape happens in Twig; sanitize terminal control sequences and
        // invalid encodings that can corrupt browser rendering.
        $raw=mb_convert_encoding($raw,'UTF-8','UTF-8');
        $raw=(string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u','',$raw);
        $lines=explode("\n",$raw);
        $lines=array_slice($lines,-self::MAX_LINES);
        return [
            'available'=>true,'path'=>$path,
            'text'=>implode("\n",$lines),
            'last_updated'=>($modified=@filemtime($path))?gmdate('Y-m-d H:i:s',$modified):null,
            'bytes'=>$size,
            'truncated'=>$size>self::MAX_BYTES || count($lines)>=self::MAX_LINES
        ];
    }
}
