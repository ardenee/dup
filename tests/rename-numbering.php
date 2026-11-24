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

function assertNumber(bool $ok,string $why):void {if(!$ok) throw new RuntimeException($why);}
$folder=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-number-'.bin2hex(random_bytes(5));
assertNumber(mkdir($folder,0700,true),'Could not create test directory');
try{
    file_put_contents($folder.'/Demo.flac','LEAVE ORIGINAL ALONE');
    file_put_contents($folder.'/Demo_[copy].flac','new-audio-A');
    file_put_contents($folder.'/Demo_[copy2].flac','new-audio-B');
    assertNumber(
        RenameRules::availableName($folder,'Demo.flac')==='Demo (1).flac',
        'Collision suffix is wrong'
    );
    file_put_contents($folder.'/Demo (1).flac','SECOND ORIGINAL');
    assertNumber(
        RenameRules::availableName($folder,'Demo.flac')==='Demo (2).flac',
        'Next available suffix is wrong'
    );
    unlink($folder.'/Demo (1).flac');
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT,file_exists_policy TEXT NOT NULL DEFAULT "fail")',
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
    ] as $sql)$db->executeStatement($sql);
    $db->executeStatement("INSERT INTO scan_profiles(id,name) VALUES(1,'Music')");
    $db->executeStatement("INSERT INTO scan_runs(id,profile_id,state) VALUES(1,1,'completed')");
    $db->insert('directories',['id'=>1,'path'=>realpath($folder),'run_id'=>1,'is_ignored'=>0]);
    foreach(['Demo_[copy].flac','Demo_[copy2].flac'] as $i=>$name) {
        $path=$folder.DIRECTORY_SEPARATOR.$name;
        $db->insert('file_entries',[
            'id'=>$i+1,'filename'=>$name,'directory_id'=>1,'run_id'=>1,
            'size_bytes'=>filesize($path),'mtime_epoch'=>filemtime($path),'error_code'=>null
        ]);
    }
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $svc=new RenameService($db,$fs,new FileActionService($db,$fs),new \App\Module\FileActions\Service\ActionSettings($db));
    $preview=$svc->candidates(1,'_[copy],_[copy2]',0,0,false,false,null,true);
    assertNumber(count($preview['rows'])===2,'Both candidates must be listed');
    assertNumber($preview['rows'][0]['new_name']==='Demo (1).flac','Wrong first reserved name');
    assertNumber($preview['rows'][1]['new_name']==='Demo (2).flac','Wrong second reserved name');
    assertNumber($preview['rows'][0]['collision_numbered'] && $preview['rows'][1]['collision_numbered'],
        'Numbered candidates not labeled');
    $names=[];
    foreach($preview['rows'] as $row)$names[(int)$row['id']]=$row['new_name'];
    // The approval is for those exact previewed names; no silent renumbering.
    $jobId=$svc->queue(1,7,[1,2],'_[copy],_[copy2]',0,0,false,$names);
    $job=$jobs->claimNext($jobId);
    assertNumber($job!==null,'Could not claim test rename job');
    (new RenameWorker($db,$jobs,$fs,new FileActionService($db,$fs)))->run($job);
    assertNumber(file_get_contents($folder.'/Demo.flac')==='LEAVE ORIGINAL ALONE',
        'Existing file overwritten');
    assertNumber(file_get_contents($folder.'/Demo (1).flac')==='new-audio-A','First numbered file missing');
    assertNumber(file_get_contents($folder.'/Demo (2).flac')==='new-audio-B','Second numbered file missing');
    assertNumber((int)$db->fetchOne("SELECT COUNT(*) FROM file_action_items WHERE state='renamed'")===2,
        'Renamed batch did not complete');

    // A user manually entering an occupied name must get the per-profile
    // Append Number behavior, even though the auto-cleanup preview remains exact.
    $db->update('scan_profiles',['file_exists_policy'=>'number'],['id'=>1]);
    $manual=$folder.'/Demo_[copy3].flac';
    file_put_contents($manual,'new-audio-C');
    $db->insert('file_entries',[
        'id'=>3,'filename'=>basename($manual),'directory_id'=>1,'run_id'=>1,
        'size_bytes'=>filesize($manual),'mtime_epoch'=>filemtime($manual),'error_code'=>null
    ]);
    $manualJob=$svc->queue(1,7,[3],'',0,0,false,[3=>'Demo.flac'],'','','number',true);
    (new RenameWorker($db,$jobs,$fs,new FileActionService($db,$fs)))->run($jobs->claimNext($manualJob));
    assertNumber(file_get_contents($folder.'/Demo (3).flac')==='new-audio-C',
        'Manual rename ignored this profile\'s Append Number policy.');
    assertNumber(file_get_contents($folder.'/Demo.flac')==='LEAVE ORIGINAL ALONE',
        'Manual rename damaged the occupied destination.');
    echo "PASS numbered collisions, previewed and manual renames, exact planned names and worker audit\n";
}finally{
    foreach(glob($folder.DIRECTORY_SEPARATOR.'*')?:[] as $file) @unlink($file);
    @rmdir($folder);
}
