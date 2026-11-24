<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Jobs\Service\JobHistoryCleanup;
use Doctrine\DBAL\DriverManager;

function ckJobClean(bool $okay,string $message):void {
    if(!$okay)throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
foreach([
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT)',
    'CREATE TABLE jobs(id INT PRIMARY KEY,kind TEXT,profile_id INT,run_id INT,active_profile_id INT,file_action_batch_id INT,state TEXT,finished_at TEXT,history_prunable_at TEXT)',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT,FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)'
] as $sql)$db->executeStatement($sql);
$db->insert('scan_profiles',['id'=>1,'name'=>'Music']);
$db->insert('scan_runs',['id'=>4,'profile_id'=>1,'state'=>'completed']);
$db->insert('jobs',[
    'id'=>1,'kind'=>'scan','profile_id'=>1,'run_id'=>4,
    'state'=>'completed','finished_at'=>'2020-02-03 01:00:00'
]);
$db->insert('jobs',[
    'id'=>2,'kind'=>'file-action','profile_id'=>1,'run_id'=>4,
    'state'=>'completed','file_action_batch_id'=>42,'finished_at'=>'2020-02-03 01:00:00'
]);
$db->insert('jobs',[
    'id'=>3,'kind'=>'scan','profile_id'=>1,'run_id'=>4,
    'active_profile_id'=>1,'state'=>'running'
]);
$db->insert('jobs',[
    'id'=>5,'kind'=>'duplicate-verify','profile_id'=>1,'run_id'=>4,
    'state'=>'failed','finished_at'=>'2020-02-03 01:00:00'
]);
$db->insert('job_events',[
    'job_id'=>5,'severity'=>'error','code'=>'WORKER_STALLED',
    'context'=>'{}','created_at'=>'2020-02-03'
]);
// An action job can only be cleaned after a separate verified, audited batch
// retirement has marked its recovery history as resolved.
$db->insert('jobs',[
    'id'=>4,'kind'=>'file-move','profile_id'=>1,'run_id'=>4,
    'file_action_batch_id'=>null,'history_prunable_at'=>'2020-01-04 00:00:00',
    'state'=>'completed','finished_at'=>'2020-01-01 01:00:00'
]);
$db->insert('job_events',[
    'job_id'=>1,'severity'=>'info','code'=>'COMPLETE',
    'context'=>'{}','created_at'=>'2020-02-03'
]);
$clean=new JobHistoryCleanup($db);
ckJobClean($clean->inspect(1)['can_remove'],'Completed scan job should be removable.');
ckJobClean(!$clean->inspect(2)['can_remove'],'File-action job must be protected.');
ckJobClean($clean->inspect(4)['can_remove'],
    'A marked fully-resolved action job should be removable.');
ckJobClean(!$clean->inspect(3)['can_remove'],'Running job must be protected.');
ckJobClean($clean->inspect(5)['can_remove'],
    'A failed SHA-256 verification job should have removable history.');
$clean->remove(5,9,'DELETE');
ckJobClean(!$db->fetchOne('SELECT id FROM jobs WHERE id=5'),
    'A failed verification job could not be deleted.');
ckJobClean(!$db->fetchOne('SELECT id FROM job_events WHERE job_id=5'),
    'Verification events survived a confirmed deletion.');
foreach([2,3] as $id) {
    try {
        $clean->remove($id,9,'DELETE');
        throw new RuntimeException('Unsafe job removed: '.$id);
    } catch(DomainException) {}
}
try {
    $clean->remove(1,9,'REMOVE');
    throw new RuntimeException('Accepted invalid job deletion confirmation.');
} catch(DomainException) {}
$clean->remove(1,9,'DELETE');
ckJobClean(!$db->fetchOne('SELECT id FROM jobs WHERE id=1'),
    'Completed job log not removed.');
ckJobClean((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE job_id=1')===0,
    'Deleted job events not cascaded.');
ckJobClean((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id=4')===1,
    'Deleting job log removed the referenced scan snapshot.');
ckJobClean((int)$db->fetchOne('SELECT COUNT(*) FROM jobs WHERE id IN (2,3)')===2,
    'Protected jobs were modified.');
for($id=100;$id<700;$id++) {
    $db->insert('jobs',[
        'id'=>$id,'kind'=>$id%2?'scan':'rebuild-duplicates','profile_id'=>1,
        'run_id'=>4,'state'=>'failed','finished_at'=>'2020-01-01 01:00:00'
    ]);
}
$db->insert('jobs',[
    'id'=>700,'kind'=>'scan','profile_id'=>1,'run_id'=>4,
    'state'=>'completed','finished_at'=>gmdate('Y-m-d H:i:s')
]);
ckJobClean($clean->estimate(90)===601,'Eligible old-job count incorrect.');
try {
    $clean->prune(1,9,'PRUNE');
    throw new RuntimeException('Accepted unsafe retention period.');
} catch(DomainException) {}
try {
    $clean->prune(90,9,'DELETE');
    throw new RuntimeException('Accepted unconfirmed batch removal.');
} catch(DomainException) {}
ckJobClean($clean->prune(90,9,'PRUNE')===500,'Batch cleanup limit exceeded or short.');
ckJobClean($clean->estimate(90)===101,'Remaining eligible jobs not counted correctly.');
ckJobClean($clean->prune(90,9,'PRUNE')===101,'Second page of cleanup failed.');
ckJobClean($clean->estimate(90)===0,'Old eligible job logs remain.');
ckJobClean(!$db->fetchOne('SELECT id FROM jobs WHERE id=4'),
    'The safely retired action job was not eligible for pruning.');
ckJobClean((int)$db->fetchOne('SELECT COUNT(*) FROM jobs WHERE id IN (2,3,700)')===3,
    'Recent/protected job logs were lost.');
ckJobClean((int)$db->fetchOne('SELECT COUNT(*) FROM scan_runs WHERE id=4')===1,
    'Pruning jobs affected scan results.');
ckJobClean((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action LIKE 'JOB_HISTORY_%'")===4,
    'Cleanup audit records missing.');
echo "PASS job deletion, audited 500-row prune, current scan preservation, action/active guards and confirmation\n";
