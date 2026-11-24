<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Repository\FileActionHistoryRepository;
use Doctrine\DBAL\DriverManager;

function checkBatches(bool $ok,string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach ([
    'CREATE TABLE users (id INTEGER PRIMARY KEY,username TEXT)',
    'CREATE TABLE scan_profiles (id INTEGER PRIMARY KEY,name TEXT)',
    'CREATE TABLE file_action_batches (id INTEGER PRIMARY KEY,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,completed_at TEXT,error_code TEXT)',
    'CREATE TABLE file_action_items (id INTEGER PRIMARY KEY,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,state TEXT,result_code TEXT,expected_size INT,expected_sha256 TEXT)'
] as $sql) $db->executeStatement($sql);
$db->executeStatement("INSERT INTO users VALUES (7,'admin')");
$db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Audio'),(2,'Pictures')");
for($id=1;$id<=65;$id++){
    $profile=$id%2+1;
    $kind=['quarantine','link','move','rename'][$id%4];
    $state=$id%9===0?'needs_review':'completed';
    $db->insert('file_action_batches',[
        'id'=>$id,'actor_id'=>7,'profile_id'=>$profile,'action_kind'=>$kind,
        'state'=>$state,'requested_at'=>'2026-11-24 08:00:00',
        'completed_at'=>'2026-11-24 08:01:00','error_code'=>$state==='needs_review'?'FILE_CHANGED':null
    ]);
    $db->insert('file_action_items',[
        'id'=>$id,'batch_id'=>$id,'file_entry_id'=>$id,
        'source_path'=>'/media/'.$id.'.mp3','target_path'=>'/target/'.$id.'.mp3',
        'state'=>$state==='needs_review'?'failed':'renamed',
        'result_code'=>$state==='needs_review'?'FILE_CHANGED':null,
        'expected_size'=>100,'expected_sha256'=>null
    ]);
}
$db->insert('file_action_items',[
    'id'=>66,'batch_id'=>63,'file_entry_id'=>66,
    'source_path'=>'/media/63-B.mp3','target_path'=>'/target/63-B.mp3',
    'state'=>'failed','result_code'=>'NAME_CONFLICT',
    'expected_size'=>110,'expected_sha256'=>null
]);
$history=new FileActionHistoryRepository($db);
$p1=$history->page();
checkBatches($p1['total']===65 && count($p1['rows'])===25,'First page total or size');
checkBatches((int)$p1['rows'][0]['id']===65 && (int)$p1['rows'][24]['id']===41,'First page order');
checkBatches($p1['next']===41 && $p1['previous']===null,'First page cursors');
$p2=$history->page(null,null,null,$p1['next']);
checkBatches(count($p2['rows'])===25 && (int)$p2['rows'][0]['id']===40,'Second page order');
checkBatches($p2['previous']===40 && $p2['next']===16,'Middle cursors');
$p3=$history->page(null,null,null,$p2['next']);
checkBatches(count($p3['rows'])===15 && $p3['next']===null,'Final page bounds');
$back=$history->page(null,null,null,null,$p3['previous']);
checkBatches((int)$back['rows'][0]['id']===40 && (int)$back['rows'][24]['id']===16,'Reverse cursor');
$filter=$history->page(2,'rename','needs_review');
checkBatches($filter['total']===2 && count($filter['rows'])===2,'Combined filters');
checkBatches($filter['next']===null && $filter['previous']===null,'Filtered cursors');
checkBatches($history->page(null,'link')['total']===17,'Type filter');
checkBatches($history->page(null,null,'needs_review')['total']===7,'Status filter');
checkBatches($history->page(2)['total']===33,'Profile filter');
checkBatches($history->page(null,null,null,9999)['rows'][0]['id']===65,'High cursor');
checkBatches(!$history->page(null,null,null,1)['rows'],'Past-final cursor');
try {
    $history->page(null,null,null,1,2);
    throw new RuntimeException('Accepted conflicting cursors');
} catch(InvalidArgumentException) {}
$detail=$history->renameDetails(63);
checkBatches($detail!==false && count($detail['items'])===2,'Full rename batch items missing');
checkBatches($detail['actor_name']==='admin','Audit actor missing');
checkBatches($detail['items'][1]['result_code']==='NAME_CONFLICT','Per-file failure hidden');
checkBatches($history->renameDetails(62)===false,'Wrong operation included as rename');
checkBatches(count($history->profiles())===2,'Profile list incomplete');
checkBatches(in_array('needs_review',$history->states(),true),'State selector incomplete');
$sorted=$history->page(null,null,null,null,null,'files','desc');
checkBatches((int)$sorted['rows'][0]['id']===63 &&
    (int)$sorted['rows'][0]['item_count']===2,
    'Sorting by file count must work across all matching action batches.');
$ascState=$history->page(null,null,null,null,null,'status','asc');
checkBatches(count($ascState['rows'])===25 &&
    $ascState['total']===65,'Sorted action history changed the total count.');
echo "PASS file actions: 65 batches, cursor paging, reverse paging, combined filters, rename details and errors\n";
