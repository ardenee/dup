<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Reports\Repository\ReportRepository;
use Doctrine\DBAL\DriverManager;

function ckReport(bool $success,string $message):void {
    if(!$success)throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,files_seen INT,'.
      'files_indexed INT,bytes_indexed INT,duplicate_groups INT,error_count INT,started_at TEXT,finished_at TEXT)',
    'CREATE TABLE jobs(id INT PRIMARY KEY,kind TEXT,run_id INT,error_code TEXT)',
    'CREATE TABLE directories(id INT PRIMARY KEY,path TEXT,is_ignored INT)',
    'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,error_code TEXT,size_bytes INT,mtime_epoch INT)'
] as $sql)$db->executeStatement($sql);
$db->insert('scan_profiles',['id'=>1,'name'=>'Audio']);
$db->insert('scan_profiles',['id'=>2,'name'=>'Video']);
for($id=1;$id<=75;$id++) {
    $db->insert('scan_runs',[
        'id'=>$id,'profile_id'=>$id%2+1,
        'state'=>$id%3===0?'archived':'completed',
        'files_seen'=>10+$id,'files_indexed'=>($id%9)*100,
        'bytes_indexed'=>$id*1000,'duplicate_groups'=>$id%5,
        'error_count'=>$id%7===0?1:0,
        'started_at'=>sprintf('2026-11-%02d 10:00:00',1+$id%23),
        'finished_at'=>sprintf('2026-11-%02d 11:00:00',1+$id%23)
    ]);
    $db->insert('jobs',['id'=>$id,'kind'=>'scan','run_id'=>$id,'error_code'=>null]);
}
$repo=new ReportRepository($db);
$expected=array_map('intval',$db->fetchFirstColumn(
    'SELECT id FROM scan_runs ORDER BY COALESCE(files_indexed,0) DESC,id DESC'
));
$cursor=null;$seen=[];$batchSizes=[];
do {
    $page=$repo->history(null,$cursor,null,'files','desc');
    $ids=array_map('intval',array_column($page['rows'],'id'));
    ckReport(count($ids)<=25,'Exceeded scan-history page size.');
    array_push($seen,...$ids);
    $batchSizes[]=count($ids);
    $cursor=$page['next'];
    ckReport(count($batchSizes)<9,'Report paging never reached final: cursor='.(string)$cursor.' seen='.implode(',',array_slice($seen,-27)));
} while($cursor!==null);
ckReport($seen===$expected,
    'Scan history sorting duplicated or omitted rows across multiple pages.');
ckReport($batchSizes===[25,25,25],'Wrong run page sizes.');
$filter=$repo->history(1,null,null,'state','asc','completed');
ckReport(count($filter['rows'])>0,'Filtered scan history is empty.');
foreach($filter['rows'] as $row)
    ckReport((int)$row['profile_id']===1 && $row['state']==='completed',
        'Filter returned the wrong profile or state.');
$db->insert('directories',['id'=>1,'path'=>'/music','is_ignored'=>0]);
for($i=1;$i<=79;$i++) {
    $db->insert('file_entries',[
        'id'=>$i,'run_id'=>75,'directory_id'=>1,
        'filename'=>sprintf('file%03d.mp3',$i),
        'error_code'=>$i%3===0?'MOVED':'FILE_READ_FAILED',
        'size_bytes'=>($i%8)*1024,'mtime_epoch'=>1720000000+$i
    ]);
}
$states=$repo->flaggedStates(75);
ckReport(count($states)===2 && in_array('MOVED',$states,true),
    'The available flagged states were not discovered.');
$filePage=$repo->flaggedFiles(75,null,'size','desc','MOVED');
ckReport($filePage['total']===26 && count($filePage['rows'])===26,
    'Filtering flagged-file records lost matching results.');
$expectedFiles=array_map('intval',$db->fetchFirstColumn(
    "SELECT id FROM file_entries WHERE run_id=75 AND error_code='FILE_READ_FAILED' ".
    'ORDER BY size_bytes DESC,id DESC'
));
$seen=[];$cursor=null;
do {
    $page=$repo->flaggedFiles(75,$cursor,'size','desc','FILE_READ_FAILED');
    array_push($seen,...array_map('intval',array_column($page['rows'],'id')));
    $cursor=$page['next'];
}while($cursor!==null);
ckReport($seen===$expectedFiles,'Flagged file sorting ignored items beyond the first page.');
echo "PASS sorted/filtered scan history and per-run file status records with stable full-dataset paging\n";
