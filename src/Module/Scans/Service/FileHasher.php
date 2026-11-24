<?php
declare(strict_types=1);

namespace App\Module\Scans\Service;

use App\Core\Diagnostics\CodedRuntimeFailure;
use App\Core\Diagnostics\CodedInvalidArgumentFailure;

/**
 * Fast duplicate candidate matching, preserving DUP's original 3-part CRC32
 * algorithm. Hash only same-size candidates: callers do size grouping first.
 *
 * Size-only and CRC32 matches are candidates, NEVER sufficient evidence for a
 * destructive operation. SHA-256 scans still require filesystem revalidation.
 */
final class FileHasher
{
    public const MODES=['size','crc32_3part','crc32_full','sha256'];
    private const SAMPLE_BYTES=150*1024;

    public static function isSupported(string $mode): bool
    {
        return in_array($mode,self::MODES,true);
    }

    public static function digest(
        string $path,string $mode,int $size,int $mtime,?callable $checkpoint=null,
        ?callable $onBytes=null
    ): string {
        if($mode==='size' || !self::isSupported($mode))
            throw new CodedInvalidArgumentFailure('DUP-HASH-101','A hashing strategy must be selected.');

        clearstatcache(true,$path);
        if(is_link($path))
            throw new CodedRuntimeFailure('DUP-HASH-102','Candidate is a symbolic link: '.$path);
        if(!is_file($path))
            throw new CodedRuntimeFailure('DUP-HASH-103','Candidate file missing: '.$path);
        if(!is_readable($path))
            throw new CodedRuntimeFailure('DUP-HASH-104','Candidate is not readable: '.$path);
        if(filesize($path)!==$size)
            throw new CodedRuntimeFailure('DUP-HASH-105','Candidate size changed: '.$path);
        if(filemtime($path)!==$mtime)
            throw new CodedRuntimeFailure('DUP-HASH-106','Candidate modification time changed: '.$path);

        $stream=@fopen($path,'rb');
        if($stream===false) throw new CodedRuntimeFailure('DUP-HASH-107','Unable to open candidate file: '.$path);
        try {
            if($mode==='crc32_3part' && $size===0) return 'unknown';
            if($mode==='crc32_3part' && $size>=3*self::SAMPLE_BYTES) {
                $start=(int)(($size/2)-(self::SAMPLE_BYTES/2));
                $parts=[];
                foreach([0,$start,$size-self::SAMPLE_BYTES] as $offset) {
                    if(fseek($stream,$offset,SEEK_SET)!==0)
                        throw new CodedRuntimeFailure('DUP-HASH-108','Failed to seek within candidate: '.$path);
                    $buffer=self::readExact($stream,self::SAMPLE_BYTES,$checkpoint);
                    $chunkHash=hash('crc32b',$buffer);
                    // Matches the original DUP behaviour, which treated
                    // zero-CRC samples as unusable rather than as duplicate proof.
                    if($chunkHash==='00000000') return '0 Filled';
                    $parts[]=$chunkHash;
                    if($checkpoint!==null) $checkpoint();
                }
                $result=strtoupper(hash('crc32b',implode('',$parts)));
            } else {
                $context=hash_init($mode==='sha256'?'sha256':'crc32b');
                $read=0;
                while(!feof($stream)) {
                    $chunk=fread($stream,1024*1024);
                    if($chunk===false || ($chunk==='' && !feof($stream)))
                        throw new CodedRuntimeFailure('DUP-HASH-109','Cannot read candidate file: '.$path);
                    if($chunk==='') break;
                    $read+=strlen($chunk);
                    hash_update($context,$chunk);
                    if($onBytes!==null) $onBytes($read);
                    if($checkpoint!==null) $checkpoint();
                }
                if($read!==$size)
                    throw new CodedRuntimeFailure('DUP-HASH-110','Candidate length changed during hashing: '.$path);
                $result=hash_final($context);
                if($mode==='crc32_3part' || $mode==='crc32_full')
                    $result=strtoupper($result);
            }
        } finally {
            fclose($stream);
        }
        clearstatcache(true,$path);
        if(filesize($path)!==$size)
            throw new CodedRuntimeFailure('DUP-HASH-111','Candidate size changed during hashing: '.$path);
        if(filemtime($path)!==$mtime)
            throw new CodedRuntimeFailure('DUP-HASH-112','Candidate modification time changed during hashing: '.$path);
        if(is_link($path))
            throw new CodedRuntimeFailure('DUP-HASH-113','Candidate became a symlink during hashing: '.$path);
        return $result;
    }

    private static function readExact($stream,int $length,?callable $checkpoint): string
    {
        $data='';
        while(strlen($data)<$length) {
            $chunk=fread($stream,$length-strlen($data));
            if($chunk===false || $chunk==='')
                throw new CodedRuntimeFailure('DUP-HASH-114','Incomplete CRC32 sample read.');
            $data.=$chunk;
            if($checkpoint!==null) $checkpoint();
        }
        return $data;
    }

    public static function groupRule(string $mode): int
    {
        return match($mode) {
            'size'=>0,
            'sha256'=>1,
            'crc32_3part'=>2,
            'crc32_full'=>3,
            default=>throw new CodedInvalidArgumentFailure('DUP-HASH-115','Unknown scan strategy.')
        };
    }

    public static function label(string $mode): string
    {
        return match($mode) {
            'size'=>'File size only',
            'crc32_3part'=>'CRC32 3 Part (fast)',
            'crc32_full'=>'CRC32 Full',
            'sha256'=>'Verified SHA-256',
            default=>'Unknown'
        };
    }
}
