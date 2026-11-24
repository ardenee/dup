<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobDebugLog;
use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\DriverManager;

function traceExpect(bool $okay,string $reason):void
{
    if(!$okay)throw new RuntimeException($reason);
}
$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-root-error-'.bin2hex(random_bytes(8));
$logs=new JobDebugLog($directory);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY,kind TEXT,state TEXT,phase TEXT,worker_token TEXT,run_id INT,file_action_batch_id INT,active_profile_id INT,progress_current INT,progress_total INT,progress_bytes_current INT,progress_bytes_total INT,progress_detail TEXT,heartbeat_at TEXT,finished_at TEXT,error_code TEXT,last_failure_reason TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,state TEXT,finished_at TEXT,error_count INT DEFAULT 0)',
        'CREATE TABLE duplicate_verification_hashes(job_id INT,file_entry_id INT,sha256 TEXT)'
    ] as $sql)$db->executeStatement($sql);
    $pid=38991;$host=(string)(gethostname()?:'unknown');
    foreach([21,22] as $id) {
        $db->insert('jobs',[
            'id'=>$id,'kind'=>'duplicate-verify','state'=>'running',
            'phase'=>'hashing_duplicates','worker_token'=>'token-'.$id,
            'run_id'=>2,'active_profile_id'=>$id,
            'progress_current'=>0,'progress_total'=>26,
            'progress_bytes_current'=>0,'progress_bytes_total'=>2454075564,
            'progress_detail'=>'Preparing SHA-256 verification',
            'heartbeat_at'=>gmdate('Y-m-d H:i:s')
        ]);
        $db->insert('job_events',[
            'job_id'=>$id,'severity'=>'info','code'=>'WORKER_CLAIMED',
            'context'=>json_encode(['worker_pid'=>$pid,'worker_host'=>$host]),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }
    $db->insert('scan_runs',['id'=>2,'state'=>'completed']);
    $logs->write(21,'info','verification.worker.start',['trace_id'=>'V21-20261124200000-aabbccdd']);
    $logs->write(21,'debug','candidate.page.sql.start',['trace_id'=>'V21-20261124200000-aabbccdd']);
    $logs->write(21,'error','verification.failure',[
        'error_code'=>'DUP-VERIFY-11001',
        'trace_id'=>'V21-20261124200000-aabbccdd',
        'stage'=>'finding_candidate_files',
        'operation'=>'Querying next batch of candidate files',
        'exception_type'=>'Doctrine\\DBAL\\Exception',
        'exception_message'=>'Invalid SQL near LIMIT',
        'source_file'=>'src/Module/Duplicates/Service/DuplicateVerificationWorker.php',
        'source_line'=>144
    ]);
    $logs->write(21,'error','verification.failure_persist_failed',[
        'root_error_code'=>'DUP-VERIFY-11001','message'=>'DB connection unavailable'
    ]);
    $logs->write(21,'error','worker.job.exception',[
        'message'=>'Could not save verification failure',
        'file'=>'src/Module/Jobs/Service/JobStore.php','line'=>99
    ]);
    $last=$logs->latestFailure(21);
    traceExpect(($last['error_code']??null)==='DUP-VERIFY-11001' &&
        ($last['trace_id']??null)==='V21-20261124200000-aabbccdd' &&
        $last['source_line']===144,
        'The original SQL failure was overwritten by the later reporting failure.');
    $store=new JobStore($db,new DirectoryBrowser(),$logs);
    traceExpect($store->markWorkerExited($pid,1,$host)===2,
        'Only two matching, unfinished worker jobs should be recovered.');
    $actual=$db->fetchAssociative(
        'SELECT state,error_code,last_failure_reason FROM jobs WHERE id=21'
    );
    traceExpect($actual['state']==='failed' &&
        $actual['error_code']==='DUP-VERIFY-11001' &&
        str_contains($actual['last_failure_reason'],'V21-20261124200000-aabbccdd') &&
        str_contains($actual['last_failure_reason'],'Invalid SQL') &&
        str_contains($actual['last_failure_reason'],':144'),
        'Process-exit recovery lost trace ID, source line, or the actual SQL cause.');
    $context=json_decode((string)$db->fetchOne(
        "SELECT context FROM job_events WHERE job_id=21 AND code='DUP-VERIFY-11001'"
    ),true);
    traceExpect(($context['root_error_code']??null)==='DUP-VERIFY-11001' &&
        ($context['trace_id']??null)==='V21-20261124200000-aabbccdd' &&
        ($context['source_line']??null)===144,
        'WORKER_PROCESS_EXITED did not link to original diagnostic trace.');
    $unknown=$db->fetchAssociative('SELECT error_code,last_failure_reason FROM jobs WHERE id=22');
    traceExpect($unknown['error_code']==='DUP-VERIFY-WORKER-001' &&
        str_contains($unknown['last_failure_reason'],'No structured cause found'),
        'Unattributed exits must be labelled explicitly, not guessed.');
    traceExpect($store->markWorkerExited($pid,1,$host)===0,
        'Recovery should remain idempotent.');
    echo "PASS original root error survives worker exit, trace ID, source file/line, fallback unclassified exit and idempotent recovery\n";
} finally {
    $logs->purge(21);
    $logs->purge(22);
    @rmdir($directory);
}
