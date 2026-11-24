<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Reports\Repository\ReportRepository;
use Doctrine\DBAL\DriverManager;
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE file_entries (id INT PRIMARY KEY,run_id INT,directory_id INT,filename TEXT,size_bytes INT,mtime_epoch INT,is_symlink INT,link_target TEXT,hash_method TEXT,hash_value TEXT,error_code TEXT)');
$db->executeStatement('CREATE TABLE directories (id INT PRIMARY KEY,path TEXT,is_ignored INT)');
$db->executeStatement("INSERT INTO directories VALUES (1,'/music',0)");
$db->executeStatement("INSERT INTO file_entries VALUES (1,4,1,'original.m4b',300,1000,0,NULL,'sha256','ABC',NULL)");
$db->executeStatement("INSERT INTO file_entries VALUES (2,4,1,'alias.m4b',19,1001,1,'../music/original.m4b',NULL,NULL,NULL)");
$reports=new ReportRepository($db);
[$headers,$cursor]=$reports->csv(4,'files');
$rows=$cursor->fetchAllAssociative();
if(count($rows)!==2 || !in_array('is_symlink',$headers,true) ||
   !in_array('link_target',$headers,true) || count($headers)!==count($rows[0]) ||
   count($headers)!==count($rows[1]))
    throw new RuntimeException('File export lacks link metadata or row alignment.');
$fields=array_combine($headers,array_values($rows[1]));
if((int)$fields['is_symlink']!==1 ||
   $fields['link_target']!=='../music/original.m4b' ||
   $fields['hash_value']!==null)
    throw new RuntimeException('Exported symlink metadata is incorrect.');
echo "PASS scan CSV exports symlink flag and target with aligned columns and no hash\n";
