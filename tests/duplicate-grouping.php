<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use App\Module\Duplicates\Service\DuplicateRebuilder;

function ensure(bool $value,string $message): void
{
    if(!$value) throw new \RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE directories (id INTEGER PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)');
$db->executeStatement('CREATE TABLE file_entries (id INTEGER PRIMARY KEY,run_id INT,directory_id INT,size_bytes BIGINT,hash_value TEXT,error_code TEXT)');
$db->executeStatement('CREATE TABLE duplicate_groups (id INTEGER PRIMARY KEY AUTOINCREMENT,run_id INT,group_key TEXT,size_bytes BIGINT,file_count INT,reclaimable_bytes BIGINT,rules_version INT)');
$db->executeStatement('CREATE TABLE duplicate_group_members (id INTEGER PRIMARY KEY AUTOINCREMENT,group_id INT,file_entry_id INT)');
$db->executeStatement('INSERT INTO directories VALUES (1,1,\'/media\',0),(2,1,\'/media/@eaDir\',1)');
foreach([
    [1,1,1,1000000,'ABCDEF12'],
    [2,1,1,1000000,'ABCDEF12'],
    [3,1,1,1000000,'00000ABC'],
    [4,1,2,1000000,'ABCDEF12'],
    [5,1,1,2000000,'ABCDEF12'],
] as [$id,$run,$dir,$size,$hash]) {
    $db->insert('file_entries',[
        'id'=>$id,'run_id'=>$run,'directory_id'=>$dir,
        'size_bytes'=>$size,'hash_value'=>$hash,'error_code'=>null
    ]);
}
$rebuild=new DuplicateRebuilder($db);
$checkpoint=static fn(int $done)=>null;
foreach([
    'crc32_3part'=>[2,2],
    'crc32_full'=>[3,2],
    'size'=>[0,3],
    'sha256'=>[1,2]
] as $mode=>[$confidence,$expectedMembers]) {
    $groups=$rebuild->rebuild(1,$mode,$checkpoint);
    ensure($groups===1,$mode.' produced unexpected group count');
    $row=$db->fetchAssociative('SELECT * FROM duplicate_groups WHERE run_id=1');
    ensure((int)$row['rules_version']===$confidence,$mode.' confidence mismatch');
    ensure((int)$row['file_count']===$expectedMembers,$mode.' ignored/size grouping mismatch');
    ensure((int)$db->fetchOne('SELECT COUNT(*) FROM duplicate_group_members WHERE group_id=?',[$row['id']])===$expectedMembers,
        $mode.' has the wrong number of group members');
    ensure((int)$row['reclaimable_bytes']===($expectedMembers-1)*1000000,
        $mode.' recoverable byte estimate is wrong');
    ensure(str_starts_with((string)$row['group_key'],$mode.':'),$mode.' group key has the wrong prefix');
}
echo "PASS size, three-part CRC32, full CRC32, SHA-256 grouping; ignored folders excluded; candidate confidence persisted\n";
