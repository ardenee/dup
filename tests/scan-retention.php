<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Reports\Service\ScanHistoryCleanup;
use App\Module\Reports\Service\ScanRetentionService;
use Doctrine\DBAL\DriverManager;

function ckRetain(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
foreach([
    'CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,profile_id INTEGER,state TEXT,started_at TEXT,finished_at TEXT,FOREIGN KEY(profile_id) REFERENCES scan_profiles(id))',
    'CREATE TABLE directories(id INTEGER PRIMARY KEY,run_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id))',
    'CREATE TABLE file_entries(id INTEGER PRIMARY KEY,run_id INTEGER,directory_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id),FOREIGN KEY(directory_id) REFERENCES directories(id))',
    'CREATE TABLE duplicate_groups(id INTEGER PRIMARY KEY,run_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id))',
    'CREATE TABLE duplicate_group_members(id INTEGER PRIMARY KEY,group_id INTEGER,file_entry_id INTEGER,FOREIGN KEY(group_id) REFERENCES duplicate_groups(id) ON DELETE CASCADE,FOREIGN KEY(file_entry_id) REFERENCES file_entries(id))',
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY,run_id INTEGER,active_profile_id INTEGER,state TEXT,FOREIGN KEY(run_id) REFERENCES scan_runs(id))',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY,job_id INTEGER,code TEXT,FOREIGN KEY(job_id) REFERENCES jobs(id))',
    'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY,file_entry_id INTEGER,survivor_file_entry_id INTEGER,FOREIGN KEY(file_entry_id) REFERENCES file_entries(id))',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,created_at TEXT,context TEXT)'
] as $sql)$db->executeStatement($sql);
$db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Music'),(2,'Photos')");
foreach([
    [1,1,'archived'],[2,1,'completed'],[3,1,'completed'],
    [4,2,'archived'],[5,2,'failed'],[6,2,'queued'],[7,2,'completed']
] as [$id,$profile,$state]) {
    $old=$id!==3 && $id!==7;
    $db->insert('scan_runs',[
        'id'=>$id,'profile_id'=>$profile,'state'=>$state,
        'started_at'=>$old?'2020-01-01 08:00:00':gmdate('Y-m-d H:i:s'),
        'finished_at'=>$old?'2020-01-01 09:00:00':gmdate('Y-m-d H:i:s')
    ]);
    $db->insert('directories',['id'=>$id,'run_id'=>$id]);
    $db->insert('file_entries',['id'=>$id,'run_id'=>$id,'directory_id'=>$id]);
    $db->insert('duplicate_groups',['id'=>$id,'run_id'=>$id]);
    $db->insert('duplicate_group_members',['id'=>$id,'group_id'=>$id,'file_entry_id'=>$id]);
    $db->insert('jobs',['id'=>$id,'run_id'=>$id,'active_profile_id'=>null,'state'=>$id===6?'queued':'completed']);
    $db->insert('job_events',['id'=>$id,'job_id'=>$id,'code'=>'DONE']);
}
$db->insert('file_action_items',['id'=>1,'file_entry_id'=>2,'survivor_file_entry_id'=>null]);
$service=new ScanRetentionService($db,new ScanHistoryCleanup($db));
ckRetain($service->estimate(90)===3,
    'Only 3 historical, unreferenced scans should qualify.');
ckRetain($service->estimate(90,1)===1,'Profile filtering did not restrict scan cleanup.');
ckRetain($service->estimate(90,2)===2,'Profile filtering dropped eligible archived scans.');
try {
    $service->prune(90,null,4,'REMOVE');
    throw new RuntimeException('Unconfirmed scan cleanup accepted.');
} catch(DomainException) {}
try {
    $service->estimate(1);
    throw new RuntimeException('Invalid retention period accepted.');
} catch(DomainException) {}
ckRetain($service->prune(90,1,4,'PRUNE SCANS')===1,
    'Filtered historical scan cleanup removed wrong number.');
ckRetain((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id IN (2,3)')===2,
    'Referenced or current scan was removed.');
ckRetain($service->prune(90,null,4,'PRUNE SCANS')===2,
    'Remaining archived/failed runs not removed.');
ckRetain($service->estimate(90)===0,'Eligible old scans remain.');
ckRetain((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id IN (2,3,6,7)')===4,
    'Retained current/referenced/pending snapshots were removed.');
ckRetain((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE run_id IN (2,3,6,7)')===4,
    'Protected file records were deleted.');
ckRetain((int)$db->fetchOne('SELECT COUNT(*) FROM job_events')===7,
    'Job-event history was lost.');
ckRetain((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='SCAN_HISTORY_REMOVED'")===3,
    'Bulk scan removal audit missing.');
echo "PASS historical scan pruning, current-run guard, action dependencies, profile filtering, preserved jobs and audit\n";
