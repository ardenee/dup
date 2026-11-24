<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\FileMoveService;
use App\Module\FileActions\Service\FileMoveWorker;
use App\Module\FileActions\Service\MovePathPlanner;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\ActionSettings;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function checkMove(bool $pass,string $why):void {
    if(!$pass) throw new RuntimeException($why);
}
$temp=realpath(sys_get_temp_dir())?:sys_get_temp_dir();
$root=$temp.DIRECTORY_SEPARATOR.'dup-move-'.bin2hex(random_bytes(6));
$scan=$root.DIRECTORY_SEPARATOR.'scanned';
$to=$root.DIRECTORY_SEPARATOR.'relocated';
$sub=$scan.DIRECTORY_SEPARATOR.'Author'.DIRECTORY_SEPARATOR.'Novel';
mkdir($sub,0700,true);
mkdir($to,0700,true);
$original=$sub.DIRECTORY_SEPARATOR.'chapter.m4b';
$destination=$to.DIRECTORY_SEPARATOR.'Author'.DIRECTORY_SEPARATOR.'Novel'.DIRECTORY_SEPARATOR.'chapter.m4b';
file_put_contents($original,str_repeat('unique sample audiobook data!',512));
$bytes=filesize($original);$mtime=filemtime($original);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT,source_root TEXT,move_root TEXT)',
        'CREATE TABLE action_settings (setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT)',
        'CREATE TABLE scan_runs (id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT)',
        'CREATE TABLE directories (id INT PRIMARY KEY,path TEXT,run_id INT,is_ignored INT)',
        'CREATE TABLE file_entries (id INT PRIMARY KEY,filename TEXT,directory_id INT,run_id INT,size_bytes INT,mtime_epoch INT,error_code TEXT)',
        'CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items (id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,state TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,result_code TEXT)',
        'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events (id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->insert('scan_profiles',[
        'id'=>1,'name'=>'Audio Books','source_root'=>realpath($scan),'move_root'=>realpath($to)
    ]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'path'=>realpath($sub),'run_id'=>1,'is_ignored'=>0]);
    $db->insert('file_entries',[
        'id'=>1,'filename'=>'chapter.m4b','directory_id'=>1,'run_id'=>1,
        'size_bytes'=>$bytes,'mtime_epoch'=>$mtime,'error_code'=>null
    ]);
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $planner=new MovePathPlanner($fs);
    $jobs=new JobStore($db,new DirectoryBrowser());
    $actions=new FileActionService($db,$fs);
    $service=new FileMoveService($db,$fs,$planner,$actions,new ActionSettings($db));
    checkMove($planner->destination($scan,$to,$sub,'chapter.m4b')===$destination,
        'Destination does not preserve folders');
    try{$planner->destination($scan,$scan,$sub,'chapter.m4b');throw new RuntimeException('Allowed root to equal source');}
    catch(DomainException){}
    try{$planner->destination($scan,$sub,$sub,'chapter.m4b');throw new RuntimeException('Allowed move root inside scan tree');}
    catch(DomainException){}
    mkdir(dirname($destination),0700,true);
    file_put_contents($destination,'do not overwrite');
    try{$service->plan([1],1);throw new RuntimeException('Allowed collision');}
    catch(DomainException){}
    checkMove(file_get_contents($destination)==='do not overwrite','Target was overwritten');
    unlink($destination);
    $batch=$service->plan([1],1);
    checkMove($batch>0,'No preview created');
    checkMove(file_exists($original),'Preview modified source');
    $details=$service->details($batch);
    checkMove(count($details['items'])===1,'Preview missed selected file');
    checkMove($details['items'][0]['target_path']===$destination,'Wrong preview target');
    $jobId=$service->confirm($batch,1);
    checkMove($jobId>0,'No move job queued');
    try{$service->confirm($batch,1);throw new RuntimeException('Duplicate confirmation');}
    catch(DomainException){}
    $job=$jobs->claimNext($jobId);
    checkMove($job && $job['kind']==='file-move','Move job not claimed');
    (new FileMoveWorker($db,$jobs,$fs,$planner,$actions))->run($job);
    checkMove(!file_exists($original) && file_exists($destination),'Source was not moved');
    checkMove((string)$db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')==='MOVED',
        'Moved scan record did not change');
    checkMove((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batch])==='completed',
        'Move batch did not complete');
    checkMove((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_MOVED'")===1,
        'Move audit event missing');
    $digest=(string)$db->fetchOne('SELECT expected_sha256 FROM file_action_items WHERE batch_id=?',[$batch]);
    checkMove(hash_equals(hash_file('sha256',$destination),$digest),'Full SHA-256 missing');
    // The standard background worker would now process the duplicate recalculation.
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $restoreJob=$service->restore($batch,1);
    checkMove($restoreJob>0,'No restore job queued');
    $job=$jobs->claimNext($restoreJob);
    checkMove($job && $job['kind']==='file-move-restore','Restore job not claimed');
    (new FileMoveWorker($db,$jobs,$fs,$planner,$actions))->run($job);
    checkMove(file_exists($original) && !file_exists($destination),'Restore failed');
    checkMove(file_get_contents($original)===str_repeat('unique sample audiobook data!',512),
        'Restored data changed');
    checkMove($db->fetchOne('SELECT error_code FROM file_entries WHERE id=1')===null,
        'Restore left an error on the scan index');
    checkMove((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batch])==='restored',
        'Restored batch state incorrect');
    checkMove((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_MOVE_RESTORED'")===1,
        'Restore audit missing');

    // Legacy "Append Number" must be resolved in the preview and must not
    // replace either existing files or the original during execution.
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    file_put_contents($destination,'KEEP EXISTING');
    (new ActionSettings($db))->save(ActionSettings::NUMBER,1);
    $numbered=$service->plan([1],1);
    $second=$service->details($numbered);
    $numberedPath=dirname($destination).DIRECTORY_SEPARATOR.'chapter (1).m4b';
    checkMove($second['items'][0]['target_path']===$numberedPath,
        'Append Number failed to produce the expected preview path');
    checkMove(file_exists($original),'Append Number preview moved the source');
    $numberedJob=$service->confirm($numbered,1);
    $job=$jobs->claimNext($numberedJob);
    checkMove($job!==null,'Numbered move job was not claimed');
    (new FileMoveWorker($db,$jobs,$fs,$planner,$actions))->run($job);
    checkMove(!file_exists($original) && file_exists($numberedPath),
        'Numbered move did not execute');
    checkMove(file_get_contents($destination)==='KEEP EXISTING',
        'Existing target was overwritten by numbered move');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $secondRestore=$service->restore($numbered,1);
    $job=$jobs->claimNext($secondRestore);
    checkMove($job!==null,'Numbered restore job was not claimed');
    (new FileMoveWorker($db,$jobs,$fs,$planner,$actions))->run($job);
    checkMove(file_exists($original) && !file_exists($numberedPath),
        'Numbered move was not reversible');
    checkMove(file_get_contents($destination)==='KEEP EXISTING',
        'Restore changed the original destination');

    echo "PASS reversible same-device file move, preview numbering, collisions, SHA-256, restore and audit\n";
} finally {
    // Clean up ONLY the uniquely named temporary test directory.
    $walk=function(string $path) use (&$walk):void {
        if(!is_dir($path) || is_link($path)) {
            if(file_exists($path) || is_link($path)) @unlink($path);
            return;
        }
        foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry)
            $walk($entry->getPathname());
        @rmdir($path);
    };
    $walk($root);
}
