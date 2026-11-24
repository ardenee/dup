<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\WorkerMaintenance;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Duplicates\Service\DuplicateVerificationService;
use Doctrine\DBAL\DriverManager;

function verifyWorker(bool $valid,string $reason):void {
    if(!$valid)throw new RuntimeException($reason);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE worker_heartbeats(id TEXT PRIMARY KEY,host_name TEXT,process_id INT,os_family TEXT,updated_at TEXT)',
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT,source_root TEXT,profile_type_id INT,hash_mode TEXT,minimum_bytes INT,enabled INT)',
    'CREATE TABLE profile_types(id INT PRIMARY KEY,extensions TEXT)',
    'CREATE TABLE profile_ignore_rules(profile_id INT,folder_name TEXT,enabled INT)',
    'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY AUTOINCREMENT,profile_id INT,state TEXT,settings_json TEXT,finished_at TEXT,error_count INT DEFAULT 0)',
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,run_id INT,'.
      'file_action_batch_id INT,active_profile_id INT UNIQUE,state TEXT,phase TEXT,heartbeat_at TEXT,'.
      'worker_token TEXT,finished_at TEXT,created_at TEXT,progress_current INT,progress_total INT,attempts INT,error_code TEXT)',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,code TEXT,severity TEXT,context TEXT,created_at TEXT)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,'.
      'entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)'
] as $query)$db->executeStatement($query);
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN started_at DATETIME DEFAULT NULL');
$db->insert('scan_profiles',['id'=>1,'name'=>'Media','source_root'=>sys_get_temp_dir(),
    'profile_type_id'=>1,'hash_mode'=>'size','minimum_bytes'=>0,'enabled'=>1]);
$db->insert('profile_types',['id'=>1,'extensions'=>'']);
$db->insert('scan_runs',['id'=>4,'profile_id'=>1,'state'=>'running']);
$recent=gmdate('Y-m-d H:i:s',time()-7);
$stale=gmdate('Y-m-d H:i:s',time()-1500);
$db->insert('worker_heartbeats',[
    'id'=>'old','host_name'=>'DSM','process_id'=>999,'os_family'=>'Linux','updated_at'=>$stale
]);
$db->insert('jobs',[
    'id'=>5,'kind'=>'scan','profile_id'=>1,'run_id'=>4,
    'active_profile_id'=>1,'state'=>'running','phase'=>'hashing',
    'heartbeat_at'=>$recent,'worker_token'=>'worker-A','created_at'=>$stale,
    'progress_current'=>110,'progress_total'=>500,'attempts'=>1
]);
$heartbeat=new WorkerHeartbeat($db);
$jobs=new JobStore($db,new DirectoryBrowser());
$maintenance=new WorkerMaintenance($db,$heartbeat,$jobs,new DuplicateVerificationService($db));

$report=$heartbeat->report();
verifyWorker($report['active']===0,'A stale process heartbeat was counted as a current worker.');
verifyWorker($report['jobs_responding']===1 && $report['state']==='working',
    'Current scan progress must override a stale worker heartbeat.');
verifyWorker($report['recovery_candidates']===0,
    'A currently progressing scan must never become a recovery candidate.');
try {
    $maintenance->recover(7);
    throw new RuntimeException('Recovered a scan which was actively progressing.');
} catch(DomainException $ex) {
    verifyWorker(str_contains($ex->getMessage(),'responding'),
        'The recovery safety guard rejected an active scan incorrectly.');
}
verifyWorker((string)$db->fetchOne('SELECT state FROM jobs WHERE id=5')==='running',
    'An active scan was lost during status inspection.');

$db->update('jobs',['heartbeat_at'=>$stale],['id'=>5]);
$report=$heartbeat->report();
verifyWorker($report['state']==='stalled' && $report['recovery_candidates']===1,
    'A genuinely overdue scan did not expose a recovery path.');
// A responsive worker can still own a job with an old progress counter;
// never recover while the CLI process is reporting.
$heartbeat->touch();
verifyWorker($heartbeat->report()['active']===1,'Process heartbeat registration failed.');
try {
    $maintenance->recover(7);
    throw new RuntimeException('Recovered while a CLI worker was reporting.');
} catch(DomainException $ex) {
    verifyWorker(str_contains($ex->getMessage(),'responding'),
        'The process heartbeat recovery guard was ignored.');
}
// Assert-running checkpoints must refresh BOTH job and process heartbeats
// without requiring a progress counter increment.
$jobs->registerWorkerHeartbeat($heartbeat);
$jobs->assertRunning(5,'worker-A');
verifyWorker($heartbeat->report()['state']==='working' &&
    $heartbeat->report()['jobs_responding']===1,
    'Checkpoints did not refresh job heartbeat while hashing.');
verifyWorker($db->fetchOne('SELECT heartbeat_at FROM jobs WHERE id=5')!==$stale,
    'The job heartbeat stayed stale after checkpoint.');
$heartbeat->stop();
$db->update('jobs',['heartbeat_at'=>$stale],['id'=>5]);
verifyWorker($heartbeat->report()['recovery_candidates']===1,
    'The stale scan should now be eligible for explicit recovery.');
$count=$maintenance->recover(7);
verifyWorker($count===1,'Explicit recovery failed to mark one stale scan.');
verifyWorker($db->fetchOne('SELECT state FROM jobs WHERE id=5')==='failed' &&
    $db->fetchOne('SELECT state FROM scan_runs WHERE id=4')==='failed',
    'Recovering a stale job did not update its scan record.');
verifyWorker((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='STALE_JOBS_RECOVERED'")===1,
    'Recovery operation was not audited.');
$stallContext=json_decode((string)$db->fetchOne(
    "SELECT context FROM job_events WHERE job_id=5 AND code='DUP-SCAN-WORKER-023'"),true);
verifyWorker(is_array($stallContext) &&
    ($stallContext['timeout_seconds']??null)===600 &&
    ($stallContext['files_completed']??null)===110 &&
    isset($stallContext['explanation'],$stallContext['last_heartbeat_at'],
        $stallContext['recommended_check']),
    'Stalled-worker error did not provide its last observed state and diagnosis.');
$failReason=(string)$db->fetchOne('SELECT last_failure_reason FROM jobs WHERE id=5');
verifyWorker(str_contains($failReason,'heartbeat') && str_contains($failReason,'110'),
    'The recovery error explanation was not persisted with the job.');
$workerSource=file_get_contents(dirname(__DIR__).'/src/Module/Jobs/Command/WorkJobsCommand.php');
verifyWorker(!str_contains($workerSource,'->recoverStalled('),
    'A background worker must not auto-fail another worker solely from a heartbeat timeout.');

$new=$maintenance->retry(5,7);
verifyWorker($new!==5 &&
    $db->fetchOne('SELECT kind FROM jobs WHERE id=?',[$new])==='scan' &&
    $db->fetchOne('SELECT state FROM jobs WHERE id=?',[$new])==='queued',
    'Retry did not create a fresh queued scan.');
verifyWorker($db->fetchOne('SELECT state FROM jobs WHERE id=5')==='failed',
    'Retry erased the failed scan history.');
try {
    $maintenance->retry($new,7);
    throw new RuntimeException('Retried a queued job, creating a duplicate scan.');
} catch(DomainException $ex) {
    verifyWorker(str_contains($ex->getMessage(),'failed or cancelled'),
        'Retry accepted a job that was not terminal.');
}
verifyWorker((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='JOB_RETRY_QUEUED'")===1,
    'Retry was not audited.');

$report=$heartbeat->report();
verifyWorker($report['state']==='waiting' && $report['queued_jobs']===1,
    'Queued work without a process should be shown as waiting, not failed.');
echo "PASS active scan evidence overrides stale worker, checkpoints refresh heartbeats, guarded recovery, audited retry and waiting state\n";
