<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use App\Module\Renaming\Service\RenameService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\ActionSettings;
use App\Module\Profiles\Service\DirectoryBrowser;

function checkPage(bool $pass,string $message):void {
    if(!$pass)throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT)',
    'CREATE TABLE directories(id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)',
    'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,mtime_epoch INT,size_bytes INT,error_code TEXT)'
] as $ddl) $db->executeStatement($ddl);
$db->insert('scan_runs',['id'=>10,'profile_id'=>1,'state'=>'completed']);
$db->insert('directories',['id'=>10,'run_id'=>10,'path'=>'/tmp/dup-tests','is_ignored'=>0]);
for($i=1;$i<=110;$i++) {
    $db->insert('file_entries',[
        'id'=>$i,'run_id'=>10,'directory_id'=>10,
        'filename'=>sprintf('Track [copy] %03d.mp3',$i),
        'mtime_epoch'=>1790000000,'size_bytes'=>512,'error_code'=>null
    ]);
}
$fs=new SafeFileSystem(new DirectoryBrowser());
$actions=new FileActionService($db,$fs);
$service=new RenameService($db,$fs,$actions,new ActionSettings($db));
$args=[1,'[copy]',0,0,true,false];
$first=$service->candidates(...$args);
checkPage(count($first['rows'])===100 && $first['next']===100 &&
    $first['previous']===null,'Rename preview first page is not 100 files.');
checkPage($first['rows'][0]['new_name']==='Track  001.mp3' ||
    $first['rows'][0]['new_name']==='Track 001.mp3','Rename cleanup preview is incorrect.');
$second=$service->candidates(1,'[copy]',0,0,true,false,(int)$first['next']);
checkPage(count($second['rows'])===10 && $second['next']===null &&
    $second['previous']===101,'Rename preview second page cannot go back.');
$previous=$service->candidates(1,'[copy]',0,0,true,false,null,false,'','',false,'id','asc','',(int)$second['previous']);
checkPage(count($previous['rows'])===100 &&
    (int)$previous['rows'][0]['id']===1 &&
    (int)$previous['rows'][99]['id']===100 &&
    $previous['next']===100,
    'Rename preview Previous failed to return the original page.');
try {
    $service->candidates(1,'[copy]',0,0,true,false,100,false,'','',false,'id','asc','',101);
    throw new RuntimeException('Both rename cursors were accepted.');
} catch(DomainException) {}
echo "PASS rename previews paginate 110 indexed files forward/back and reject conflicting cursors\n";
