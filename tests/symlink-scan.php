<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Scans\Service\ScanEngine;
use App\Module\Duplicates\Service\DuplicateRebuilder;
use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\DriverManager;

function ensureLink(bool $yes,string $message):void {
    if(!$yes) throw new RuntimeException($message);
}
$base=realpath(sys_get_temp_dir()) ?: sys_get_temp_dir();
$root=$base.DIRECTORY_SEPARATOR.'dup-symlink-scan-'.bin2hex(random_bytes(6));
$clean=function(string $path) use (&$clean):void {
    if(is_link($path) || !is_dir($path)) {
        if(is_link($path) || file_exists($path)) @unlink($path);
        return;
    }
    foreach(new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry)
        $clean($entry->getPathname());
    @rmdir($path);
};
mkdir($root,0700,true);
try {
    mkdir($root.DIRECTORY_SEPARATOR.'sub',0700);
    $one=$root.DIRECTORY_SEPARATOR.'song-a.bin';
    $two=$root.DIRECTORY_SEPARATOR.'song-b.bin';
    file_put_contents($one,str_repeat('test-file-content-abc',19));
    file_put_contents($two,file_get_contents($one));
    file_put_contents($root.DIRECTORY_SEPARATOR.'sub'.DIRECTORY_SEPARATOR.'small.bin','abc');
    $link=$root.DIRECTORY_SEPARATOR.'song-link.bin';
    if(!@symlink($one,$link)) {
        echo "SKIP symbolic-link filesystem test: operating system account cannot create symlinks\n";
        return;
    }
    $secondLink=$root.DIRECTORY_SEPARATOR.'second-link.bin';
    $dangling=$root.DIRECTORY_SEPARATOR.'dangling.bin';
    $directoryLink=$root.DIRECTORY_SEPARATOR.'sub-alias';
    ensureLink(@symlink($one,$secondLink),'Cannot create duplicate-link fixture');
    $hasBroken=@symlink($root.DIRECTORY_SEPARATOR.'missing.bin',$dangling);
    $hasDirectoryAlias=@symlink($root.DIRECTORY_SEPARATOR.'sub',$directoryLink);
    if(PHP_OS_FAMILY!=='Windows') {
        ensureLink($hasBroken,'Cannot create broken-link fixture on Linux');
        ensureLink($hasDirectoryAlias,'Cannot create directory-link fixture on Linux');
    }
    $expectedLinks=2+(int)$hasBroken+(int)$hasDirectoryAlias;

    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    foreach([
        'CREATE TABLE directories (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INT,parent_id INT,path TEXT,path_hash TEXT,basename TEXT,is_ignored INT)',
        'CREATE TABLE file_entries (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INT,directory_id INT,filename TEXT,path_hash TEXT,size_bytes INT,mtime_epoch INT,is_symlink INT DEFAULT 0,link_target TEXT,hash_method TEXT,hash_value TEXT,error_code TEXT)',
        'CREATE TABLE duplicate_groups (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INT,group_key TEXT,size_bytes INT,file_count INT,reclaimable_bytes INT,rules_version INT)',
        'CREATE TABLE duplicate_group_members (id INTEGER PRIMARY KEY AUTOINCREMENT,group_id INT,file_entry_id INT)',
        'CREATE TABLE jobs (id INT PRIMARY KEY,state TEXT,worker_token TEXT,phase TEXT,progress_current INT,progress_total INT,heartbeat_at TEXT)',
        'CREATE TABLE job_events (id INTEGER PRIMARY KEY AUTOINCREMENT,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
    ] as $sql) $db->executeStatement($sql);
    $db->insert('jobs',['id'=>1,'state'=>'running','worker_token'=>'test-lease','phase'=>'enumerating']);
    $browser=new DirectoryBrowser();
    $jobs=new JobStore($db,$browser);
    $rebuild=new DuplicateRebuilder($db);
    $engine=new ScanEngine($db,$jobs,$rebuild,$browser);
    // The complete scan-run entrypoint normally initializes these counters.
    (new ReflectionProperty($engine,'stats'))->setValue($engine,[
        'files_seen'=>0,'files_indexed'=>0,'bytes_indexed'=>0,
        'ignored_indexed'=>0,'symlinks_indexed'=>0,'symlinks_skipped'=>0
    ]);
    $method=new ReflectionMethod($engine,'enumerate');
    $method->invoke($engine,1,1,'test-lease',$root,[],[],0);

    $links=$db->fetchAllAssociative('SELECT filename,link_target,is_symlink,hash_value FROM file_entries WHERE is_symlink=1 ORDER BY filename');
    ensureLink(count($links)===$expectedLinks,'Expected file, dangling and directory link metadata');
    ensureLink((int)$db->fetchOne('SELECT COUNT(*) FROM file_entries WHERE is_symlink=0')===3,
        'A directory symlink was traversed or regular files were missed');
    foreach($links as $row) {
        ensureLink((int)$row['is_symlink']===1 &&
            $row['link_target']!==null && $row['hash_value']===null,
            'Symlink metadata missing or incorrectly hashed');
    }
    ensureLink((string)$db->fetchOne(
        'SELECT link_target FROM file_entries WHERE filename=?',['song-link.bin']
    )===$one,'Absolute symlink destination not preserved');
    if($hasBroken) ensureLink((string)$db->fetchOne(
        'SELECT link_target FROM file_entries WHERE filename=?',['dangling.bin']
    )===$root.DIRECTORY_SEPARATOR.'missing.bin','Dangling link target lost');
    $stats=(new ReflectionProperty($engine,'stats'))->getValue($engine);
    ensureLink((int)$stats['symlinks_indexed']===$expectedLinks &&
        (int)$stats['files_indexed']===3+$expectedLinks,
        'Symlink statistics were not reported');

    $checkpoint=static fn(int $done)=>null;
    ensureLink($rebuild->rebuild(1,'size',$checkpoint)===1,
        'Size-only grouping incorrectly includes symlinks');
    ensureLink((int)$db->fetchOne('SELECT file_count FROM duplicate_groups')===2,
        'Size-only group included symlink paths');

    $hash=new ReflectionMethod($engine,'hashCandidateFiles');
    $hash->invoke($engine,1,1,'test-lease','sha256');
    ensureLink((int)$db->fetchOne(
        "SELECT COUNT(*) FROM file_entries WHERE is_symlink=1 AND hash_value IS NOT NULL"
    )===0,'Symlink contents were hashed');
    ensureLink((int)$db->fetchOne(
        "SELECT COUNT(*) FROM file_entries WHERE hash_method='sha256'"
    )===2,'Expected two independently hashed normal files');
    ensureLink($rebuild->rebuild(1,'sha256',$checkpoint)===1 &&
        (int)$db->fetchOne('SELECT file_count FROM duplicate_groups')===2,
        'Verified grouping includes symlink metadata');
    echo "PASS file/broken/directory symlinks indexed without traversal, raw targets, SHA-256 excluded, size grouping excluded, stats preserved\n";
} finally {
    $clean($root);
}
