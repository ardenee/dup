<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use App\Module\Files\Service\IndexedFileService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Profiles\Service\DirectoryBrowser;

function checkFile(bool $condition,string $message):void {
    if(!$condition) throw new \RuntimeException($message);
}
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-viewer-'.bin2hex(random_bytes(6));
mkdir($folder,0700,true);
try {
    $source=$folder.DIRECTORY_SEPARATOR.'a.txt';
    file_put_contents($source,"line 1\n<unsafe> & special\n");
    $size=filesize($source);
    $mtime=filemtime($source);
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles (id INTEGER PRIMARY KEY,source_root TEXT,name TEXT)',
        'CREATE TABLE scan_runs (id INTEGER PRIMARY KEY,profile_id INT,state TEXT)',
        'CREATE TABLE directories (id INTEGER PRIMARY KEY,path TEXT)',
        'CREATE TABLE file_entries (id INTEGER PRIMARY KEY,filename TEXT,directory_id INT,run_id INT,size_bytes INT,mtime_epoch INT,hash_value TEXT,hash_method TEXT,error_code TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->insert('scan_profiles',['id'=>1,'source_root'=>realpath($folder),'name'=>'Test']);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'path'=>realpath($folder)]);
    $db->insert('file_entries',[
        'id'=>1,'filename'=>'a.txt','directory_id'=>1,'run_id'=>1,
        'size_bytes'=>$size,'mtime_epoch'=>$mtime,'hash_value'=>'abcd',
        'hash_method'=>'crc32_full','error_code'=>null
    ]);
    $service=new IndexedFileService($db,new SafeFileSystem(new DirectoryBrowser()));
    $file=$service->get(1);
    checkFile($file['preview_type']==='text','Text preview missing');
    [$content,$truncated]=$service->textPreview($file);
    checkFile(!$truncated && str_contains($content,'<unsafe>'),'Preview text incorrect');
    checkFile(IndexedFileService::previewType('pdf',500)==='pdf','PDF preview missing');
    checkFile(IndexedFileService::previewType('jpg',400)==='image','Image preview missing');
    checkFile(IndexedFileService::previewType('html',300)==='text','HTML must be shown as text');
    checkFile(IndexedFileService::previewType('pdf',50*1024*1024)==='none','Large PDF preview must be blocked');
    checkFile(IndexedFileService::previewType('exe',5)==='none','Executable preview allowed');
    try{$service->get(999);throw new \RuntimeException('Non-indexed file accepted');}
    catch(\DomainException) {}
    $db->update('file_entries',['filename'=>'../a.txt'],['id'=>1]);
    try{$service->get(1);throw new \RuntimeException('Traversal filename accepted');}
    catch(\DomainException) {}
    $db->update('file_entries',['filename'=>'a.txt'],['id'=>1]);
    file_put_contents($source,"changed content");
    try{$service->get(1);throw new \RuntimeException('Stale file accepted');}
    catch(\DomainException) {}
    echo "PASS current indexed file checks, safe previews, traversal guard, stale-file guard\n";
} finally {
    foreach(glob($folder.DIRECTORY_SEPARATOR.'*')?:[] as $file) @unlink($file);
    @rmdir($folder);
}
