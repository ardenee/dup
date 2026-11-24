<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Duplicates\Service\DuplicateVerificationService;
use App\Module\Duplicates\Service\DuplicateVerificationWorker;
use App\Module\Duplicates\Service\DuplicateRebuilder;
use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\FileActions\Service\SafeFileSystem;
use Doctrine\DBAL\DriverManager;

function verifyPage(bool $okay,string $why):void
{
    if(!$okay) throw new RuntimeException($why);
}

$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-page-verify-'.bin2hex(random_bytes(6));
verifyPage(mkdir($root,0700,true),'Cannot make fixture directory');
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
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
        'CREATE TABLE duplicate_verification_hashes(job_id INT,file_entry_id INT,sha256 TEXT,PRIMARY KEY(job_id,file_entry_id))',
        'CREATE TABLE worker_heartbeats(id TEXT PRIMARY KEY,host_name TEXT,process_id INT,os_family TEXT,updated_at TEXT)'
    ] as $sql)$db->executeStatement($sql);
    foreach([
        'progress_bytes_current BIGINT NOT NULL DEFAULT 0',
        'progress_bytes_total BIGINT DEFAULT NULL',
        'progress_detail VARCHAR(255) DEFAULT NULL',
        'last_progress_at DATETIME DEFAULT NULL',
        'last_failure_reason TEXT DEFAULT NULL'
    ] as $column) $db->executeStatement('ALTER TABLE jobs ADD COLUMN '.$column);
    $db->insert('scan_profiles',['id'=>1,'name'=>'Paged candidate test']);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed','duplicate_groups'=>1]);
    $db->insert('directories',['id'=>1,'run_id'=>1,'path'=>realpath($root),'is_ignored'=>0]);
    $size=1024;$number=69;
    $db->insert('duplicate_groups',[
        'id'=>1,'run_id'=>1,'group_key'=>'size:1024',
        'size_bytes'=>$size,'file_count'=>$number,
        'reclaimable_bytes'=>($number-1)*$size,'rules_version'=>0
    ]);
    for($i=1;$i<=$number;$i++) {
        $filename=sprintf('Test%03d.mp3',$i);
        $path=$root.DIRECTORY_SEPARATOR.$filename;
        file_put_contents($path,str_repeat('ABCD',256));
        $db->insert('file_entries',[
            'id'=>$i,'run_id'=>1,'directory_id'=>1,
            'filename'=>$filename,'size_bytes'=>$size,
            'mtime_epoch'=>filemtime($path),
            'hash_method'=>'size','hash_value'=>null,'error_code'=>null,
            'is_symlink'=>0
        ]);
        $db->insert('duplicate_group_members',['group_id'=>1,'file_entry_id'=>$i]);
    }
    // Emulate MySQL's zero CHANGED rows for exact same-second progress.
    // Under the old implementation, the first assertRunning() immediately
    // raised LostLease and this 69-file end-to-end test failed at 0/69.
    $db->executeStatement(
        'CREATE TRIGGER simulate_mysql_noop BEFORE UPDATE ON jobs '.
        'WHEN NEW.heartbeat_at=OLD.heartbeat_at AND '.
        'NEW.phase=OLD.phase AND NEW.progress_current=OLD.progress_current AND '.
        'NEW.progress_total=OLD.progress_total AND '.
        'NEW.progress_bytes_current=OLD.progress_bytes_current AND '.
        'NEW.progress_bytes_total=OLD.progress_bytes_total AND '.
        'NEW.progress_detail=OLD.progress_detail AND '.
        'NEW.last_progress_at=OLD.last_progress_at '.
        'BEGIN SELECT RAISE(IGNORE); END'
    );
    $jobs=new JobStore($db,new DirectoryBrowser());
    $heartbeat=new WorkerHeartbeat($db);
    $jobs->registerWorkerHeartbeat($heartbeat);
    $service=new DuplicateVerificationService($db);
    $worker=new DuplicateVerificationWorker($db,$jobs,
        new SafeFileSystem(new DirectoryBrowser()),new DuplicateRebuilder($db));
    $id=$service->queue(1,5);
    $job=$jobs->claimNext($id);
    verifyPage($job!==null,'Could not claim verification.');
    $worker->run($job);
    $heartbeat->stop();
    verifyPage($db->fetchOne('SELECT state FROM jobs WHERE id=?',[$id])==='completed',
        'The second candidate page never completed.');
    verifyPage((int)$db->fetchOne('SELECT progress_current FROM jobs WHERE id=?',[$id])===$number,
        'Some candidate files were skipped.');
    verifyPage((int)$db->fetchOne("SELECT COUNT(*) FROM file_entries WHERE hash_method='sha256'")===$number,
        'Hashes were not published for every page.');
    verifyPage((int)$db->fetchOne('SELECT COUNT(*) FROM job_events WHERE job_id=? AND code=?',
        [$id,'VERIFICATION_CANDIDATE_PAGE_READY'])===2,
        'Candidate lookup did not traverse two pages.');
    verifyPage((int)$db->fetchOne("SELECT COUNT(*) FROM job_events WHERE job_id=? AND code='VERIFICATION_FILE_OPENING'",[$id])===$number,
        'Opening-file diagnostic events were not written.');
    $pageStats=$db->fetchFirstColumn(
        "SELECT context FROM job_events WHERE job_id=? AND code='VERIFICATION_CANDIDATE_PAGE_READY' ORDER BY id",
        [$id]
    );
    verifyPage(array_map(static fn($json)=>(int)json_decode((string)$json,true)['count'],
        $pageStats)===[64,5],
        'Candidate page boundaries were not 64 and 5.');
    echo "PASS SHA-256 verification of 69 files across two bounded SQL pages with filename/permission diagnostics\n";
} finally {
    foreach(new DirectoryIterator($root) as $entry)
        if(!$entry->isDot())@unlink($entry->getPathname());
    @rmdir($root);
}
