<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Search\Repository\ActionHistoryRepository;
use Doctrine\DBAL\DriverManager;

function assertHistory(bool $condition,string $message):void
{
    if(!$condition) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs (id INT PRIMARY KEY,finished_at TEXT)',
    'CREATE TABLE file_entries (id INT PRIMARY KEY,run_id INT)',
    'CREATE TABLE file_action_batches (id INT PRIMARY KEY,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,completed_at TEXT,error_code TEXT)',
    'CREATE TABLE file_action_items (id INT PRIMARY KEY,batch_id INT,file_entry_id INT,state TEXT,source_path TEXT,target_path TEXT,link_target_path TEXT,expected_size INT,result_code TEXT)'
] as $sql) $db->executeStatement($sql);
$db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Audio'),(2,'Docs')");
$db->executeStatement("INSERT INTO scan_runs VALUES (1,'2026-10-01'),(2,'2026-11-01')");
for($id=1;$id<=65;$id++) {
    $profile=$id%5===0?2:1;
    $kind=['move','link','quarantine'][($id-1)%3];
    $restored=$id%7===0;
    $source=$id===12?'/media/special_%_title.wav':
        ($id===1?'/media/CASE-ONE.mp3':'/media/case-'.sprintf('%03d',$id).'.mp3');
    $db->insert('file_entries',['id'=>$id,'run_id'=>$id<33?1:2]);
    $db->insert('file_action_batches',[
        'id'=>$id,'profile_id'=>$profile,'action_kind'=>$kind,
        'state'=>$restored?'restored':'completed',
        'requested_at'=>'2026-10-31 12:00:00',
        'completed_at'=>'2026-10-31 13:00:00','error_code'=>null
    ]);
    $db->insert('file_action_items',[
        'id'=>$id,'batch_id'=>$id,'file_entry_id'=>$id,
        'state'=>$restored?'restored':($kind==='move'?'moved':($kind==='link'?'linked':'quarantined')),
        'source_path'=>$source,'target_path'=>'/dest/target-'.$id,
        'link_target_path'=>$kind==='link'?'/survivor/link-'.$id:null,
        'expected_size'=>1024,'result_code'=>null
    ]);
}
$history=new ActionHistoryRepository($db);
$page=$history->find('',null,false);
assertHistory($page['total']===65,'Cross-run history count wrong');
assertHistory(count($page['rows'])===50 && (int)$page['rows'][0]['id']===65,'History page ordering wrong');
assertHistory($page['next']!==null && $page['previous']===null,'First page cursors wrong');
$next=$history->find('',null,false,$page['next']);
assertHistory(count($next['rows'])===15 && (int)$next['rows'][0]['id']===15,
    'Old scan actions missing from second page');
assertHistory($next['previous']!==null && $next['next']===null,'Second page cursors wrong');
$back=$history->find('',null,false,null,$next['previous']);
assertHistory(count($back['rows'])===50 && (int)$back['rows'][0]['id']===65,
    'Reverse paging did not restore recent records');
assertHistory($history->find('',2,false)['total']===13,'Profile filter wrong');
assertHistory($history->find('special_%',null,false)['total']===1,
    'LIKE wildcard search incorrectly matched other records');
assertHistory($history->find('CASE',null,true)['total']===1,
    'Case-sensitive history search did not preserve original case');
assertHistory($history->find('CASE',null,false)['total']===64,
    'Case-insensitive history search wrong');
assertHistory($history->find('/survivor/link-14',null,false)['total']===1,
    'Link-target search failed');
assertHistory($history->find('target-64',null,false)['total']===1,
    'Move-target search failed');
$restored=$history->find('case-007',null,false);
assertHistory($restored['total']===1 && $restored['rows'][0]['item_state']==='restored' &&
    (int)$restored['rows'][0]['run_id']===1,'Restored historical actions lost old run data');
try {
    $history->find('',null,false,1,2);
    throw new RuntimeException('Accepted simultaneous history cursors');
} catch(InvalidArgumentException) {}
echo "PASS 65 historical actions, older scans, restored state, 50-item paging, reverse paging, profiles, link targets, escaped wildcards, case-sensitive queries\n";
