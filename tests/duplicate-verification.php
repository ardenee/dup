<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Duplicates\Service\DuplicateVerificationService;
use App\Module\Duplicates\Service\DuplicateVerificationWorker;
use App\Module\Duplicates\Service\DuplicateRebuilder;
use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\FileActions\Service\SafeFileSystem;
use Doctrine\DBAL\DriverManager;

function checked(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException($why);
}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-verify-'.bin2hex(random_bytes(6));
checked(mkdir($root,0700,true),'Unable to create test directory');
$files=[
    'A.mp3'=>str_repeat('ABCD',800),
    'B.mp3'=>str_repeat('ABCD',800),
    'C.mp3'=>str_repeat('XYZA',800),
    'Other.mp3'=>str_repeat('ZZZ',1100)
];
foreach($files as $name=>$contents)file_put_contents($root.DIRECTORY_SEPARATOR.$name,$contents);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    $db->executeStatement('PRAGMA foreign_keys=ON');
    foreach([
        'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
        'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT,verification_method TEXT,verified_at TEXT,duplicate_groups INT)',
        'CREATE TABLE directories(id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)',
        'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT,is_symlink INT)',
        'CREATE TABLE duplicate_groups(id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INT,group_key TEXT,size_bytes INT,file_count INT,reclaimable_bytes INT,rules_version INT)',
        'CREATE TABLE duplicate_group_members(id INTEGER PRIMARY KEY AUTOINCREMENT,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE duplicate_verification_hashes(job_id INT,file_entry_id INT,sha256 TEXT,PRIMARY KEY(job_id,file_entry_id))'
    ] as $sql)$db->executeStatement($sql);
    $db->insert('scan_profiles',['id'=>1,'name'=>'Music']);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed','duplicate_groups'=>1]);
    $db->insert('directories',['id'=>1,'run_id'=>1,'path'=>realpath($root),'is_ignored'=>0]);
    $db->insert('duplicate_groups',['id'=>1,'run_id'=>1,'group_key'=>'size:3200',
        'size_bytes'=>3200,'file_count'=>3,'reclaimable_bytes'=>6400,'rules_version'=>0]);
    $index=0;
    foreach($files as $name=>$contents){
        $index++;
        $path=$root.DIRECTORY_SEPARATOR.$name;
        $db->insert('file_entries',[
            'id'=>$index,'run_id'=>1,'directory_id'=>1,'filename'=>$name,
            'size_bytes'=>filesize($path),'mtime_epoch'=>filemtime($path),
            'hash_method'=>'size','hash_value'=>null,'error_code'=>null,'is_symlink'=>0
        ]);
        if($index<=3)$db->insert('duplicate_group_members',['group_id'=>1,'file_entry_id'=>$index]);
    }
    $jobs=new JobStore($db,new DirectoryBrowser());
    $service=new DuplicateVerificationService($db);
    $worker=new DuplicateVerificationWorker($db,$jobs,
        new SafeFileSystem(new DirectoryBrowser()),new DuplicateRebuilder($db));
    $eligibility=$service->eligibility(1);
    checked($eligibility['can_verify']===true && $eligibility['files']===3,
        'Optional stage counted non-candidates or omitted candidates');
    $id=$service->queue(1,8);
    $job=$jobs->claimNext($id);
    checked($job && $job['kind']==='duplicate-verify','Stage job not enqueued');
    $worker->run($job);
    checked((string)$db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id])==='completed',
        'Stage job did not complete');
    checked((string)$db->fetchOne('SELECT verification_method FROM scan_runs WHERE id=1')==='sha256',
        'Scan verification method not updated');
    checked((int)$db->fetchOne('SELECT duplicate_groups FROM scan_runs WHERE id=1')===1,
        'Incorrect verified group count');
    checked((int)$db->fetchOne("SELECT COUNT(*) FROM duplicate_groups WHERE rules_version=1")===1,
        'SHA-256 duplicate group was not published');
    checked((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_group_members')===2,
        'False-positive same-size file was retained as a verified duplicate');
    checked((int)$db->fetchOne("SELECT COUNT(*) FROM file_entries WHERE hash_method='sha256'")===3,
        'Second stage hashed unrelated files');
    checked((string)$db->fetchOne('SELECT hash_method FROM file_entries WHERE id=4')==='size',
        'Unrelated file was rehashed');
    checked((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_verification_hashes')===0,
        'Staging hashes remain after successful stage');
    checked($service->eligibility(1)['can_verify']===false,'Stage still offered after verification');

    // A changed candidate fails the entire second stage, with no scan/index mutation.
    $db->executeStatement("UPDATE scan_runs SET verification_method=NULL,verified_at=NULL WHERE id=1");
    $db->executeStatement("UPDATE duplicate_groups SET rules_version=0 WHERE run_id=1");
    $again=$service->queue(1,8);
    file_put_contents($root.DIRECTORY_SEPARATOR.'A.mp3','changed after approval');
    try {
        $worker->run($jobs->claimNext($again));
        throw new RuntimeException('Changed candidate was incorrectly verified');
    } catch(RuntimeException $ex) {
        checked(str_contains($ex->getMessage(),'changed') ||
            str_contains($ex->getMessage(),'Candidate'),
            'Verification failed unexpectedly: '.$ex->getMessage());
    }
    checked((string)$db->fetchOne('SELECT state FROM jobs WHERE id=?',[$again])==='failed',
        'Changed stage not recorded as failed');
    checked((string)$db->fetchOne('SELECT verification_method FROM scan_runs WHERE id=1')==='',
        'Failed stage modified verification marker');
    checked((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_verification_hashes')===0,
        'Failed stage leaked intermediate hashes');
    echo "PASS optional SHA-256 second stage hashes candidate members only, splits false positives, updates results atomically and rejects changed files\n";
} finally {
    foreach(new DirectoryIterator($root) as $item)if(!$item->isDot())@unlink($item->getPathname());
    @rmdir($root);
}
