<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\DriverManager;

function eqWorker(bool $assertion,string $message):void
{
    if(!$assertion)throw new RuntimeException($message);
}

$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE jobs(id INTEGER PRIMARY KEY,kind TEXT,state TEXT,phase TEXT,worker_token TEXT,'.
        'profile_id INT,run_id INT,file_action_batch_id INT,active_profile_id INT,'.
        'progress_current INT,progress_total INT,progress_bytes_current INT,'.
        'progress_bytes_total INT,progress_detail TEXT,heartbeat_at TEXT,'.
        'last_failure_reason TEXT,error_code TEXT,finished_at TEXT)',
    'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,code TEXT,severity TEXT,context TEXT,created_at TEXT)',
    'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,state TEXT,finished_at TEXT,error_count INT DEFAULT 0)',
    'CREATE TABLE duplicate_verification_hashes(job_id INT,file_entry_id INT,sha256 TEXT)',
    'CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY,state TEXT,error_code TEXT)'
] as $sql)$db->executeStatement($sql);
$host=(string)(gethostname()?:'unknown');
$pid=184232;
foreach([
    [21,'duplicate-verify','running',184232,0],
    [22,'duplicate-verify','cancelling',184232,0],
    [23,'scan','running',184232,0],
    [24,'file-move','running',184232,24],
    [25,'duplicate-verify','running',13377,0],
    [26,'scan','completed',184232,0]
] as [$id,$kind,$state,$ownerPid,$batch]) {
    $db->insert('jobs',[
        'id'=>$id,'kind'=>$kind,'state'=>$state,'phase'=>'hashing',
        'worker_token'=>'token-'.$id,'profile_id'=>1,'run_id'=>$id,
        'file_action_batch_id'=>$batch?:null,'active_profile_id'=>$id,
        'progress_current'=>0,'progress_total'=>26,
        'progress_bytes_current'=>0,'progress_bytes_total'=>2512555861,
        'progress_detail'=>'File 1/26: Movie.mkv',
        'heartbeat_at'=>'2026-11-24 15:41:00'
    ]);
    $db->insert('scan_runs',['id'=>$id,'state'=>$kind==='scan'?'running':'completed']);
    $db->insert('job_events',[
        'job_id'=>$id,'code'=>'WORKER_CLAIMED','severity'=>'info',
        'context'=>json_encode([
            'worker_pid'=>$ownerPid,'worker_host'=>$host,'attempt'=>1
        ],JSON_THROW_ON_ERROR),'created_at'=>'2026-11-24 15:40:00'
    ]);
}
foreach([21,22,25] as $id)
    $db->insert('duplicate_verification_hashes',[
        'job_id'=>$id,'file_entry_id'=>1,'sha256'=>str_repeat('a',64)
    ]);
$db->insert('file_action_batches',['id'=>24,'state'=>'executing']);
$store=new JobStore($db,new DirectoryBrowser());
eqWorker($store->markWorkerExited($pid,137,$host)===4,
    'Worker fatal exit must resolve only its four unfinished jobs.');
foreach([21,22,23,24] as $id) {
    $job=$db->fetchAssociative('SELECT state,worker_token,active_profile_id,error_code,last_failure_reason FROM jobs WHERE id=?',[$id]);
    $target=$id===22?'cancelled':'failed';
    eqWorker($job['state']===$target && $job['worker_token']===null &&
        $job['active_profile_id']===null &&
        str_contains($job['last_failure_reason'],'137'),
        'Lost worker lease or exit explanation for job '.$id);
    $context=json_decode((string)$db->fetchOne(
        'SELECT context FROM job_events WHERE job_id=? ORDER BY id DESC LIMIT 1',[$id]
    ),true);
    eqWorker(($context['worker_pid']??null)===$pid &&
        ($context['exit_code']??null)===137 &&
        ($context['resolved_state']??null)===$target,
        'Worker exit event failed to record process identity and outcome.');
}
eqWorker($db->fetchOne('SELECT state FROM scan_runs WHERE id=23')==='failed',
    'An unfinished scan run must be failed after confirmed worker death.');
eqWorker($db->fetchOne('SELECT state FROM scan_runs WHERE id=21')==='completed',
    'Prior completed scan results must survive worker failure.');
eqWorker((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_verification_hashes')===1 &&
    (int)$db->fetchOne('SELECT job_id FROM duplicate_verification_hashes')===25,
    'Crash cleanup removed another worker staging hash or leaked old hashes.');
eqWorker($db->fetchOne('SELECT state FROM file_action_batches WHERE id=24')==='needs_review',
    'Interrupted file action must be reviewed, never auto-retried.');
eqWorker($db->fetchOne('SELECT state FROM jobs WHERE id=25')==='running' &&
    $db->fetchOne('SELECT state FROM jobs WHERE id=26')==='completed',
    'Exited worker changed jobs belonging to another PID or already finished.');
eqWorker($store->markWorkerExited($pid,137,$host)===0,
    'Worker exit record is not idempotent.');
eqWorker($store->markWorkerExited($pid,137,'another-host')===0,
    'Worker exit must be scoped to the claiming hostname.');
try {
    $store->markWorkerExited(0,137,$host);
    throw new RuntimeException('Invalid PID accepted.');
} catch(DomainException) {}
echo "PASS confirmed worker exit: exact PID/host, running/cancelling jobs, stale hash cleanup, preserved scans, protected file actions and idempotency\n";
