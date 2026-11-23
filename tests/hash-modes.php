<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Scans\Service\FileHasher;

function check(bool $ok,string $message): void
{
    if(!$ok) throw new \RuntimeException($message);
}
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-hash-fixtures-'.bin2hex(random_bytes(5));
if(!mkdir($folder,0700,true)) throw new \RuntimeException('Could not create test folder.');
try {
    $small=$folder.DIRECTORY_SEPARATOR.'small.bin';
    file_put_contents($small,str_repeat('test data',200));
    $smallSize=filesize($small);
    $smallTime=filemtime($small);
    $fullSmall=strtoupper(hash_file('crc32b',$small));
    check(FileHasher::digest($small,'crc32_3part',$smallSize,$smallTime)===$fullSmall,
        'Short files must fall back to full CRC32');
    check(FileHasher::digest($small,'crc32_full',$smallSize,$smallTime)===$fullSmall,
        'Full CRC32 mismatch');
    check(FileHasher::digest($small,'sha256',$smallSize,$smallTime)===hash_file('sha256',$small),
        'SHA-256 mismatch');
    $large=$folder.DIRECTORY_SEPARATOR.'large.bin';
    $sample=str_repeat('A',150*1024);
    $middle=str_repeat('B',150*1024);
    $end=str_repeat('C',150*1024);
    $interior=str_repeat('x',150*1024);
    file_put_contents($large,$sample.$interior.$middle.$interior.$end);
    $size=filesize($large);
    $mtime=filemtime($large);
    $middleOffset=(int)(($size/2)-(strlen($sample)/2));
    $original=file_get_contents($large);
    $expected=strtoupper(hash('crc32b',
        hash('crc32b',substr($original,0,150*1024)).
        hash('crc32b',substr($original,$middleOffset,150*1024)).
        hash('crc32b',substr($original,-150*1024))
    ));
    $partial=FileHasher::digest($large,'crc32_3part',$size,$mtime);
    check($partial===$expected,'3-part CRC32 is not compatible with original DUP');
    $originalFull=FileHasher::digest($large,'crc32_full',$size,$mtime);
    $originalSha=FileHasher::digest($large,'sha256',$size,$mtime);
    // Mutate an interior byte outside the sampled start/middle/end regions.
    $stream=fopen($large,'r+b');
    fseek($stream,180*1024);
    fwrite($stream,'Y');
    fclose($stream);
    clearstatcache(true,$large);
    $changedTime=filemtime($large);
    check(FileHasher::digest($large,'crc32_3part',$size,$changedTime)===$partial,
        'Partial CRC should not read unsampled interior bytes');
    check(FileHasher::digest($large,'crc32_full',$size,$changedTime)!==$originalFull,
        'Full CRC failed to detect an unsampled change');
    check(FileHasher::digest($large,'sha256',$size,$changedTime)!==$originalSha,
        'SHA-256 failed to detect an unsampled change');
    check(FileHasher::groupRule('size')===0 && FileHasher::groupRule('sha256')===1 &&
        FileHasher::groupRule('crc32_3part')===2 && FileHasher::groupRule('crc32_full')===3,
        'Duplicate confidence levels differ from expected rules');
    check(FileHasher::isSupported('crc32_3part') && !FileHasher::isSupported('invalid'),
        'Hashing strategy validation failed');
    $empty=$folder.DIRECTORY_SEPARATOR.'empty.bin';
    file_put_contents($empty,'');
    check(FileHasher::digest($empty,'crc32_3part',0,filemtime($empty))==='unknown',
        'Legacy empty-file CRC32 behaviour differs');
    echo "PASS original DUP 3-part CRC32, short-file fallback, full CRC32, SHA-256, confidence labels\n";
} finally {
    foreach(glob($folder.DIRECTORY_SEPARATOR.'*')?:[] as $file) @unlink($file);
    @rmdir($folder);
}
