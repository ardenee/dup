<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Reports\Service\ScanHistoryCleanup;
use Doctrine\DBAL\DriverManager;

function checkCleanup(bool $ok,string $message):void
{
    if(!$ok) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
foreach([
    'CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,profile_id INTEGER,state TEXT,started_at TEXT,finished_at TEXT,FOREIGN KEY(profile_id) REFERENCES scan_profiles(id))',
    'CREATE TABLE directories(id INTEGER PRIMARY KEY,run_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE)',
    'CREATE TABLE file_entries(id INTEGER PRIMARY KEY,run_id INTEGER,directory_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE,FOREIGN KEY(directory_id) REFERENCES directories(id))',
    'CREATE TABLE duplicate_groups(id INTEGER PRIMARY KEY,run_id INTEGER,FOREIGN KEY(run_id) REFERENCES scan_runs(id) ON DELETE CASCADE)',
    'CREATE TABLE duplicate_group_members(id INTEGER PRIMARY KEY,group_id INTEGER,file_entry_id INTEGER,FOREIGN KEY(group_id) REFERENCES duplicate_groups(id) ON DELETE CASCADE,FOREIGN KEY(file_entry_id) REFERENCES file_entries(id))',
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY,run_id INTEGER,active_profile_id INTEGER,state TEXT,FOREIGN KEY(run_id) REFERENCES scan_runs(id))',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY,job_id INTEGER,code TEXT,FOREIGN KEY(job_id) REFERENCES jobs(id))',
    'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY,file_entry_id INTEGER,survivor_file_entry_id INTEGER,FOREIGN KEY(file_entry_id) REFERENCES file_entries(id))',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY,actor_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,created_at TEXT,context TEXT)'
] as $sql) $db->executeStatement($sql);
$db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Music'),(2,'Photos')");
foreach([[1,1,'completed'],[2,1,'completed'],[3,1,'completed'],[4,2,'completed'],[6,2,'failed']] as [$id,$p,$state]){
    $db->insert('scan_runs',['id'=>$id,'profile_id'=>$p,'state'=>$state]);
    $db->insert('directories',['id'=>$id,'run_id'=>$id]);
    $db->insert('file_entries',['id'=>$id,'run_id'=>$id,'directory_id'=>$id]);
    $db->insert('duplicate_groups',['id'=>$id,'run_id'=>$id]);
    $db->insert('duplicate_group_members',['id'=>$id,'group_id'=>$id,'file_entry_id'=>$id]);
    $db->insert('jobs',['id'=>$id,'run_id'=>$id,'active_profile_id'=>null,'state'=>'completed']);
    $db->insert('job_events',['id'=>$id,'job_id'=>$id,'code'=>'DONE']);
}
$svc=new ScanHistoryCleanup($db);
checkCleanup($svc->inspect(1)['is_current']===false,'Oldest scan misidentified as current');
checkCleanup($svc->inspect(3)['is_current']===true,'Current scan detection incorrect');
try{$svc->remove(1,9,'CLEAR');throw new RuntimeException('Wrong confirmation accepted');}
catch(DomainException){}
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs')===5,'Wrong confirmation modified records');
$old=$svc->remove(1,9,'DELETE');
checkCleanup(!$old['is_current'],'Historical scan deletion identified as current');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE run_id=1')===0,'Historical files not purged');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_groups WHERE run_id=1')===0,'Historical groups not purged');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_group_members WHERE id=1')===0,'Group membership orphaned');
checkCleanup($db->fetchOne('SELECT run_id FROM jobs WHERE id=1')===null,'Job was not detached');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE id=1')===1,'Job history erased');
checkCleanup($svc->inspect(3)['is_current']===true,'Current scan changed when older one removed');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE run_id=3')===1,'Live files deleted by older scan cleanup');
$db->executeStatement("INSERT INTO file_action_items(id,file_entry_id,survivor_file_entry_id) VALUES (1,2,NULL)");
checkCleanup(!$svc->inspect(2)['can_remove'],'Audit-linked historical run should be blocked');
try{$svc->remove(2,9,'DELETE');throw new RuntimeException('Audit-linked scan was deleted');}
catch(DomainException){}
$db->executeStatement('DELETE FROM file_action_items WHERE id=1');
$db->executeStatement("UPDATE jobs SET active_profile_id=1 WHERE id=3");
checkCleanup(!$svc->inspect(3)['can_remove'],'Active profile job not blocked');
$db->executeStatement('UPDATE jobs SET active_profile_id=NULL WHERE id=3');
try{$svc->remove(3,9,'DELETE');throw new RuntimeException('Current result cleared without CLEAR token');}
catch(DomainException){}
$current=$svc->remove(3,9,'CLEAR');
checkCleanup($current['is_current'],'Current run not recognized');
checkCleanup((string)$db->fetchOne('SELECT state FROM scan_runs WHERE id=2')==='archived','Older scan not archived');
checkCleanup((int)$db->fetchOne("SELECT COUNT(*) FROM scan_runs WHERE profile_id=1 AND state='completed'")===0,
    'Old results resurfaced as current');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE run_id=2')===1,
    'Archived scan was unexpectedly deleted');
checkCleanup($svc->inspect(2)['is_current']===false,'Archived run became current');
checkCleanup($svc->inspect(2)['can_remove'],'Archived run should be removable');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE run_id=4')===1,
    'Unrelated profile affected');
$db->executeStatement("INSERT INTO scan_runs VALUES(7,1,'completed',NULL,NULL)");
checkCleanup($svc->inspect(7)['is_current'],'A new scan must become current');
$svc->remove(2,9,'DELETE');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id=2')===0,'Archived run not deleted');
$svc->remove(6,9,'DELETE');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id=6')===0,'Failed scan not removable');
checkCleanup((int)$db->fetchOne('SELECT COUNT(*) FROM audit_events')===4,
    'Database scan removal audit not recorded');
echo "PASS scan cleanup: historical/current distinction, no snapshot resurrection, cascade cleanup, job retention, audit and safety blockers\n";
