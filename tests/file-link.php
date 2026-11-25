<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\FileLinkWorker;
use App\Module\FileActions\Service\FileActionWorker;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function checkLink(bool $condition,string $message):void {
    if(!$condition) throw new RuntimeException($message);
}
$temp=realpath(sys_get_temp_dir())?:sys_get_temp_dir();
$root=$temp.DIRECTORY_SEPARATOR.'dup-link-'.bin2hex(random_bytes(6));
$sourceDir=$root.DIRECTORY_SEPARATOR.'files';
$backupDir=$root.DIRECTORY_SEPARATOR.'recovery';
mkdir($sourceDir,0700,true);
mkdir($backupDir,0700,true);
$original=$sourceDir.DIRECTORY_SEPARATOR.'original.mp3';
$survivor=$sourceDir.DIRECTORY_SEPARATOR.'survivor.mp3';
$contents=str_repeat('original duplicate data abc',103);
file_put_contents($original,$contents);
file_put_contents($survivor,$contents);
$sha=hash_file('sha256',$original);
$size=filesize($original);
$mtime=filemtime($original);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT,source_root TEXT,move_root TEXT)',
        'CREATE TABLE scan_runs (id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT)',
        'CREATE TABLE directories (id INT PRIMARY KEY,path TEXT,run_id INT,is_ignored INT)',
        'CREATE TABLE file_entries (id INT PRIMARY KEY,filename TEXT,directory_id INT,run_id INT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT)',
        'CREATE TABLE duplicate_groups (id INT PRIMARY KEY,run_id INT,rules_version INT,file_count INT)',
        'CREATE TABLE duplicate_group_members (id INT PRIMARY KEY,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items (id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,link_target_path TEXT,state TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,survivor_file_entry_id INT,result_code TEXT)',
        'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events (id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL');
    $db->insert('scan_profiles',['id'=>1,'name'=>'Music',
        'source_root'=>realpath($sourceDir),'move_root'=>realpath($backupDir)]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'path'=>realpath($sourceDir),'run_id'=>1,'is_ignored'=>0]);
    foreach([[1,'original.mp3'],[2,'survivor.mp3']] as [$id,$name]) {
        $path=$sourceDir.DIRECTORY_SEPARATOR.$name;
        $db->insert('file_entries',[
            'id'=>$id,'filename'=>$name,'directory_id'=>1,'run_id'=>1,
            'size_bytes'=>$size,'mtime_epoch'=>filemtime($path),
            'hash_method'=>'sha256','hash_value'=>$sha,'error_code'=>null
        ]);
    }
    $db->insert('duplicate_groups',['id'=>1,'run_id'=>1,'rules_version'=>1,'file_count'=>2]);
    foreach([[1,1],[2,2]] as [$id,$entry])
        $db->insert('duplicate_group_members',[
            'id'=>$id,'group_id'=>1,'file_entry_id'=>$entry
        ]);
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $actions=new FileActionService($db,$fs);
    $batch=$actions->plan(1,1,'link');
    $preview=$actions->find($batch);
    checkLink($preview['action_kind']==='link','Link preview missing');
    checkLink($preview['link_target_path']===$survivor,'Wrong surviving target selected');
    checkLink(file_exists($original) && !is_link($original),'Preview modified original');
    $job=$actions->confirm($batch,1);
    checkLink($job>0,'Link confirmation did not queue a job');
    $claimed=$jobs->claimNext($job);
    checkLink($claimed && $claimed['kind']==='file-link','Wrong link job claimed');
    checkLink((int)$claimed['progress_total']===4,'Link must queue four processing stages.');
    (new FileLinkWorker($db,$jobs,$actions,$fs))->run($claimed);
    $linkCounts=$db->fetchAssociative('SELECT progress_current,progress_total FROM jobs WHERE id=?',[$job]);
    checkLink((int)$linkCounts['progress_current']===4 && (int)$linkCounts['progress_total']===4,
        'Link completed with incorrect stage counts (expected 4 / 4).');
    checkLink(is_link($original) && readlink($original)===$survivor,'Correct symbolic link was not created');
    checkLink(file_get_contents($original)===$contents,'Link contents differ from original');
    checkLink(is_file($preview['target_path']),'Original file was not retained for recovery');
    checkLink(hash_file('sha256',$preview['target_path'])===$sha,'Stored original changed');
    checkLink((string)$db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')==='LINKED',
        'Index did not mark file linked');
    checkLink((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_LINK_CREATED'")===1,
        'Link creation audit missing');
    checkLink((int)$db->fetchOne("SELECT COUNT(*) FROM jobs WHERE kind='rebuild-duplicates'")===0,
        'Linking or unlinking queued an unsolicited duplicate rebuild.');
    $undoJob=$actions->restore($batch,1);
    $claimed=$jobs->claimNext($undoJob);
    checkLink($claimed && $claimed['kind']==='file-unlink','Wrong restore job claimed');
    (new FileLinkWorker($db,$jobs,$actions,$fs))->run($claimed);
    $unlinkCounts=$db->fetchAssociative('SELECT progress_current,progress_total FROM jobs WHERE id=?',[$undoJob]);
    checkLink((int)$unlinkCounts['progress_current']===3 && (int)$unlinkCounts['progress_total']===3,
        'Unlink completed with incorrect stage counts (expected 3 / 3).');
    checkLink(!is_link($original) && is_file($original),'Original file was not restored');
    checkLink(file_get_contents($original)===$contents,'Restored content differs');
    checkLink(!file_exists($preview['target_path']),'Backup remained after restore');
    checkLink($db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')===null,
        'Index still labels restored file linked');
    checkLink((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_LINK_RESTORED'")===1,
        'Link restore audit missing');
    // Existing quarantine and restore workflows must still work with the
    // shared action planner after the link support was added.
    $quarantine=$actions->plan(1,1);
    checkLink($actions->find($quarantine)['action_kind']==='quarantine','Quarantine plan changed kind');
    $qJob=$actions->confirm($quarantine,1);
    $claimed=$jobs->claimNext($qJob);
    checkLink($claimed && $claimed['kind']==='file-action','Quarantine job kind changed');
    (new FileActionWorker($db,$jobs,$actions,$fs))->run($claimed);
    checkLink(!file_exists($original),'Quarantine did not move original');
    checkLink((string)$db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')==='QUARANTINED',
        'Quarantine status missing');
    $qUndo=$actions->restore($quarantine,1);
    $claimed=$jobs->claimNext($qUndo);
    checkLink($claimed && $claimed['kind']==='file-restore','Quarantine restore job kind changed');
    (new FileActionWorker($db,$jobs,$actions,$fs))->run($claimed);
    checkLink(file_get_contents($original)===$contents,'Quarantined original could not be restored');
    checkLink($db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')===null,
        'Quarantine restore did not clear scan status');
    // Candidate CRC32 groups must never replace a file with a link.
    $db->executeStatement('UPDATE duplicate_groups SET rules_version=2 WHERE id=1');
    try {$actions->plan(1,1,'link');throw new RuntimeException('CRC32-only link was approved');}
    catch(DomainException) {}
    echo "PASS verified link replacement, SHA-256, original backup, exact-link restore, audit, candidate rejection\n";
} finally {
    $remove=function(string $path) use (&$remove):void {
        if(!is_dir($path) || is_link($path)) {
            if(file_exists($path) || is_link($path)) @unlink($path);
            return;
        }
        foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry)
            $remove($entry->getPathname());
        @rmdir($path);
    };
    $remove($root);
}
