<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\SelectedFileIds;
use App\Module\FileActions\Service\FileDeletionService;
use App\Module\FileActions\Service\FileDeletionWorker;
use App\Module\FileActions\Service\BulkDuplicateService;
use App\Module\FileActions\Service\FileMoveService;
use App\Module\FileActions\Service\MovePathPlanner;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\ActionSettings;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Renaming\Service\RenameService;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Symfony\Component\HttpFoundation\Request;
use Doctrine\DBAL\DriverManager;

function ensureLarge(bool $valid,string $reason):void {
    if(!$valid)throw new RuntimeException($reason);
}
$ids=range(1,161);
$compact=Request::create('/delete/preview','POST',['file_ids'=>implode(',',$ids)]);
ensureLarge(SelectedFileIds::fromRequest($compact)===$ids,
    'Compact selection lost one of 161 file IDs.');
$legacy=Request::create('/delete/preview','POST',['files'=>$ids]);
ensureLarge(SelectedFileIds::fromRequest($legacy)===$ids,
    'The old files[] input stopped working.');
foreach(['1,1','1,-2','1,','0,2','1,x'] as $invalid) {
    try {
        SelectedFileIds::fromRequest(Request::create('/test','POST',['file_ids'=>$invalid]));
        throw new RuntimeException('Accepted invalid file selection '.$invalid);
    } catch(DomainException $ex) {}
}
$big=range(1,2100);
ensureLarge(count(SelectedFileIds::fromRequest(Request::create(
    '/test','POST',['file_ids'=>implode(',',$big)]
)))===2100,'PHP input-variable limits cut off large compact selections.');

$parent=sys_get_temp_dir().'/dup-unlimited-actions-'.bin2hex(random_bytes(5));
$source=$parent.'/source';
$destination=$parent.'/destination';
if(!mkdir($source,0700,true) || !mkdir($destination,0700,true))
    throw new RuntimeException('Could not create the temporary test folders.');
$payload='duplicate contents for bulk testing';
$sha=hash('sha256',$payload);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT,source_root TEXT,move_root TEXT,file_exists_policy TEXT)',
        'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,verification_method TEXT,started_at TEXT)',
        'CREATE TABLE directories(id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)',
        'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT,is_symlink INT)',
        'CREATE TABLE duplicate_groups(id INT PRIMARY KEY,run_id INT,rules_version INT,file_count INT)',
        'CREATE TABLE duplicate_group_members(id INT PRIMARY KEY,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,link_target_path TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,survivor_file_entry_id INT,state TEXT,result_code TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $schema)$db->executeStatement($schema);
    foreach([
        'ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0',
        'ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL',
        'ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL',
        'ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL',
        'ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL'
    ] as $schema)$db->executeStatement($schema);
    $db->insert('scan_profiles',[
        'id'=>1,'name'=>'Music','source_root'=>realpath($source),
        'move_root'=>realpath($destination),'file_exists_policy'=>'fail'
    ]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'run_id'=>1,'path'=>realpath($source),'is_ignored'=>0]);
    $db->insert('duplicate_groups',['id'=>1,'run_id'=>1,'rules_version'=>1,'file_count'=>162]);
    foreach(range(1,162) as $id) {
        $name=sprintf('Track_%04d.mp3',$id);
        $file=$source.'/'.$name;
        file_put_contents($file,$payload);
        $db->insert('file_entries',[
            'id'=>$id,'run_id'=>1,'directory_id'=>1,'filename'=>$name,
            'size_bytes'=>filesize($file),'mtime_epoch'=>filemtime($file),
            'hash_method'=>'sha256','hash_value'=>$sha,'error_code'=>null,'is_symlink'=>0
        ]);
        $db->insert('duplicate_group_members',['id'=>$id,'group_id'=>1,'file_entry_id'=>$id]);
    }
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $audit=new FileActionService($db,$fs);
    $jobs=new JobStore($db,new DirectoryBrowser());
    $settings=new ActionSettings($db);
    $move=new FileMoveService($db,$fs,new MovePathPlanner($fs),$audit,$settings);
    $rename=new RenameService($db,$fs,$audit,$settings);
    $bulk=new BulkDuplicateService($db,$fs,$audit);
    $delete=new FileDeletionService($db,$fs,$audit);

    // All operations preview 161 files without modifying any source file.
    $moveBatch=$move->plan($ids,7);
    ensureLarge(count($move->details($moveBatch)['items'])===161,
        'Move preview lost file selections beyond the old 50-file cap.');
    $bulkBatch=$bulk->plan($ids,7,'bulk_quarantine');
    ensureLarge(count($bulk->details($bulkBatch)['items'])===161,
        'Bulk duplicate preview lost selections beyond the old cap.');
    try {
        $bulk->plan(range(1,162),7,'bulk_link');
        throw new RuntimeException('Bulk link accepted removal of the final survivor.');
    } catch(DomainException $ex) {
        ensureLarge(str_contains($ex->getMessage(),'survivor'),
            'Bulk link rejected the full group for an unexpected reason.');
    }

    $newNames=[];
    foreach($ids as $id)$newNames[$id]=sprintf('Renamed_%04d.mp3',$id);
    $renameJob=$rename->queue(1,7,$ids,'',0,0,false,$newNames,'','','fail',true);
    ensureLarge((int)$db->fetchOne('SELECT progress_total FROM jobs WHERE id=?',[$renameJob])===161,
        'Rename queued job has incorrect total.');
    // The rename test deliberately does not execute; release the synthetic job.
    $db->executeStatement("UPDATE jobs SET state='cancelled',active_profile_id=NULL WHERE id=?",[$renameJob]);
    ensureLarge(is_file($source.'/Track_0001.mp3'),
        'Preview unexpectedly renamed files.');

    $deleteBatch=$delete->plan($ids,7);
    ensureLarge(count($delete->details($deleteBatch)['items'])===161,
        'Delete preview did not include all 161 selected files.');
    ensureLarge(is_file($source.'/Track_0161.mp3'),'Preview deleted a selected file.');
    $jobId=$delete->confirm($deleteBatch,7);
    ensureLarge((int)$db->fetchOne('SELECT progress_total FROM jobs WHERE id=?',[$jobId])===161,
        'Queued deletion did not retain the full 161-file count.');
    (new FileDeletionWorker($db,$jobs,$fs,$audit))->run($jobs->claimNext($jobId));
    $completed=$db->fetchAssociative('SELECT state,progress_current,progress_total FROM jobs WHERE id=?',[$jobId]);
    ensureLarge($completed['state']==='completed' &&
        (int)$completed['progress_current']===161 &&
        (int)$completed['progress_total']===161,
        '161-file deletion did not finish with 161 / 161 progress.');
    ensureLarge((int)$db->fetchOne("SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state='deleted'",[$deleteBatch])===161,
        'Some files were not deleted or were silently skipped.');
    ensureLarge(!file_exists($source.'/Track_0161.mp3') &&
        is_file($source.'/Track_0162.mp3'),
        'Deletion affected the wrong files or failed to preserve the unselected file.');
    echo "PASS 161-file delete/move/bulk/rename, complete job counters, survivor rule and 2100-ID form selection\n";
} finally {
    $remove=function(string $path) use (&$remove):void {
        if(!is_dir($path) || is_link($path)) {
            if(file_exists($path)||is_link($path))@unlink($path);
            return;
        }
        foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry)
            $remove($entry->getPathname());
        @rmdir($path);
    };
    $remove($parent);
}
