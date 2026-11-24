<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Duplicates\Service\VerificationFailureCode as Codes;
use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\JobFailureCode;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Scans\Service\FileHasher;
use App\Core\Diagnostics\DiagnosticFailure;
use App\Core\Diagnostics\CodedDomainFailure;
use Doctrine\DBAL\DriverManager;

function checkTrace(bool $okay,string $why):void {
    if(!$okay)throw new RuntimeException($why);
}

$catalog=Codes::catalog();
checkTrace(count($catalog)>220 && count($catalog)===count(array_unique(array_keys($catalog))),
    'The code catalog must contain more than 220 distinct, stable error codes.');
$cases=[
    ['preflight_event',new PDOException('SQL failed'),'DUP-VERIFY-10101'],
    ['preflight_lookup',new PDOException('SQL failed'),'DUP-VERIFY-10201'],
    ['preflight_latest',new PDOException('SQL failed'),'DUP-VERIFY-10301'],
    ['candidate_count',new PDOException('SQL failed'),'DUP-VERIFY-10401'],
    ['initial_progress',new PDOException('SQL failed'),'DUP-VERIFY-10501'],
    ['verification_started_event',new PDOException('SQL failed'),'DUP-VERIFY-10601'],
    ['candidate_lease',new LostLease('Stale lease','DUP-JOB-LEASE-101'),'DUP-JOB-LEASE-101'],
    ['candidate_progress',new PDOException('SQL failed'),'DUP-VERIFY-10801'],
    ['candidate_event',new PDOException('SQL failed'),'DUP-VERIFY-10901'],
    ['finding_candidate_files',new PDOException('SQL failed'),'DUP-VERIFY-11001'],
    ['finding_candidate_files',new RuntimeException('Unexpected'),'DUP-VERIFY-11005'],
    ['candidate_page_event',new PDOException('SQL failed'),'DUP-VERIFY-11101'],
    ['file_lease',new LostLease('Stale lease','DUP-JOB-LEASE-102'),'DUP-JOB-LEASE-102'],
    ['checking_file_access',new TypeError('Type mismatch'),'DUP-VERIFY-11406'],
    ['reading_file',new RuntimeException('Unexpected'),'DUP-VERIFY-11705'],
    ['staging_hash',new PDOException('SQL failed'),'DUP-VERIFY-11801'],
    ['publishing',new PDOException('SQL failed'),'DUP-VERIFY-12301']
];
$codes=[];
foreach($cases as [$operation,$exception,$expected]) {
    $details=Codes::detail($operation,$exception,'V42-20261124235959-abcdef12');
    checkTrace($details['error_code']===$expected &&
        (Codes::explain($expected)??JobFailureCode::explain($expected))!==null &&
        isset($details['source_file'],$details['source_line'],$details['exception_type']),
        'Incorrect, undecodable or context-free code for '.$operation);
    $codes[]=$details['error_code'];
}
checkTrace(count($codes)===count(array_unique($codes)),
    'Different operations or causes must NEVER share an error code.');
checkTrace(Codes::identify('not_registered',new TypeError('bad'))['error_code']==='DUP-VERIFY-99906',
    'An unknown location must have a visibly unclassified code distinct by cause.');
$trace=Codes::trace(48);
checkTrace((bool)preg_match('/^V48-\d{14}-[a-f0-9]{8}$/D',$trace),
    'Occurrence trace ID not unique to run/attempt.');

$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-codes-'.bin2hex(random_bytes(5));
checkTrace(mkdir($root,0700,true),'Could not make test fixture.');
try {
    $path=$root.DIRECTORY_SEPARATOR.'sample.bin';
    file_put_contents($path,'1234567890');
    $size=10;$mtime=(int)filemtime($path);
    $hashFailures=[
        static fn()=>FileHasher::digest($path,'size',$size,$mtime),
        static fn()=>FileHasher::digest($root.'/absent','sha256',$size,$mtime),
        static fn()=>FileHasher::digest($path,'sha256',9,$mtime),
        static fn()=>FileHasher::digest($path,'sha256',$size,$mtime-1)
    ];
    foreach(['DUP-HASH-101','DUP-HASH-103','DUP-HASH-105','DUP-HASH-106'] as $i=>$expected) {
        try {
            $hashFailures[$i]();
            throw new RuntimeException('Missing coded failure '.$expected);
        } catch(DiagnosticFailure $failure) {
            checkTrace($failure->errorCode()===$expected &&
                Codes::detail('reading_file',$failure,$trace)['error_code']===$expected,
                'Hash failure lost its own component-specific code: '.$expected);
        }
    }
    $fs=new SafeFileSystem(new DirectoryBrowser());
    try {
        $fs->checkedPath($root,'../bad');
        throw new RuntimeException('Invalid name was accepted');
    } catch(CodedDomainFailure $failure) {
        checkTrace($failure->errorCode()==='DUP-FS-101',
            'Unsafe source-path failure was merged with a hashing error.');
    }
    try {
        $fs->checkedPath($root,'absent.bin');
        throw new RuntimeException('Missing file was accepted');
    } catch(CodedDomainFailure $failure) {
        checkTrace($failure->errorCode()==='DUP-FS-104',
            'Missing source file was not classified separately from invalid filename.');
    }
    $details=Codes::detail('finding_candidate_files',new PDOException('SQL query failed'),$trace);
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY,kind TEXT,state TEXT,phase TEXT,worker_token TEXT,run_id INT,active_profile_id INT,heartbeat_at TEXT,finished_at TEXT,error_code TEXT,last_failure_reason TEXT,file_action_batch_id INT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,state TEXT,finished_at TEXT,error_count INT DEFAULT 0)'
    ] as $q)$db->executeStatement($q);
    $db->insert('jobs',[
        'id'=>48,'kind'=>'duplicate-verify','state'=>'running',
        'worker_token'=>'owned-48','run_id'=>4,'active_profile_id'=>3
    ]);
    $store=new JobStore($db,new DirectoryBrowser());
    $store->stop(48,'owned-48','failed','VERIFICATION_FAILED',[
        ...$details,'failure_code'=>$details['error_code'],
        'reason'=>'['.$details['error_code'].'] '.$details['operation'].'; trace '.$trace
    ]);
    $row=$db->fetchAssociative('SELECT error_code,last_failure_reason FROM jobs WHERE id=48');
    checkTrace($row['error_code']==='DUP-VERIFY-11001' &&
        str_contains((string)$row['last_failure_reason'],$trace),
        'Normal job DB record discarded its distinct five-digit error code.');
    $event=$db->fetchAssociative('SELECT code,context FROM job_events WHERE job_id=48');
    $context=json_decode((string)$event['context'],true);
    checkTrace($event['code']==='DUP-VERIFY-11001' &&
        $context['failure_code']==='DUP-VERIFY-11001' &&
        $context['trace_id']===$trace && $context['source_line']>0,
        'Event is missing the specific underlying failure code, trace and source.');
    echo "PASS ".count($catalog)." non-colliding codes; DB/lease/type/source/hash failures differ; persistence, trace and source location\n";
} finally {
    @unlink($root.DIRECTORY_SEPARATOR.'sample.bin');
    @rmdir($root);
}
