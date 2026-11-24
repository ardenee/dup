<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\WorkerFailureCode;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\DriverManager;

function ensureFinal(bool $pass,string $message):void {
    if(!$pass)throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE jobs (id INTEGER PRIMARY KEY,kind TEXT,state TEXT,phase TEXT,worker_token TEXT,'.
      'active_profile_id INT,error_code TEXT,last_failure_reason TEXT,finished_at TEXT,'.
      'heartbeat_at TEXT,run_id INT,file_action_batch_id INT)',
    'CREATE TABLE file_action_batches (id INTEGER PRIMARY KEY,state TEXT,error_code TEXT)',
    'CREATE TABLE job_events (id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,'.
      'code TEXT,context TEXT,created_at TEXT)'
] as $sql)$db->executeStatement($sql);
foreach([[1,'executing'],[2,'restore_executing'],[3,'completed']] as [$id,$state]) {
    $db->insert('file_action_batches',['id'=>$id,'state'=>$state]);
    $db->insert('jobs',['id'=>$id,'kind'=>'file-delete','state'=>'running',
        'worker_token'=>'worker-'.$id,'active_profile_id'=>$id,'file_action_batch_id'=>$id]);
}
$store=new JobStore($db,new DirectoryBrowser());
$code=WorkerFailureCode::unhandled('file-delete');
ensureFinal($code==='DUP-DELETE-WORKER-024','Missing unique uncaught delete failure code.');
$store->stop(1,'worker-1','failed',$code,['reason'=>'Missing approved file record']);
ensureFinal($db->fetchOne('SELECT state FROM jobs WHERE id=1')==='failed',
    'Unhandled worker exception must produce a terminal failed job.');
ensureFinal($db->fetchOne('SELECT state FROM file_action_batches WHERE id=1')==='needs_review',
    'An unfinished destructive batch was not flagged for manual review.');
ensureFinal($db->fetchOne('SELECT error_code FROM file_action_batches WHERE id=1')===$code,
    'Worker exception code was not retained on the review-required batch.');
ensureFinal((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE job_id=1')===1,
    'Terminal failure did not produce an auditable job event.');
$store->stop(1,'worker-1','failed',$code);
ensureFinal((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE job_id=1')===1,
    'Second finalization incorrectly duplicated job events.');
$store->stop(2,'wrong-token','failed',$code);
ensureFinal($db->fetchOne('SELECT state FROM jobs WHERE id=2')==='running' &&
    $db->fetchOne('SELECT state FROM file_action_batches WHERE id=2')==='restore_executing',
    'A stale worker must not modify another owner or its batch.');
$store->stop(3,'worker-3','failed',$code);
ensureFinal($db->fetchOne('SELECT state FROM file_action_batches WHERE id=3')==='completed',
    'Uncaught-exception recovery must not overwrite a terminal successful batch.');
$source=file_get_contents(dirname(__DIR__).'/src/Module/Jobs/Command/WorkJobsCommand.php');
ensureFinal(str_contains($source,'$this->jobs->stop($id,(string)$job[\'worker_token\']') &&
    str_contains($source,'$this->stopping=true;'),
    'Outer worker exception handler must finalize the lease or stop claiming more jobs.');
echo "PASS uncaught worker finalization, safe batch review, immutable terminal state, stale owner and unique codes\n";
