<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Profiles\Service\DirectoryBrowser;

function checkFs(bool $okay,string $why):void {if(!$okay) throw new RuntimeException($why);}
function refuseFs(callable $fn,string $message):void {
    try {$fn();} catch(DomainException) {return;}
    throw new RuntimeException($message);
}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-fs-'.bin2hex(random_bytes(6));
checkFs(mkdir($root,0700,true),'Cannot create safety fixture');
try {
    $source=$root.DIRECTORY_SEPARATOR.'original.bin';
    $destination=$root.DIRECTORY_SEPARATOR.'target.bin';
    $sourceContent=str_repeat('AUDIO_TEST_DATA-',10000);
    file_put_contents($source,$sourceContent);
    $size=filesize($source);
    $mtime=filemtime($source);
    $digest=hash_file('sha256',$source);
    $fs=new SafeFileSystem(new DirectoryBrowser());

    refuseFs(static fn()=>$fs->checkedPath($root,'../original.bin'),'Parent traversal accepted');
    refuseFs(static fn()=>$fs->checkedPath($root,'sub/original.bin'),'Slash traversal accepted');
    refuseFs(static fn()=>$fs->checkedPath($root,'sub\\original.bin'),'Backslash traversal accepted');
    refuseFs(static fn()=>$fs->checkedPath($root,'missing.bin'),'Missing input accepted');
    $fs->checkedStat($source,$size,$mtime);
    $fs->verifyDigest($source,$size,$mtime,$digest,static function():void {});
    refuseFs(static fn()=>$fs->verifyDigest($source,$size,$mtime,str_repeat('0',64),static function():void {}),
        'Mismatched full SHA-256 was accepted');
    refuseFs(static fn()=>$fs->checkedStat($source,$size+1,$mtime),'Stale file size accepted');
    refuseFs(static fn()=>$fs->checkedStat($source,$size,$mtime+100),'Stale file modification time accepted');
    $fs->requireSameDevice($source,$root);
    $fs->renameExactly($source,$destination,$size,$mtime);
    checkFs(!file_exists($source) && file_get_contents($destination)===$sourceContent,
        'Verified move corrupted data');
    file_put_contents($source,'FRESH CONTENT');
    $freshSize=filesize($source);
    $freshMtime=filemtime($source);
    refuseFs(static fn()=>$fs->renameExactly($source,$destination,$freshSize,$freshMtime),
        'Existing destination overwritten');
    checkFs(file_get_contents($destination)===$sourceContent,'Existing destination changed');
    checkFs(file_get_contents($source)==='FRESH CONTENT','Source changed during rejected operation');

    $symlinkTested=false;
    if(function_exists('symlink')) {
        $link=$root.DIRECTORY_SEPARATOR.'linked.bin';
        if(@symlink($source,$link)) {
            $symlinkTested=true;
            refuseFs(static fn()=>$fs->checkedPath($root,'linked.bin'),'Source symlink accepted');
            refuseFs(static fn()=>$fs->checkedStat($link,$freshSize,$freshMtime),'Symlink accepted during stat');
            // A dangling symlink is still an occupied destination.
            $dangling=$root.DIRECTORY_SEPARATOR.'dangling.bin';
            if(@symlink($root.DIRECTORY_SEPARATOR.'absent.bin',$dangling)){
                refuseFs(static fn()=>$fs->renameExactly($source,$dangling,$freshSize,$freshMtime),
                    'Dangling destination symlink overwritten');
                checkFs(is_link($dangling),'Dangling destination lost');
            }
        }
    }
    $outside=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-fs-out-'.bin2hex(random_bytes(6));
    if(mkdir($outside,0700,true)) {
        try {
            $jump=$root.DIRECTORY_SEPARATOR.'redirect';
            if(@symlink($outside,$jump)) {
                refuseFs(static fn()=>$fs->checkedRoot($jump),'Linked directory accepted as canonical root');
            }
        } finally { @rmdir($outside); }
    }
    checkFs(file_get_contents($source)==='FRESH CONTENT','Safety checks modified the source');
    echo "PASS filesystem safety: traversal, stale size/mtime, digest, collisions, no overwrite, same-device checks; ".
        ($symlinkTested?'symlink refusal verified':'symlink checks skipped (host privilege unavailable)')."\n";
}finally{
    foreach(new FilesystemIterator($root,FilesystemIterator::SKIP_DOTS) as $entry){
        if($entry->isDir() && !$entry->isLink()) @rmdir($entry->getPathname());
        else @unlink($entry->getPathname());
    }
    @rmdir($root);
}
