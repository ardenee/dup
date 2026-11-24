<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Profiles\Service\IgnoreRuleService;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function ckIgnore(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT)',
    'CREATE TABLE profile_ignore_rules(id INTEGER PRIMARY KEY AUTOINCREMENT,profile_id INT,folder_name TEXT,enabled INT)',
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,state TEXT,phase TEXT,progress_current INT,created_at TEXT,attempts INT)',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)'
] as $sql)$db->executeStatement($sql);
foreach([1=>'Audio',2=>'Video'] as $id=>$name) {
    $db->insert('scan_profiles',['id'=>$id,'name'=>$name]);
    $db->insert('scan_runs',['id'=>$id,'profile_id'=>$id,'state'=>'completed']);
}
$db->insert('scan_runs',['id'=>3,'profile_id'=>1,'state'=>'archived']);
$rules=new IgnoreRuleService($db,new JobStore($db,new DirectoryBrowser()));
$global=$rules->save('@eaDir'."\n".'#recycle',3);
ckIgnore($global===2,'Global exclusions should recalculate both current profiles.');
ckIgnore(count($rules->rules())===2,'Global exclusions not persisted.');
$db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL");
$profile=$rules->save('Temporary'."\n".'Cache',3,1);
ckIgnore($profile===1,'Profile exclusion change queued other profiles.');
ckIgnore($rules->rules(1)===['Cache','Temporary'] || $rules->rules(1)===['Temporary','Cache'],
    'Profile exclusions not stored independently.');
ckIgnore($rules->rules(2)===[],'Other profile received unrelated exclusions.');
ckIgnore(count($rules->rules())===2,'Profile save removed global exclusions.');
$effective=$rules->effective(1);
foreach(['@eaDir','#recycle','Temporary','Cache','.dup-quarantine'] as $name)
    ckIgnore(in_array($name,$effective,true),'Effective rules missing '.$name);
ckIgnore(count($rules->effective(2))===3,
    'Other profile should inherit only two global exclusions and DUP internal exclusion.');
ckIgnore((int)$db->fetchOne(
    "SELECT COUNT(*) FROM jobs WHERE kind='rebuild-duplicates' AND profile_id=1")===2,
    'Targeted rebuild job missing.');
ckIgnore((int)$db->fetchOne(
    "SELECT COUNT(*) FROM jobs WHERE kind='rebuild-duplicates' AND profile_id=2")===1,
    'An extra unrelated rebuild job was queued.');
$db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL");
$rules->save('',3,1);
ckIgnore($rules->rules(1)===[],'Profile override clearing failed.');
ckIgnore(count($rules->rules())===2,'Clearing overrides removed global defaults.');
try {
    $rules->save('foo',3,123);
    throw new RuntimeException('Nonexistent profile accepted.');
} catch(DomainException) {}
ckIgnore((int)$db->fetchOne(
    "SELECT COUNT(*) FROM audit_events WHERE action='IGNORE_RULES_CHANGED'")===3,
    'Global/profile change auditing absent.');
echo "PASS profile-specific and global ignore rules, inheritance, independent updates, target-only rebuild and audit\n";
