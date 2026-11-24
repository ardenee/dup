<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Scans\Service\FileHasher;

function assertVerify(bool $ok,string $why):void
{
    if(!$ok) throw new RuntimeException($why);
}

$path=tempnam(sys_get_temp_dir(),'dup-hash-progress-');
if($path===false)throw new RuntimeException('Failed to create verification test file.');
try {
    $chunks=4;
    $written=file_put_contents($path,str_repeat('Z',1024*1024*$chunks));
    assertVerify($written===1024*1024*$chunks,'Test file size mismatch.');
    clearstatcache(true,$path);
    $checkpoints=0;$marks=[];
    $digest=FileHasher::digest(
        $path,'sha256',(int)filesize($path),(int)filemtime($path),
        function() use (&$checkpoints):void {$checkpoints++;},
        function(int $bytes) use (&$marks):void {$marks[]=$bytes;}
    );
    assertVerify(hash_file('sha256',$path)===$digest,'Streaming digest is not SHA-256.');
    assertVerify(count($marks)>1 && end($marks)===$written,
        'Streaming SHA-256 did not report byte-level progress.');
    assertVerify($marks===array_values(array_unique($marks)) &&
        $marks===array_values(array_filter($marks,static fn(int $b)=>$b>0)),
        'SHA-256 byte checkpoints were duplicated or non-positive.');
    assertVerify($checkpoints>=count($marks),
        'Hashing checkpoints were not called after chunk reads.');
    $migration=file_get_contents(dirname(__DIR__).'/migrations/Version20261124001100.php');
    foreach(['progress_bytes_current','progress_bytes_total','progress_detail',
        'last_progress_at','last_failure_reason'] as $field) {
        assertVerify(str_contains($migration,$field),
            'Schema migration omits verification telemetry: '.$field);
    }
    echo "PASS streaming SHA-256 bytes, checkpoint timing, unchanged digest and migration fields\n";
} finally {
    @unlink($path);
}
