<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\FileDeletionService;
use App\Module\FileActions\Service\FileDeletionWorker;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function assertDelete(bool $pass,string $message):void {
    if(!$pass)throw new RuntimeException($message);
}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-delete-'.bin2hex(random_bytes(5));
assertDelete(mkdir($root,0700,true),'Cannot create test directory.');
$names=['A.mp3','B.mp3','C.mp3'];
$payload=str_repeat('Media test bytes for permanent deletion!',1000);
foreach($names as $name)file_put_contents($root.DIRECTORY_SEPARATOR.$name,$payload);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT,source_root TEXT)',
        'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,verification_method TEXT,started_at TEXT)',
        'CREATE TABLE directories(id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)',
        'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT,is_symlink INT)',
        'CREATE TABLE duplicate_groups(id INT PRIMARY KEY,run_id INT,rules_version INT,file_count INT)',
        'CREATE TABLE duplicate_group_members(id INT PRIMARY KEY,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,state TEXT,result_code TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql)$db->executeStatement($sql);
    $db->insert('scan_profiles',['id'=>1,'name'=>'Music','source_root'=>realpath($root)]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'run_id'=>1,'path'=>realpath($root),'is_ignored'=>0]);
    $db->insert('duplicate_groups',['id'=>1,'run_id'=>1,'rules_version'=>1,'file_count'=>3]);
    foreach($names as $i=>$name) {
        $path=$root.DIRECTORY_SEPARATOR.$name;
        $db->insert('file_entries',[
            'id'=>$i+1,'run_id'=>1,'directory_id'=>1,'filename'=>$name,
            'size_bytes'=>filesize($path),'mtime_epoch'=>filemtime($path),
            'hash_method'=>'sha256','hash_value'=>hash_file('sha256',$path),
            'error_code'=>null,'is_symlink'=>0
        ]);
        $db->insert('duplicate_group_members',['id'=>$i+1,'group_id'=>1,'file_entry_id'=>$i+1]);
    }
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $audit=new FileActionService($db,$fs);
    $service=new FileDeletionService($db,$fs,$audit);
    $worker=new FileDeletionWorker($db,$jobs,$fs,$audit);
    $batchId=$service->plan([1,2,3],8);
    $preview=$service->details($batchId);
    assertDelete(count($preview['items'])===3 && $preview['state']==='planned',
        'Deletion preview did not include all requested files.');
    assertDelete(is_file($root.'/A.mp3'),'Preview unexpectedly modified a file.');
    $jobId=$service->confirm($batchId,8);
    $job=$jobs->claimNext($jobId);
    assertDelete($job && $job['kind']==='file-delete','Deletion job was not dispatched.');
    $worker->run($job);
    foreach($names as $name)
        assertDelete(!file_exists($root.'/'.$name),'Selected file was not permanently deleted: '.$name);
    assertDelete((int)$db->fetchOne("SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state='deleted'",[$batchId])===3,
        'Deletion status not stored per file.');
    assertDelete((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batchId])==='completed',
        'Completed delete batch status missing.');
    assertDelete((int)$db->fetchOne("SELECT COUNT(*) FROM jobs WHERE kind='rebuild-duplicates'")===1,
        'Duplicate group rebuild not queued after deleting files.');
    assertDelete((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='FILE_DELETED'")===3,
        'Individual file deletions not audited.');
    assertDelete((int)$db->fetchOne("SELECT COUNT(*) FROM file_entries WHERE error_code='DELETED'")===3,
        'Deleted scan entries still show as available.');
    // Clear the deliberately pending rebuild job, then verify stale-file protection.
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $file=$root.'/D.mp3';
    file_put_contents($file,$payload);
    $db->insert('file_entries',[
        'id'=>4,'run_id'=>1,'directory_id'=>1,'filename'=>'D.mp3',
        'size_bytes'=>filesize($file),'mtime_epoch'=>filemtime($file),
        'hash_method'=>'sha256','hash_value'=>hash_file('sha256',$file),
        'error_code'=>null,'is_symlink'=>0
    ]);
    $db->insert('duplicate_group_members',['id'=>4,'group_id'=>1,'file_entry_id'=>4]);
    $other=$service->plan([4],8);
    $job=$service->confirm($other,8);
    file_put_contents($file,'Modified after delete approval');
    $worker->run($jobs->claimNext($job));
    assertDelete(file_exists($file) && file_get_contents($file)==='Modified after delete approval',
        'Changed file was deleted.');
    assertDelete((string)$db->fetchOne(
        'SELECT state FROM file_action_batches WHERE id=?',[$other])==='failed',
        'Changed file did not mark batch failure.');
    $twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
        ['autoescape'=>'html']);
    foreach([
        'path'=>static fn(string $name,array $args=[])=>'/'.$name,
        'asset'=>static fn(string $name)=>'/assets/'.$name,
        'csrf_token'=>static fn(string $name)=>'token-'.$name,
        'is_granted'=>static fn()=>true,
        'dup_navigation'=>static fn()=>[]
    ] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
    $html=$twig->render('actions/delete.html.twig',[
        'app'=>['user'=>null,'flashes'=>[],'environment'=>'test'],
        'batch'=>$preview
    ]);
    foreach(['Delete 3 files permanently','permanent','A.mp3','B.mp3','C.mp3',
        'token-confirm_delete_'.$batchId] as $needle)
        assertDelete(str_contains($html,$needle),'Delete confirmation view missing: '.$needle);
    echo "PASS permanent single/bulk delete, no survivor requirement, confirmation, job audit, stale-file rejection and index update\n";
} finally {
    foreach(new DirectoryIterator($root) as $entry)
        if(!$entry->isDot())@unlink($entry->getPathname());
    @rmdir($root);
}
