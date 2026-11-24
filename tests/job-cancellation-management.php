<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\WorkerMaintenance;
use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\JobHistoryCleanup;
use App\Module\Duplicates\Service\DuplicateVerificationService;
use Doctrine\DBAL\DriverManager;

function checkCancel(bool $okay,string $what):void
{
    if(!$okay)throw new RuntimeException($what);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
foreach([
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,finished_at TEXT,error_count INT DEFAULT 0)',
    'CREATE TABLE worker_heartbeats(id TEXT PRIMARY KEY,host_name TEXT,process_id INT,os_family TEXT,updated_at TEXT)',
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,'.
        'file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,'.
        'progress_bytes_current INT DEFAULT 0,progress_bytes_total INT,progress_detail TEXT,'.
        'last_progress_at TEXT,last_failure_reason TEXT,worker_token TEXT,error_code TEXT,'.
        'heartbeat_at TEXT,started_at TEXT,finished_at TEXT,history_prunable_at TEXT)',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT,FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE)',
    'CREATE TABLE duplicate_verification_hashes(job_id INT,file_entry_id INT,sha256 TEXT)',
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)'
] as $sql)$db->executeStatement($sql);
$db->insert('scan_profiles',['id'=>1,'name'=>'Movies']);
$db->insert('scan_profiles',['id'=>2,'name'=>'Music']);
$db->insert('scan_runs',['id'=>4,'profile_id'=>1,'state'=>'completed','finished_at'=>'2026-11-21 08:00:00']);
$db->insert('scan_runs',['id'=>5,'profile_id'=>2,'state'=>'running']);
$stale=gmdate('Y-m-d H:i:s',time()-172800);
$recent=gmdate('Y-m-d H:i:s',time()-15);
$db->insert('jobs',[
    'id'=>8,'kind'=>'duplicate-verify','profile_id'=>1,'active_profile_id'=>1,
    'run_id'=>4,'state'=>'cancelling','phase'=>'hashing_duplicates',
    'progress_current'=>0,'progress_total'=>26,'progress_bytes_current'=>0,
    'progress_bytes_total'=>2512555861,'progress_detail'=>'Preparing SHA-256 verification',
    'worker_token'=>'old-worker','heartbeat_at'=>$stale,'started_at'=>$stale
]);
$db->insert('job_events',[
    'job_id'=>8,'severity'=>'warning','code'=>'CANCELLATION_REQUESTED',
    'context'=>'{}','created_at'=>$stale
]);
$db->insert('duplicate_verification_hashes',[
    'job_id'=>8,'file_entry_id'=>22,'sha256'=>str_repeat('a',64)
]);
$db->insert('jobs',[
    'id'=>9,'kind'=>'scan','profile_id'=>2,'active_profile_id'=>2,'run_id'=>5,
    'state'=>'running','phase'=>'enumerating','worker_token'=>'working-worker',
    'heartbeat_at'=>$recent,'started_at'=>$recent,'progress_current'=>0
]);
$store=new JobStore($db,new DirectoryBrowser());
$heartbeat=new WorkerHeartbeat($db);
$maintenance=new WorkerMaintenance($db,$heartbeat,$store,new DuplicateVerificationService($db));
$history=new JobHistoryCleanup($db);

checkCancel($store->requestCancel(8)===true,
    'Repeated cancellation must be idempotent for pending jobs.');
checkCancel($db->fetchOne('SELECT state FROM jobs WHERE id=8')==='cancelling',
    'A repeated cancellation unexpectedly cleared the active lease.');
try {
    $maintenance->resolveOne(9,4);
    throw new RuntimeException('A responsive worker was reset.');
} catch(DomainException $e) {
    checkCancel(str_contains($e->getMessage(),'10 minutes'),
        'Recent job should fail with a useful message.');
}
checkCancel($maintenance->ownerActive(8)===false,
    'A job without a recorded owner should not be attributed to a different live worker.');
$db->insert('job_events',[
    'job_id'=>8,'severity'=>'info','code'=>'WORKER_CLAIMED',
    'context'=>json_encode([
        'worker_host'=>gethostname(),
        'worker_pid'=>512345
    ],JSON_THROW_ON_ERROR),
    'created_at'=>$stale
]);
// Only the worker that CLAIMED job 8 can block its resolution.
$db->insert('worker_heartbeats',[
    'id'=>'owner','host_name'=>gethostname(),'process_id'=>512345,
    'os_family'=>PHP_OS_FAMILY,'updated_at'=>gmdate('Y-m-d H:i:s')
]);
checkCancel($maintenance->ownerActive(8)===true,
    'A responding job owner was not detected.');
try {
    $maintenance->resolveOne(8,4);
    throw new RuntimeException('A responsive original owner was ignored.');
} catch(DomainException $e) {
    checkCancel(str_contains($e->getMessage(),'claimed this job'),
        'Responding job owner should block stale resolution with a clear reason.');
}
checkCancel($db->fetchOne('SELECT state FROM jobs WHERE id=8')==='cancelling',
    'Blocked recovery changed the job anyway.');
$db->delete('worker_heartbeats',['id'=>'owner']);
checkCancel(!$maintenance->ownerActive(8),'Stopped original owner still blocks resolution.');

// A different, freshly registered worker must NOT block recovery of job 8.
$heartbeat->touch();
checkCancel($heartbeat->report()['active']===1,
    'Unrelated test worker did not register as active.');
checkCancel($maintenance->ownerActive(8)===false,
    'A different active worker was incorrectly identified as job owner.');
$resolved=$maintenance->resolveOne(8,4);
$heartbeat->stop();
checkCancel($resolved==='cancelled','A pending cancellation must finish cancelled, not failed.');
$job=$db->fetchAssociative('SELECT state,phase,error_code,worker_token,active_profile_id,finished_at FROM jobs WHERE id=8');
checkCancel($job['state']==='cancelled' && $job['phase']==='cancelled' &&
    $job['error_code']==='CANCEL_RESOLVED_AFTER_STALL' &&
    $job['worker_token']===null && $job['active_profile_id']===null &&
    $job['finished_at']!==null,
    'Resolving cancellation did not release its worker lease and profile lock.');
checkCancel($db->fetchOne('SELECT state FROM scan_runs WHERE id=4')==='completed',
    'Resolving SHA-256 verification must not overwrite successful scan results.');
checkCancel((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_verification_hashes WHERE job_id=8')===0,
    'Staged verification hashes were not cleaned.');
$context=json_decode((string)$db->fetchOne(
    "SELECT context FROM job_events WHERE job_id=8 AND code='CANCEL_RESOLVED_AFTER_STALL'"
),true);
checkCancel(($context['final_state']??null)==='cancelled' &&
    ($context['files_total']??null)===26 &&
    ($context['previous_state']??null)==='cancelling',
    'Stalled cancellation did not record a readable diagnostic event.');
checkCancel((int)$db->fetchOne(
    "SELECT COUNT(*) FROM audit_events WHERE action='STALLED_JOB_RESOLVED'"
)===1,'An explicit resolution must be audited.');
try {
    $store->assertRunning(8,'old-worker');
    throw new RuntimeException('Resolved job still accepts its old worker lease.');
} catch(LostLease) {}
checkCancel($db->fetchOne('SELECT state FROM jobs WHERE id=9')==='running',
    'Targeted recovery modified another job.');
try {
    $maintenance->resolveOne(8,4);
    throw new RuntimeException('Already resolved cancellation accepted twice.');
} catch(DomainException) {}
checkCancel($history->inspect(8)['can_remove']===true,
    'A resolved verification must be removable from job history.');
$history->remove(8,4,'DELETE');
checkCancel(!$db->fetchOne('SELECT id FROM jobs WHERE id=8') &&
    !$db->fetchOne('SELECT id FROM job_events WHERE job_id=8'),
    'Deleting a resolved job failed to delete its log/events.');
checkCancel($db->fetchOne('SELECT state FROM scan_runs WHERE id=4')==='completed',
    'Deleting a resolved verification job unexpectedly deleted scan results.');
checkCancel((int)$db->fetchOne(
    "SELECT COUNT(*) FROM audit_events WHERE action IN ('STALLED_JOB_RESOLVED','JOB_HISTORY_REMOVED')"
)===2,'Cancellation resolution and history deletion were not independently audited.');

// A fresh running job cannot be removed even when a user explicitly requests DELETE.
checkCancel(!$history->inspect(9)['can_remove'],'Live job was deemed removable.');
try {
    $history->remove(9,4,'DELETE');
    throw new RuntimeException('Live scan was deleted.');
} catch(DomainException) {}

// A running scan can request cancellation but is not immediately removed.
checkCancel($store->requestCancel(9),'Running scan should accept a cancellation request.');
checkCancel($db->fetchOne('SELECT state FROM jobs WHERE id=9')==='cancelling',
    'Running scan cancellation was not recorded.');
checkCancel(!$history->inspect(9)['can_remove'],
    'Job pending cancellation was unexpectedly removable.');
$db->update('jobs',['heartbeat_at'=>$stale],['id'=>9]);
checkCancel($store->recoverStalled(600,9)===1,
    'Targeted stale scan recovery did not change the scan after its heartbeat expired.');
checkCancel($db->fetchOne('SELECT state FROM jobs WHERE id=9')==='cancelled' &&
    $db->fetchOne('SELECT state FROM scan_runs WHERE id=5')==='cancelled',
    'Scan cancellation recovery did not update the scan run consistently.');
echo "PASS per-job cancellation, stale resolution, active-worker guards, stage cleanup, retained results, audit, and confirmed history deletion\n";
