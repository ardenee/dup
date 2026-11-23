<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Renaming\Service\RenameRules;
use App\Module\Renaming\Service\RenameService;
use App\Module\Renaming\Service\RenameWorker;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function verifyRename(bool $condition,string $message): void
{
    if(!$condition) throw new \RuntimeException($message);
}

verifyRename(RenameRules::cleaned('Track_Name..mp3','Track',1,1,true)==='Name.mp3',
    'Legacy cleanup failed');
verifyRename(RenameRules::cleaned('Demo.[copy].FLAC','[copy]',1,1,true)==='Demo.FLAC',
    'Extension retention failed');
foreach(['','..','foo/bar.txt','foo\\bar.txt','CON.txt','foo?.txt'] as $bad) {
    try {
        RenameRules::validateName($bad);
        throw new \RuntimeException('Filename validation accepted: '.$bad);
    } catch(\DomainException) {}
}

$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-rename-'.bin2hex(random_bytes(6));
verifyRename(mkdir($folder,0700,true),'Cannot create temporary test folder');
try {
    $original=$folder.DIRECTORY_SEPARATOR.'Demo_[copy].flac';
    $existing=$folder.DIRECTORY_SEPARATOR.'Demo.flac';
    file_put_contents($original,str_repeat('AUDIO',100));
    file_put_contents($existing,'DO NOT OVERWRITE');
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT)',
        'CREATE TABLE scan_runs (id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT)',
        'CREATE TABLE directories (id INT PRIMARY KEY,path TEXT,run_id INT,is_ignored INT)',
        'CREATE TABLE file_entries (id INT PRIMARY KEY,filename TEXT,directory_id INT,run_id INT,size_bytes INT,mtime_epoch INT,error_code TEXT)',
        'CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items (id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,state TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,result_code TEXT)',
        'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events (id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE duplicate_groups (id INT PRIMARY KEY,run_id INT)',
        'CREATE TABLE duplicate_group_members (id INT PRIMARY KEY,group_id INT,file_entry_id INT)'
    ] as $sql) $db->executeStatement($sql);
    $db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Music')");
    $db->executeStatement("INSERT INTO scan_runs (id,profile_id,state) VALUES (1,1,'completed')");
    $db->insert('directories',['id'=>1,'path'=>realpath($folder),'run_id'=>1,'is_ignored'=>0]);
    $db->insert('file_entries',[
        'id'=>1,'filename'=>basename($original),'directory_id'=>1,
        'run_id'=>1,'size_bytes'=>filesize($original),
        'mtime_epoch'=>filemtime($original),'error_code'=>null
    ]);
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $actions=new FileActionService($db,$fs);
    $service=new RenameService($db,$fs,$actions);
    $candidate=$service->candidates(1,'[copy]',0,1,true);
    verifyRename(count($candidate['rows'])===1,'Cleanup candidates missing');
    verifyRename($candidate['rows'][0]['new_name']==='Demo.flac','Wrong rename preview');
    try {
        $service->queue(1,1,[1],'[copy]',0,1,true);
        throw new \RuntimeException('Collision should have stopped queueing');
    } catch(\DomainException $ex) {
        verifyRename(str_contains($ex->getMessage(),'already exists'),'Wrong collision rejection');
    }
    verifyRename(file_get_contents($existing)==='DO NOT OVERWRITE','Collision overwrote file');
    unlink($existing);
    $jobId=$service->queue(1,1,[1],'[copy]',0,1,true);
    verifyRename($jobId>0,'Job not queued');
    try {
        $service->queue(1,1,[1],'[copy]',0,1,true);
        throw new \RuntimeException('Second active job was queued');
    } catch(\DomainException) {}
    $job=$jobs->claimNext($jobId);
    verifyRename($job!==null && $job['kind']==='file-rename','Rename worker job not claimed');
    (new RenameWorker($db,$jobs,$fs,$actions))->run($job);
    verifyRename(!file_exists($original),'Source still exists after rename');
    verifyRename(file_exists($existing),'Target missing after rename');
    verifyRename((string)$db->fetchOne('SELECT filename FROM file_entries WHERE id=1')==='Demo.flac',
        'Indexed filename was not updated');
    verifyRename((string)$db->fetchOne('SELECT state FROM jobs WHERE id=?',[$jobId])==='completed',
        'Job did not complete');
    verifyRename((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE action_kind=?',['rename'])==='completed',
        'Rename batch not completed');
    verifyRename((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_RENAMED'")===1,
        'Rename audit missing');
    verifyRename(count($service->batches())===1,'Rename history missing');
    echo "PASS rename rules, collision protection, queued job, worker, index update and audit\n";
} finally {
    foreach(glob($folder.DIRECTORY_SEPARATOR.'*')?:[] as $file) @unlink($file);
    @rmdir($folder);
}
