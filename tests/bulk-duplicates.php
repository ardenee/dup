<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\BulkDuplicateService;
use App\Module\FileActions\Service\BulkDuplicateWorker;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\FileActions\Service\FileActionService;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\DriverManager;

function assertBulk(bool $yes,string $message):void {
    if(!$yes) throw new RuntimeException($message);
}
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-bulk-'.bin2hex(random_bytes(6));
$source=$root.DIRECTORY_SEPARATOR.'source';
$backup=$root.DIRECTORY_SEPARATOR.'recovery';
assertBulk(mkdir($source,0700,true) && mkdir($backup,0700,true),'Cannot create bulk test directories');
$content=str_repeat('verified duplicate payload 2026',512);
$names=['A.mp3','B.mp3','C.mp3','D.mp3'];
foreach($names as $name) file_put_contents($source.DIRECTORY_SEPARATOR.$name,$content);
$sha=hash_file('sha256',$source.DIRECTORY_SEPARATOR.$names[0]);
try {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,name TEXT,source_root TEXT,move_root TEXT)',
        'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT)',
        'CREATE TABLE directories(id INTEGER PRIMARY KEY,path TEXT,run_id INT,is_ignored INT)',
        'CREATE TABLE file_entries(id INTEGER PRIMARY KEY,filename TEXT,directory_id INT,run_id INT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT)',
        'CREATE TABLE duplicate_groups(id INTEGER PRIMARY KEY,run_id INT,rules_version INT,file_count INT)',
        'CREATE TABLE duplicate_group_members(id INTEGER PRIMARY KEY,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs(id INTEGER PRIMARY KEY AUTOINCREMENT,kind TEXT,profile_id INT,active_profile_id INT UNIQUE,run_id INT,file_action_batch_id INT,state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,attempts INT,worker_token TEXT,heartbeat_at TEXT,started_at TEXT,finished_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,profile_id INT,action_kind TEXT,state TEXT,requested_at TEXT,expires_at TEXT,confirmed_at TEXT,completed_at TEXT,error_code TEXT)',
        'CREATE TABLE file_action_items(id INTEGER PRIMARY KEY AUTOINCREMENT,batch_id INT,file_entry_id INT,source_path TEXT,target_path TEXT,link_target_path TEXT,expected_size INT,expected_mtime INT,expected_sha256 TEXT,survivor_file_entry_id INT,state TEXT,result_code TEXT)',
        'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)',
        'CREATE TABLE job_events(id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL');
    $db->executeStatement('ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL');
    $db->insert('scan_profiles',[
        'id'=>1,'name'=>'Music','source_root'=>realpath($source),'move_root'=>realpath($backup)
    ]);
    $db->insert('scan_runs',['id'=>1,'profile_id'=>1,'state'=>'completed']);
    $db->insert('directories',['id'=>1,'path'=>realpath($source),'run_id'=>1,'is_ignored'=>0]);
    $db->insert('duplicate_groups',['id'=>1,'run_id'=>1,'rules_version'=>1,'file_count'=>4]);
    foreach($names as $index=>$name) {
        $file=$source.DIRECTORY_SEPARATOR.$name;
        $db->insert('file_entries',[
            'id'=>$index+1,'filename'=>$name,'directory_id'=>1,'run_id'=>1,
            'size_bytes'=>filesize($file),'mtime_epoch'=>filemtime($file),
            'hash_method'=>'sha256','hash_value'=>$sha,'error_code'=>null
        ]);
        $db->insert('duplicate_group_members',[
            'id'=>$index+1,'group_id'=>1,'file_entry_id'=>$index+1
        ]);
    }
    $fs=new SafeFileSystem(new DirectoryBrowser());
    $jobs=new JobStore($db,new DirectoryBrowser());
    $audit=new FileActionService($db,$fs);
    $service=new BulkDuplicateService($db,$fs,$audit);
    $worker=new BulkDuplicateWorker($db,$jobs,$audit,$fs);
    try {
        $service->plan([1,2,3,4],1,'bulk_quarantine');
        throw new RuntimeException('Allowed removal of every file in a verified group.');
    } catch(DomainException $ex) {
        assertBulk(str_contains($ex->getMessage(),'unselected'),'Expected survivor warning');
    }
    assertBulk(file_get_contents($source.'/C.mp3')===$content,'Survivor was changed while planning');

    $batch=$service->plan([1,2],1,'bulk_quarantine');
    $details=$service->details($batch);
    assertBulk($details && count($details['items'])===2,'Bulk preview missing rows');
    assertBulk((int)$details['items'][0]['survivor_file_entry_id']===3,'Wrong survivor chosen');
    assertBulk(is_file($source.'/A.mp3'),'Preview changed source');
    $jobId=$service->confirm($batch,1);
    $job=$jobs->claimNext($jobId);
    assertBulk($job && $job['kind']==='bulk-quarantine','Quarantine worker was not queued');
    assertBulk((int)$job['progress_total']===2,'Bulk quarantine must retain two approved files.');
    $worker->run($job);
    assertBulk(!file_exists($source.'/A.mp3') && !file_exists($source.'/B.mp3'),
        'Quarantine did not move both selected duplicates');
    assertBulk(file_get_contents($source.'/C.mp3')===$content,'Survivor changed in quarantine');
    assertBulk((int)$db->fetchOne("SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state='quarantined'",[$batch])===2,
        'Quarantine status incomplete');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batch])==='completed',
        'Bulk quarantine batch incomplete');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $restore=$service->restore($batch,1);
    $job=$jobs->claimNext($restore);
    assertBulk($job && $job['kind']==='bulk-restore','Bulk restore was not queued');
    $worker->run($job);
    assertBulk(file_get_contents($source.'/A.mp3')===$content &&
        file_get_contents($source.'/B.mp3')===$content,'Restore failed for one or more files');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$batch])==='restored',
        'Restored bulk batch state incorrect');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");

    // The link operation must retain the original in a private backup and
    // refuse to replace the designated unselected survivor.
    $linkBatch=$service->plan([1,2],1,'bulk_link');
    $linkJob=$service->confirm($linkBatch,1);
    $job=$jobs->claimNext($linkJob);
    assertBulk($job && $job['kind']==='bulk-link','Bulk link job was not queued');
    $worker->run($job);
    assertBulk(is_link($source.'/A.mp3') && is_link($source.'/B.mp3'),'Bulk links not created');
    assertBulk(readlink($source.'/A.mp3')===$source.DIRECTORY_SEPARATOR.'C.mp3','Link survivor differs from approval');
    $linkItems=$service->details($linkBatch)['items'];
    foreach($linkItems as $item)
        assertBulk(file_get_contents($item['target_path'])===$content,'Link backup missing or changed');
    assertBulk(is_file($source.'/C.mp3') && !is_link($source.'/C.mp3'),
        'Link survivor was unexpectedly replaced');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $unlinkJob=$service->restore($linkBatch,1);
    assertBulk($jobs->requestCancel($unlinkJob),'Queued bulk restore could not be cancelled');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$linkBatch])==='partial',
        'Cancelling a queued bulk restore did not leave the original backup restorable');
    assertBulk(is_link($source.'/A.mp3'),'Cancelling queued restore changed the filesystem');
    $unlinkJob=$service->restore($linkBatch,1);
    $job=$jobs->claimNext($unlinkJob);
    assertBulk($job && $job['kind']==='bulk-unlink','Bulk unlink job was not queued');
    $worker->run($job);
    foreach(['A.mp3','B.mp3','C.mp3','D.mp3'] as $name)
        assertBulk(is_file($source.'/'.$name) && !is_link($source.'/'.$name) &&
            file_get_contents($source.'/'.$name)===$content,'Bulk unlink failed: '.$name);
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$linkBatch])==='restored',
        'Bulk link was not restored');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");

    // A stale file is rejected independently while unaffected selected files
    // may proceed safely, producing a partial batch with selective restore.
    $partial=$service->plan([1,2],1,'bulk_quarantine');
    $partialJob=$service->confirm($partial,1);
    file_put_contents($source.'/A.mp3','changed file content');
    $job=$jobs->claimNext($partialJob);
    $worker->run($job);
    assertBulk(is_file($source.'/A.mp3') &&
        file_get_contents($source.'/A.mp3')==='changed file content',
        'A stale file was modified');
    assertBulk(!file_exists($source.'/B.mp3'),'Unaffected batch item did not complete');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$partial])==='partial',
        'Partial failure state missing');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $partialRestore=$service->restore($partial,1);
    $worker->run($jobs->claimNext($partialRestore));
    assertBulk(file_get_contents($source.'/A.mp3')==='changed file content' &&
        file_get_contents($source.'/B.mp3')===$content,
        'Selective restore changed failed source or lost retained original');

    // Reset the deliberately modified stale-file fixture for subsequent tests.
    file_put_contents($source.'/A.mp3',$content);
    touch($source.'/A.mp3',(int)$db->fetchOne('SELECT mtime_epoch FROM file_entries WHERE id=1'));
    clearstatcache(true,$source.'/A.mp3');
    $db->executeStatement("UPDATE jobs SET state='completed',active_profile_id=NULL WHERE kind='rebuild-duplicates'");
    $cancelBatch=$service->plan([2],1,'bulk_quarantine');
    $queued=$service->confirm($cancelBatch,1);
    assertBulk($jobs->requestCancel($queued),'Queued bulk quarantine could not be cancelled');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$cancelBatch])==='cancelled',
        'Cancelled queued batch still appears executable');
    assertBulk(is_file($source.'/B.mp3') && !is_link($source.'/B.mp3'),
        'Queued cancellation touched the original file');
    $stalledBatch=$service->plan([2],1,'bulk_quarantine');
    $stalledJob=$service->confirm($stalledBatch,1);
    $claimed=$jobs->claimNext($stalledJob);
    assertBulk($claimed!==null,'Unable to claim stalled-job fixture');
    assertBulk(!$jobs->requestCancel($stalledJob),
        'A running filesystem mutation job was incorrectly cancellable');
    $db->executeStatement(
        'UPDATE jobs SET heartbeat_at=? WHERE id=?',
        ['2000-01-01 00:00:00',$stalledJob]
    );
    assertBulk($jobs->recoverStalled(120)>0,'Lost worker was not flagged');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$stalledBatch])==='needs_review',
        'A stalled bulk job was not quarantined for manual review');
    assertBulk(is_file($source.'/B.mp3'),'Stalled-job recovery touched the original file');

    $db->executeStatement('UPDATE duplicate_groups SET rules_version=2 WHERE id=1');
    try {
        $service->plan([2],1,'bulk_quarantine');
        throw new RuntimeException('Allowed CRC-only matches to be bulk-quarantined.');
    } catch(DomainException) {}
    assertBulk((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='BULK_FILE_PROCESSED'")===5,
        'Per-file bulk audit entries missing');

    $db->executeStatement('UPDATE duplicate_groups SET rules_version=1 WHERE id=1');
    $missingBatch=$service->plan([2],1,'bulk_quarantine');
    $missingJob=$service->confirm($missingBatch,1);
    $db->delete('file_entries',['id'=>2]);
    try {
        $worker->run($jobs->claimNext($missingJob));
        throw new RuntimeException('Missing bulk item was silently accepted.');
    } catch(DomainException $ex) {
        assertBulk(str_contains($ex->getMessage(),'item count changed'),
            'Missing bulk entry did not produce a count error.');
    }
    assertBulk(is_file($source.'/B.mp3'),'Missing bulk item changed the original file.');
    assertBulk((string)$db->fetchOne('SELECT state FROM file_action_batches WHERE id=?',[$missingBatch])==='needs_review',
        'Missing bulk entry did not flag batch for review.');
    assertBulk((string)$db->fetchOne('SELECT state FROM jobs WHERE id=?',[$missingJob])==='failed',
        'Missing bulk item incorrectly completed the job.');

    echo "PASS bulk quarantine/link, unselected survivor, SHA-256, backups, restore, partial errors, CRC guard and audit\n";
} finally {
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
