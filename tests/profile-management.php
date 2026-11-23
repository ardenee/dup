<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use App\Module\Profiles\Repository\ProfileRepository;

function assertProfile(bool $condition,string $description): void
{
    if(!$condition) throw new RuntimeException($description);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE profile_types (id INTEGER PRIMARY KEY,name TEXT,extensions TEXT)');
$db->executeStatement('CREATE TABLE scan_profiles (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT,source_root TEXT,move_root TEXT,profile_type_id INT,minimum_bytes INT,hash_mode TEXT,enabled INT,created_at TEXT,updated_at TEXT)');
$db->executeStatement('CREATE TABLE scan_runs (id INTEGER PRIMARY KEY,profile_id INT,state TEXT,files_indexed INT,duplicate_groups INT,finished_at TEXT)');
$db->executeStatement('CREATE TABLE jobs (id INTEGER PRIMARY KEY,profile_id INT,active_profile_id INT,state TEXT,phase TEXT)');
$db->executeStatement("INSERT INTO profile_types VALUES (1,'All files','')");
$repo=new ProfileRepository($db);
$repo->create('Music','/files','/archive',1,0,'crc32_3part');
$id=(int)$db->lastInsertId();
assertProfile($repo->find($id)!==false,'Created profile missing');
assertProfile($repo->find($id)['hash_mode']==='crc32_3part','Fast hashing was not saved');
$db->executeStatement("INSERT INTO scan_runs VALUES (1,?,'completed',12,3,'2026-11-01 00:00:00')",[$id]);
$repo->update($id,'Music edited','/files','/newarchive',1,1048576,'crc32_full');
$p=$repo->find($id);
assertProfile($p['name']==='Music edited' && $p['move_root']==='/newarchive' &&
    $p['hash_mode']==='crc32_full' && (int)$p['minimum_bytes']===1048576,
    'Profile changes did not persist');
assertProfile((int)$db->fetchOne('SELECT files_indexed FROM scan_runs WHERE id=1')===12,
    'Completed scan history was changed');
$repo->setEnabled($id,false);
assertProfile((int)$repo->find($id)['enabled']===0,'Disable failed');
$repo->setEnabled($id,true);
assertProfile((int)$repo->find($id)['enabled']===1,'Enable failed');
$db->executeStatement("INSERT INTO jobs VALUES (1,?,?, 'running','hashing')",[$id,$id]);
foreach(['update','setEnabled'] as $method) {
    try {
        if($method==='update') $repo->update($id,'Unsafe edit','/files','/newarchive',1,0,'size');
        else $repo->setEnabled($id,false);
        throw new RuntimeException('Active-job change should have been rejected: '.$method);
    } catch(DomainException $ex) {
        assertProfile(str_contains($ex->getMessage(),'active'),'Wrong active-job rejection');
    }
}
assertProfile($repo->find($id)['hash_mode']==='crc32_full','Failed update altered stored profile');
echo "PASS profile creation, editing, disabling/enabling, immutable scan history, and active-job guards\n";
