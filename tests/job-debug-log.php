<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobDebugLog;

function okDebug(bool $okay,string $message):void {
    if(!$okay)throw new RuntimeException($message);
}
$logger=new JobDebugLog();
$id=random_int(10000000,90000000);
try {
    $logger->write($id,'info','verification.started',[
        'run_id'=>22,'profile_id'=>4,'php_version'=>PHP_VERSION,
        'password'=>'this-must-never-be-recorded',
        'nested'=>['worker_token'=>'DO_NOT_STORE','rows'=>26]
    ]);
    $logger->write($id,'debug','candidate.query.begin',[
        'sql_name'=>'candidate_page_64','at'=>microtime(true)
    ]);
    $logger->write($id,'debug','candidate.query.end',[
        'rows'=>26,'elapsed_ms'=>123.45
    ]);
    $view=$logger->read($id);
    okDebug($view['available']===true && count($view['rows'])===3,
        'Detailed SQL phases were not persisted independently of database events.');
    okDebug(array_column($view['rows'],'event')===[
        'verification.started','candidate.query.begin','candidate.query.end'
    ],'Diagnostic records are not ordered.');
    okDebug($view['rows'][0]['data']['password']==='[REDACTED]' &&
        $view['rows'][0]['data']['nested']['worker_token']==='[REDACTED]',
        'Sensitive keys were not redacted recursively.');
    $export=$logger->export($id);
    okDebug(is_string($export) && str_contains($export,'elapsed_ms') &&
        !str_contains($export,'this-must-never-be-recorded') &&
        !str_contains($export,'DO_NOT_STORE'),
        'JSONL export is missing records or exposed a credential.');
    okDebug(str_ends_with($logger->path($id),'job-'.$id.'.jsonl'),
        'Diagnostic path is not scoped to the job number.');

    // Exercise bounded rotation, no unbounded accumulation of 1000s of
    // per-file byte-checkpoint records for one large scan.
    for($i=0;$i<540;$i++) {
        $logger->write($id,'debug','file.bytes',[
            'bytes_read'=>$i*1048576,
            'details'=>str_repeat('z',4096)
        ]);
    }
    $view=$logger->read($id,250);
    okDebug($view['available'] && count($view['rows'])<=250 &&
        count($view['rows'])>0,'Read-back exceeds page cap or lacks recent records.');
    okDebug(is_file($logger->path($id).'.1'),
        'Log did not rotate after the size limit.');
    $size=filesize($logger->path($id));
    okDebug($size<=2*1024*1024+32768,'Current log file exceeded cap.');
    $all=$logger->export($id);
    okDebug($all!==null && strlen($all)>strlen((string)file_get_contents($logger->path($id))),
        'Export omitted rotated diagnostic records.');
    // The admin UI must not query the database merely to view log files.
    $controller=file_get_contents(dirname(__DIR__).
        '/src/Module/Jobs/Controller/JobsController.php');
    $start=strpos($controller,'public function workerLog(');
    $end=strpos($controller,'#[Route(', $start);
    okDebug($start!==false && $end!==false &&
        !str_contains(substr($controller,$start,$end-$start),'$heartbeat->report()'),
        'Diagnostic page blocks itself by querying a possibly hung database.');
    echo "PASS independent file logs: pre/post SQL, timestamp, recursive redaction, rotation, 250-row cap, JSONL export and DB-free view\n";
} finally {
    $logger->purge($id);
    okDebug(!is_file($logger->path($id)) && !is_file($logger->path($id).'.1'),
        'Deleting diagnostic history left its rotated files behind.');
}
