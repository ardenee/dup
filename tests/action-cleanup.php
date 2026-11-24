<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\FileActions\Service\FileActionHistoryCleanup;
use Doctrine\DBAL\DriverManager;

function ckActionClean(bool $yes,string $message):void {
    if(!$yes) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
foreach([
    'CREATE TABLE file_action_batches(id INT PRIMARY KEY,profile_id INT,action_kind TEXT,state TEXT,completed_at TEXT)',
    'CREATE TABLE file_action_items(id INT PRIMARY KEY,batch_id INT,file_entry_id INT,state TEXT,FOREIGN KEY(batch_id) REFERENCES file_action_batches(id) ON DELETE CASCADE)',
    'CREATE TABLE jobs(id INT PRIMARY KEY,file_action_batch_id INT,state TEXT,history_prunable_at TEXT)',
    'CREATE TABLE job_events(id INT PRIMARY KEY,job_id INT,code TEXT)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,created_at TEXT,context TEXT)'
] as $sql)$db->executeStatement($sql);
$old='2020-01-02 12:00:00';$today=gmdate('Y-m-d H:i:s');
foreach([
    [1,1,'rename','completed',$old,'renamed','completed'],
    [2,1,'link','restored',$old,'restored','completed'],
    [3,1,'quarantine','completed',$old,'quarantined','completed'],
    [4,1,'move','partial',$old,'moved','completed'],
    [5,2,'bulk_link','restored',$old,'linked','completed'],
    [6,2,'rename','completed',$old,'renamed','running'],
    [7,2,'rename','completed',$today,'renamed','completed'],
    [8,2,'bulk_quarantine','restored',$old,'restored','completed'],
    [9,1,'rename','needs_review',$old,'renamed','failed']
] as [$id,$profile,$kind,$state,$finished,$itemState,$jobState]) {
    $db->insert('file_action_batches',[
        'id'=>$id,'profile_id'=>$profile,'action_kind'=>$kind,
        'state'=>$state,'completed_at'=>$finished
    ]);
    $db->insert('file_action_items',[
        'id'=>$id,'batch_id'=>$id,'file_entry_id'=>$id,'state'=>$itemState
    ]);
    $db->insert('jobs',['id'=>$id,'file_action_batch_id'=>$id,'state'=>$jobState]);
    $db->insert('job_events',['id'=>$id,'job_id'=>$id,'code'=>'DONE']);
}
$service=new FileActionHistoryCleanup($db);
ckActionClean($service->inspect(1)['can_remove'],'Completed rename cannot be removed.');
ckActionClean($service->inspect(2)['can_remove'],'Restored link cannot be removed.');
foreach([3,4,5,6,9] as $id)
    ckActionClean(!$service->inspect($id)['can_remove'],
        'Unresolved/active action incorrectly removable: '.$id);
ckActionClean($service->estimate(90)===3,'Eligible old action count incorrect.');
ckActionClean($service->estimate(90,1)===2,'Profile 1 action count incorrect.');
ckActionClean($service->estimate(90,2)===1,'Profile 2 action count incorrect.');
try {
    $service->remove(1,9,'PRUNE');
    throw new RuntimeException('Unconfirmed file-action deletion accepted.');
} catch(DomainException) {}
try {
    $service->remove(3,9,'DELETE');
    throw new RuntimeException('Active quarantine record deleted.');
} catch(DomainException) {}
$service->remove(1,9,'DELETE');
ckActionClean(!$db->fetchOne('SELECT id FROM file_action_batches WHERE id=1'),
    'Completed rename batch did not delete.');
ckActionClean((int)$db->fetchOne('SELECT COUNT(*) FROM file_action_items WHERE batch_id=1')===0,
    'Resolved batch file-action items not cleaned.');
ckActionClean($db->fetchOne('SELECT file_action_batch_id FROM jobs WHERE id=1')===null,
    'Job still points to nonexistent resolved batch.');
ckActionClean($db->fetchOne('SELECT history_prunable_at FROM jobs WHERE id=1')!==null,
    'Resolved action job was not marked as safely prunable.');
ckActionClean($db->fetchOne('SELECT history_prunable_at FROM jobs WHERE id=3')===null,
    'Unresolved action job was incorrectly marked prunable.');
ckActionClean((int)$db->fetchOne('SELECT COUNT(*) FROM job_events')===9,
    'Action job event logs were removed.');
try {
    $service->prune(90,null,9,'PRUNE');
    throw new RuntimeException('Unconfirmed action pruning accepted.');
} catch(DomainException) {}
ckActionClean($service->prune(90,null,9,'PRUNE ACTIONS')===2,
    'Eligible restored action histories not pruned.');
ckActionClean($service->estimate(90)===0,'Eligible old action records remain.');
ckActionClean($db->fetchOne('SELECT history_prunable_at FROM jobs WHERE id IN (2,8) LIMIT 1')!==null,
    'Fully restored action jobs were not marked for job-log cleanup.');
ckActionClean($db->fetchOne('SELECT history_prunable_at FROM jobs WHERE id=5')===null,
    'An unrestored action job was incorrectly marked for cleanup.');
ckActionClean((int)$db->fetchOne('SELECT COUNT(*) FROM file_action_batches WHERE id IN (3,4,5,6,7,9)')===6,
    'Unresolved or recently completed actions were deleted.');
ckActionClean((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_ACTION_HISTORY_REMOVED'")===3,
    'Action cleanup auditing missing.');
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
foreach([
    'path'=>static fn($name,$args=[])=>'/'.$name,
    'asset'=>static fn($name)=>'/assets/'.$name,
    'csrf_token'=>static fn($name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$app=['app'=>['user'=>null,'environment'=>'test','flashes'=>[]]];
$html=$twig->render('actions/remove.html.twig',$app+['batch'=>$service->inspect(7)]);
ckActionClean(str_contains($html,'Type DELETE'),'Eligible batch removal form missing.');
$blocked=$twig->render('actions/remove.html.twig',$app+['batch'=>$service->inspect(3)]);
ckActionClean(str_contains($blocked,'History is protected') &&
    !str_contains($blocked,'name="confirm"'),
    'Protected batch incorrectly exposes action removal.');
$bulk=$twig->render('actions/cleanup.html.twig',$app+[
    'profile'=>null,'days'=>90,'eligible'=>3,'profiles'=>[]
]);
ckActionClean(str_contains($bulk,'PRUNE ACTIONS') && str_contains($bulk,'50 batches'),
    'Bulk action-history cleanup UI missing.');
echo "PASS old resolved action cleanup, recovery guards, preserved job/audit events, CSRF confirmation and batch UI\n";
