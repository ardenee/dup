<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Doctrine\DBAL\DriverManager;
use App\Module\Search\Repository\SearchRepository;

function checkSearch(bool $condition,string $message):void
{
    if(!$condition) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT,source_root TEXT)');
$db->executeStatement('CREATE TABLE scan_runs (id INT PRIMARY KEY,profile_id INT,state TEXT)');
$db->executeStatement('CREATE TABLE directories (id INT PRIMARY KEY,run_id INT,path TEXT,is_ignored INT)');
$db->executeStatement('CREATE TABLE file_entries (id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,hash_method TEXT,hash_value TEXT,error_code TEXT)');
$db->executeStatement('CREATE TABLE duplicate_group_members (id INT PRIMARY KEY,group_id INT,file_entry_id INT)');
$db->executeStatement('CREATE TABLE duplicate_groups (id INT PRIMARY KEY,run_id INT,rules_version INT)');
$db->executeStatement("INSERT INTO scan_profiles VALUES (1,'Music','/media'),(2,'Docs','/docs')");
$db->executeStatement("INSERT INTO scan_runs VALUES (1,1,'completed'),(2,1,'completed'),(3,2,'completed')");
$db->executeStatement("INSERT INTO directories VALUES (1,1,'/old',0),(2,2,'/media',0),(3,3,'/docs',0),(4,2,'/media/@eaDir',1)");
$db->executeStatement("INSERT INTO file_entries VALUES (1,1,1,'old_track.mp3',99,0,'sha256','abc',NULL)");
for($i=2;$i<=70;$i++){
    $db->insert('file_entries',[
        'id'=>$i,'run_id'=>2,'directory_id'=>($i===70?4:2),
        'filename'=>($i===4?'Track_100%.mp3':('track'.sprintf('%03d',$i).'.mp3')),
        'size_bytes'=>1000,'mtime_epoch'=>1700000000,
        'hash_method'=>'crc32_3part','hash_value'=>'ABCD1234',
        'error_code'=>$i===5?'QUARANTINED':null
    ]);
}
$db->executeStatement("INSERT INTO file_entries VALUES (71,3,3,'document.pdf',5000,1700000001,'sha256','cafe',NULL)");
$db->executeStatement('INSERT INTO duplicate_groups VALUES (8,2,2)');
$db->executeStatement('INSERT INTO duplicate_group_members VALUES (1,8,2),(2,8,3)');
$search=new SearchRepository($db);
$all=$search->find('',null,'all',false);
checkSearch($all['total']===70,'Old scan should not appear (69 current music + 1 doc)');
checkSearch(count($all['rows'])===50,'Result page should be capped at fifty');
checkSearch($all['next']!==null && $all['previous']===null,'First page cursors');
$next=$search->find('',null,'all',false,$all['next']);
checkSearch(count($next['rows'])===20 && $next['previous']!==null,'Second page cursor');
$back=$search->find('',null,'all',false,null,$next['previous']);
checkSearch(count($back['rows'])===50 && (int)$back['rows'][0]['id']===71,
    'Backwards cursor did not restore first page');
$dup=$search->find('',1,'duplicates',false);
checkSearch($dup['total']===2 && (int)$dup['rows'][0]['group_id']===8,
    'Duplicate scope not preserved');
$moved=$search->find('',null,'moved',false);
checkSearch($moved['total']===1 && (int)$moved['rows'][0]['id']===5,
    'Quarantine search did not filter correctly');
$caseInsensitive=$search->find('TRACK',1,'all',false);
checkSearch($caseInsensitive['total']===69,'Case-insensitive search mismatch');
$caseSensitive=$search->find('TRACK',1,'all',true);
checkSearch($caseSensitive['total']===0,'Case-sensitive search matched lowercase');
$special=$search->find('_100%',1,'all',false);
checkSearch($special['total']===1 && (int)$special['rows'][0]['id']===4,
    'Search wildcard characters were treated as patterns');
$ignored=$search->find('track070',1,'all',false);
checkSearch($ignored['total']===1 && (int)$ignored['rows'][0]['is_ignored']===1,
    'Ignored-folder data disappeared from index search');
echo "PASS latest snapshots, 50-item keyset paging, search profiles, duplicates, quarantine, case handling and literal wildcards\n";
