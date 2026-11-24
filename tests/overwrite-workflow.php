<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\{ActionSettings,FileMoveService,FileMoveWorker,MovePathPlanner,SafeFileSystem,FileActionService};
use App\Module\Renaming\Service\{RenameService,RenameWorker};
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function assertOverwrite(bool $okay,string $message):void {
    if(!$okay)throw new RuntimeException($message);
}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-overwrite-'.bin2hex(random_bytes(5));
$source=$root.DIRECTORY_SEPARATOR.'scan';
$destination=$root.DIRECTORY_SEPARATOR.'moved';
mkdir($source,0700,true);mkdir($destination,0700,true);
$from=$source.DIRECTORY_SEPARATOR.'chapter.m4b';
$to=$destination.DIRECTORY_SEPARATOR.'chapter.m4b';
$new=str_repeat('NEW MEDIA',1000);
$old=str_repeat('OLD MEDIA',450);
file_put_contents($from,$new);
file_put_contents($to,$old);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT,source_root TEXT,move_root TEXT)',
        'CREATE TABLE action_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT)',
        'CREATE TABLE scan_runs(id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT)',
        'CREATE TABLE directories(id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)',
        'CREATE TABLE file_entries(id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,error_code TEXT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,overwrite_target_size INT,overwrite_target_mtime INT,overwrite_backup_path TEXT,state TEXT,result_code TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql)$db->executeStatement($sql);
    $db->insert('scan_profiles',[
        'id'=>1,'name'=>'Audio','source_root'=>realpath($source),'move_root'=>realpath($destination)
    ]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'run_id'=>1,'path'=>realpath($source),'is_ignored'=>0]);
    $db->insert('file_entries',[
        'id'=>1,'run_id'=>1,'directory_id'=>1,'filename'=>'chapter.m4b',
        'size_bytes'=>filesize($from),'mtime_epoch'=>filemtime($from),'error_code'=>null
    ]);
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $audit=new FileActionService($db,$fs);
    $settings=new ActionSettings($db);
    $settings->save(ActionSettings::OVERWRITE,7);
    $moves=new FileMoveService($db,$fs,new MovePathPlanner($fs),$audit,$settings);
    $moveWorker=new FileMoveWorker($db,$jobs,$fs,new MovePathPlanner($fs),$audit);
    $batch=$moves->plan([1],7);
    $plan=$moves->details($batch)['items'][0];
    assertOverwrite((int)$plan['overwrite_target_size']===strlen($old),
        'Overwrite preview failed to record the approved destination size.');
    $backup=$plan['overwrite_backup_path'];
    assertOverwrite(str_contains($backup,'.dup-replaced-'),'Overwrite preview has no backup plan.');
    assertOverwrite(file_get_contents($to)===$old,'Move preview modified destination.');
    $job=$moves->confirm($batch,7);
    $moveWorker->run($jobs->claimNext($job));
    assertOverwrite(!file_exists($from),'Moved file remains at original location.');
    assertOverwrite(file_get_contents($to)===$new,'Existing destination was not overwritten.');
    assertOverwrite(!file_exists($backup),'Temporary overwritten destination was not cleaned up.');
    assertOverwrite((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batch])==='completed',
        'Overwritten move not marked complete.');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $restoreJob=$moves->restore($batch,7);
    $moveWorker->run($jobs->claimNext($restoreJob));
    assertOverwrite(file_get_contents($from)===$new && !file_exists($to),
        'Restoring a moved source did not restore its location.');
    // Overwrite is permanent: restoring a move does not resurrect the old destination.
    assertOverwrite(!file_exists($backup),'Old destination was secretly retained.');

    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    // Failure after the destination is backed up must restore both files.
    file_put_contents($to,$old);
    $failureBatch=$moves->plan([1],7);
    $failureJob=$moves->confirm($failureBatch,7);
    $db->executeStatement(
        "CREATE TRIGGER reject_move BEFORE UPDATE ON file_entries ".
        "WHEN NEW.error_code='MOVED' BEGIN SELECT RAISE(ABORT,'injected persistence failure'); END"
    );
    $moveWorker->run($jobs->claimNext($failureJob));
    $db->executeStatement("DROP TRIGGER reject_move");
    assertOverwrite(file_get_contents($from)===$new && file_get_contents($to)===$old,
        'Overwrite rollback lost source data or original destination data.');
    assertOverwrite((string)$db->fetchOne(
        'SELECT state FROM file_action_batches WHERE id=?',[$failureBatch])==='failed',
        'Interrupted overwrite was not recorded as failed.');
    assertOverwrite(!file_exists((string)$db->fetchOne(
        'SELECT overwrite_backup_path FROM file_action_items WHERE batch_id=?',[$failureBatch])),
        'Rollback left a stale temporary backup.');
    // Any destination changed after approval must remain untouched.
    $changedBatch=$moves->plan([1],7);
    $changedJob=$moves->confirm($changedBatch,7);
    $unexpected='Changed after approval and must not be lost!';
    file_put_contents($to,$unexpected);
    $moveWorker->run($jobs->claimNext($changedJob));
    assertOverwrite(file_get_contents($to)===$unexpected && file_get_contents($from)===$new,
        'Overwrite accepted a destination modified after preview.');
    unlink($to);
    // Rename overwrite within the scanned source; mark the old indexed destination overwritten.
    $sourceFilename='Demo_[copy].flac';$targetFilename='Demo.flac';
    $sourcePath=$source.DIRECTORY_SEPARATOR.$sourceFilename;
    $targetPath=$source.DIRECTORY_SEPARATOR.$targetFilename;
    $renameContent=str_repeat('new flac',1200);
    $oldContent=str_repeat('old flac',400);
    file_put_contents($sourcePath,$renameContent);
    file_put_contents($targetPath,$oldContent);
    $db->insert('file_entries',[
        'id'=>2,'run_id'=>1,'directory_id'=>1,'filename'=>$sourceFilename,
        'size_bytes'=>filesize($sourcePath),'mtime_epoch'=>filemtime($sourcePath),'error_code'=>null
    ]);
    $db->insert('file_entries',[
        'id'=>3,'run_id'=>1,'directory_id'=>1,'filename'=>$targetFilename,
        'size_bytes'=>filesize($targetPath),'mtime_epoch'=>filemtime($targetPath),'error_code'=>null
    ]);
    $rename=new RenameService($db,$fs,$audit);
    $renameJob=$rename->queue(1,7,[2],'_[copy]',0,0,false,[], '', '', 'overwrite');
    $renameBatch=(int)$db->fetchOne('SELECT file_action_batch_id FROM jobs WHERE id=?',[$renameJob]);
    $renameItem=$db->fetchAssociative('SELECT * FROM file_action_items WHERE batch_id=?',[$renameBatch]);
    assertOverwrite($renameItem['overwrite_backup_path']!==null,
        'Overwrite rename not staged for the existing target.');
    $renameWorker=new RenameWorker($db,$jobs,$fs,$audit);
    $renameWorker->run($jobs->claimNext($renameJob));
    assertOverwrite(!file_exists($sourcePath) && file_get_contents($targetPath)===$renameContent,
        'Rename overwrite failed.');
    assertOverwrite(!file_exists($renameItem['overwrite_backup_path']),
        'Rename temporary overwritten file left behind.');
    assertOverwrite((string)$db->fetchOne('SELECT error_code FROM file_entries WHERE id=3')==='OVERWRITTEN',
        'Previous target index entry still claims to be valid.');
    assertOverwrite((string)$db->fetchOne('SELECT filename FROM file_entries WHERE id=2')===$targetFilename,
        'Renamed source index record not updated.');
    assertOverwrite((int)$db->fetchOne("SELECT COUNT(*) FROM jobs WHERE kind='rebuild-duplicates'")===3,
        'Renamed duplicate groups were not queued for recalculation.');
    echo "PASS Move and Rename Overwrite: approved existing targets, staged rollback backup, permanent replacement, index and restore\n";
} finally {
    foreach([$source,$destination] as $dir) {
        if(!is_dir($dir))continue;
        foreach(new FilesystemIterator($dir,FilesystemIterator::SKIP_DOTS) as $item)@unlink($item->getPathname());
        @rmdir($dir);
    }
    @rmdir($root);
}
