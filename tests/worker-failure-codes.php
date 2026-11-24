<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\WorkerFailureCode;
use App\Module\Duplicates\Service\VerificationFailureCode;

function checkWorkers(bool $yes,string $message):void
{
    if(!$yes)throw new RuntimeException($message);
}
$catalog=WorkerFailureCode::catalog();
$verification=VerificationFailureCode::catalog();
checkWorkers(count($catalog)===120,
    'Each of the 15 job kinds must have 8 distinct failure conditions.');
checkWorkers(!array_intersect_key($catalog,$verification),
    'Supervisor codes collide with verification, hashing or filesystem codes.');
$kinds=['scan','rebuild-duplicates','duplicate-verify','file-action','file-restore',
    'file-rename','file-move','file-move-restore','file-link','file-unlink',
    'bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'];
$distinct=[];
foreach($kinds as $kind) {
    foreach([0,1,137,143,11] as $status) {
        $code=WorkerFailureCode::exited($kind,$status);
        checkWorkers(isset($catalog[$code]) &&
            WorkerFailureCode::explain($code)!==null &&
            preg_match('/^DUP-[A-Z-]+-\d{3}$/D',$code),
            'Missing stable, identifiable worker-exit code: '.$kind.' status '.$status);
        $distinct[$code]=true;
    }
    foreach([false,true] as $cancelling) {
        $code=WorkerFailureCode::stalled($kind,$cancelling);
        checkWorkers(isset($catalog[$code]) &&
            WorkerFailureCode::explain($code)!==null,
            'Manual recovery shares a generic worker code: '.$kind);
        $distinct[$code]=true;
    }
    $cancelled=WorkerFailureCode::exited($kind,143,true);
    checkWorkers(isset($catalog[$cancelled]),
        'Stopped cancellation missing a job-kind-specific identifier.');
    $distinct[$cancelled]=true;
}
checkWorkers(count($distinct)===count($catalog),
    'Distinct worker process conditions or job types reuse the same code.');
checkWorkers(WorkerFailureCode::exited('duplicate-verify',1)!==
    WorkerFailureCode::exited('scan',1) &&
    WorkerFailureCode::exited('file-move',1)!==
    WorkerFailureCode::exited('file-delete',1),
    'Unrelated processes incorrectly share a process-exit code.');
checkWorkers(WorkerFailureCode::exited('duplicate-verify',1)!==
    WorkerFailureCode::exited('duplicate-verify',137) &&
    WorkerFailureCode::stalled('duplicate-verify',false)!==
    WorkerFailureCode::stalled('duplicate-verify',true),
    'Exit reason and manual recovery reason are indistinguishable.');
checkWorkers(WorkerFailureCode::exited('duplicate-verify',1)===
    WorkerFailureCode::exited('duplicate-verify',1),
    'The same recurring condition must keep the same searchable code.');
echo 'PASS '.count($catalog).' noncolliding worker failure codes; all 15 job processes and 8 failure conditions distinct'.PHP_EOL;
