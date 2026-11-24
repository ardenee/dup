<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\ScanCancelled;
use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Core\Diagnostics\CodedRuntimeFailure;
use Doctrine\DBAL\DriverManager;

function heartbeatCheck(bool $okay,string $reason):void
{
    if(!$okay)throw new RuntimeException($reason);
}

// SQLite normally counts matched UPDATE rows. Simulate PDO MySQL's default
// CHANGED-row count by suppressing an UPDATE that writes identical data.
// No live NAS database or credentials are needed for this regression.
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE jobs (id INT PRIMARY KEY,state TEXT,worker_token TEXT,phase TEXT,'.
        'progress_current INT,progress_total INT,progress_bytes_current INT,'.
        'progress_bytes_total INT,progress_detail TEXT,last_progress_at TEXT,heartbeat_at TEXT)',
    'CREATE TABLE worker_heartbeats (id TEXT PRIMARY KEY,host_name TEXT,process_id INT,os_family TEXT,updated_at TEXT)'
] as $sql)$db->executeStatement($sql);

$now=gmdate('Y-m-d H:i:s');
$db->insert('jobs',[
    'id'=>42,'state'=>'running','worker_token'=>'original-worker',
    'phase'=>'hashing_duplicates',
    'progress_current'=>0,'progress_total'=>26,
    'progress_bytes_current'=>0,'progress_bytes_total'=>2454075564,
    'progress_detail'=>'Preparing SHA-256 verification',
    'last_progress_at'=>$now,'heartbeat_at'=>$now
]);
$store=new JobStore($db,new DirectoryBrowser());
$process=new WorkerHeartbeat($db);
$store->registerWorkerHeartbeat($process);

// The precise incident: another operation recorded this same second.
// The MySQL driver reports zero CHANGED rows, despite a matching lease.
$db->executeStatement(
    'CREATE TRIGGER ignore_same_heartbeat BEFORE UPDATE ON jobs '.
    'WHEN NEW.heartbeat_at=OLD.heartbeat_at AND '.
    'NEW.phase=OLD.phase AND NEW.progress_current=OLD.progress_current AND '.
    'NEW.progress_total=OLD.progress_total AND '.
    'NEW.progress_bytes_current=OLD.progress_bytes_current AND '.
    'NEW.progress_bytes_total=OLD.progress_bytes_total AND '.
    'NEW.progress_detail=OLD.progress_detail AND '.
    'NEW.last_progress_at=OLD.last_progress_at '.
    'BEGIN SELECT RAISE(IGNORE); END'
);
$count=$db->executeStatement(
    "UPDATE jobs SET heartbeat_at=? WHERE id=? AND worker_token=? AND state='running'",
    [$now,42,'original-worker']
);
heartbeatCheck($count===0,'Test fixture did not emulate MySQL no-op affected rows.');
$store->assertRunning(42,'original-worker');
heartbeatCheck((int)$db->fetchOne('SELECT COUNT(*) FROM worker_heartbeats')===1,
    'A valid same-second job must register its process heartbeat without throwing LostLease.');

$store->verificationProgress(
    42,'original-worker',0,26,0,2454075564,'Preparing SHA-256 verification'
);
heartbeatCheck($db->fetchOne('SELECT state FROM jobs WHERE id=42')==='running',
    'A no-op SHA-256 progress update falsely failed the job.');
$store->progress(42,'original-worker','hashing_duplicates',0,26);
heartbeatCheck($db->fetchOne('SELECT state FROM jobs WHERE id=42')==='running',
    'A no-op scan progress update falsely failed the job.');

// A rejected progress UPDATE with genuinely different values is not a no-op.
// It must produce a *different*, precise error, not a false lease error.
$db->executeStatement('DROP TRIGGER ignore_same_heartbeat');
$db->executeStatement(
    'CREATE TRIGGER block_progress BEFORE UPDATE ON jobs BEGIN SELECT RAISE(IGNORE); END'
);
try {
    $store->verificationProgress(42,'original-worker',1,26,1024,2454075564,
        'File 1/26');
    throw new RuntimeException('Unsaved SHA-256 progress was silently accepted.');
} catch(CodedRuntimeFailure $failure) {
    heartbeatCheck($failure->errorCode()==='DUP-JOB-PROGRESS-102',
        'A genuine failed SHA-256 progress write must have its own diagnostic code.');
}
try {
    $store->progress(42,'original-worker','new-phase',1,26);
    throw new RuntimeException('Unsaved scan progress was silently accepted.');
} catch(CodedRuntimeFailure $failure) {
    heartbeatCheck($failure->errorCode()==='DUP-JOB-PROGRESS-101',
        'A genuine failed scan progress write must have its own diagnostic code.');
}

$db->executeStatement('DROP TRIGGER block_progress');
$db->executeStatement("UPDATE jobs SET state='cancelling' WHERE id=42");
try {
    $store->assertRunning(42,'original-worker');
    throw new RuntimeException('A cancelling job kept processing.');
} catch(ScanCancelled) {}

$db->executeStatement("UPDATE jobs SET state='running',worker_token='other-worker' WHERE id=42");
try {
    $store->assertRunning(42,'original-worker');
    throw new RuntimeException('An actually stolen lease was accepted.');
} catch(LostLease $failure) {
    heartbeatCheck($failure->errorCode()==='DUP-JOB-LEASE-101',
        'A real ownership mismatch must have a distinct source-specific error code.');
}
$process->stop();

echo "PASS MySQL zero-changed-row heartbeat, scan/SHA-256 no-op progress, real failed writes, cancellation and stolen lease\n";
