<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\VerificationCancelSignal;
use App\Module\Jobs\Service\JobDebugLog;
use Doctrine\DBAL\DriverManager;

function cancelCheck(bool $yes,string $message):void
{
    if(!$yes)throw new RuntimeException($message);
}
$dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-cancel-control-'.bin2hex(random_bytes(6));
$logs=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-cancel-logs-'.bin2hex(random_bytes(6));
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE jobs(id INT PRIMARY KEY,kind TEXT,state TEXT,worker_token TEXT)');
$db->executeStatement('CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,code TEXT,context TEXT,severity TEXT,created_at TEXT)');
$host=(string)gethostname();
$db->insert('jobs',['id'=>8,'kind'=>'duplicate-verify','state'=>'cancelling','worker_token'=>'old-token']);
$db->insert('jobs',['id'=>9,'kind'=>'duplicate-verify','state'=>'running','worker_token'=>'running-token']);
$db->insert('jobs',['id'=>10,'kind'=>'file-delete','state'=>'cancelling','worker_token'=>'delete-token']);
$db->insert('jobs',['id'=>11,'kind'=>'duplicate-verify','state'=>'cancelling','worker_token'=>'other-token']);
$db->insert('jobs',['id'=>12,'kind'=>'duplicate-verify','state'=>'cancelling','worker_token'=>'']);
foreach([[8,31337,$host],[9,31338,$host],[10,31339,$host],[11,31340,'other-host']] as [$jobId,$pid,$workerHost]) {
    $db->insert('job_events',[
        'job_id'=>$jobId,'code'=>'WORKER_CLAIMED',
        'context'=>json_encode([
            'worker_pid'=>$pid,'worker_host'=>$workerHost
        ],JSON_THROW_ON_ERROR)
    ]);
}
$logger=new JobDebugLog($logs);
$signal=new VerificationCancelSignal($db,$logger,$dir,true);
try {
    $result=$signal->notify(8);
    cancelCheck($result['sent']===true && $result['pid']===31337,
        'The target SHA-256 job did not notify its owning DSM supervisor.');
    $file=$dir.'/verify-31337.request';
    cancelCheck(is_file($file) && trim((string)file_get_contents($file))==='8',
        'Cancellation request not written to the expected owner-PID file.');
    cancelCheck((int)$db->fetchOne(
        "SELECT COUNT(*) FROM job_events WHERE job_id=8 AND code='VERIFICATION_SUPERVISOR_STOP_REQUESTED'"
    )===1,'Supervisor stop was not recorded in the normal job event history.');
    cancelCheck($signal->notify(8)['sent']===true,
        'Repeated force-stop request must remain idempotent.');
    foreach([9,10,11,12,13] as $id) {
        $attempt=$signal->notify($id);
        cancelCheck(!$attempt['sent'],'Unsupported/non-owned worker #'.$id.' was signalled.');
    }
    cancelCheck(count(glob($dir.'/*.request'))===1,
        'A different job kind, host or running worker received a cancellation file.');

    $generic=new VerificationCancelSignal($db,$logger,$dir,false);
    cancelCheck(!$generic->notify(8)['sent'],
        'A generic web server must never use an absent DSM supervisor.');

    $script=file_get_contents(dirname(__DIR__).'/packaging/dsm7/worker-loop.sh');
    foreach([
        'verify-$pid.request','requested_job','sending TERM','sending KILL',
        'kill -TERM "$pid"','kill -KILL "$pid"',
        'rm -f "$request"','[ $((now-requested_at)) -ge 8 ]',
        'wait "$pid"'
    ] as $needle)
        cancelCheck(str_contains($script,$needle),
            'Supervisor does not safely implement bounded cancellation: '.$needle);
    $worker=file_get_contents(dirname(__DIR__).'/src/Module/Jobs/Command/WorkJobsCommand.php');
    cancelCheck(str_contains($worker,'$this->stopping=true;'),
        'Cancelled verification could continue into another job while supervisor is stopping.');

    $controller=file_get_contents(dirname(__DIR__).'/src/Module/Jobs/Controller/JobsController.php');
    foreach(["name:'app_job_force_stop_verify'","methods:['POST']",
        "isCsrfTokenValid('stop_verify_job_'",'$signal->notify($id)'] as $needle)
        cancelCheck(str_contains($controller,$needle),
            'Force-stop endpoint is missing a control: '.$needle);
    echo "PASS DSM verified PID cancellation signalling, no direct web kill, 8-second escalation, safe job-kind/host filtering and CSRF\n";
} finally {
    $logger->purge(8);
    foreach(glob($dir.'/*')?:[] as $file)@unlink($file);
    @rmdir($dir);
    foreach(glob($logs.'/*')?:[] as $file)@unlink($file);
    @rmdir($logs);
}
