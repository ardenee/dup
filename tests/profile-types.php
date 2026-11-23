<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use Doctrine\DBAL\DriverManager;
use App\Module\Profiles\Service\ProfileTypeManager;

function check(bool $ok,string $msg):void {
    if(!$ok) throw new \RuntimeException($msg);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE profile_types (id INTEGER PRIMARY KEY AUTOINCREMENT,name TEXT UNIQUE,extensions TEXT)');
$db->executeStatement('CREATE TABLE scan_profiles (id INTEGER PRIMARY KEY,profile_type_id INT)');
$m=new ProfileTypeManager($db);
check($m->normalize(' .MP3, m4b,mp3,  flac')==='mp3,m4b,flac','Normalization failed');
check($m->normalize('*')==='','Wildcard failed');
check($m->normalize('')==='','Empty list failed');
foreach(['*.mp3','mp3;exe','foo bar','../mp3','mp3,*','*_bad'] as $bad) {
    try {$m->normalize($bad);throw new \RuntimeException('Accepted invalid extension: '.$bad);}
    catch(\DomainException) {}
}
$id=$m->save(null,'Music','mp3, m4b');
check($id>0 && $m->find($id)['extensions']==='mp3,m4b','Create failed');
$m->save($id,'Audio','m4b,ogg,flac');
check($m->find($id)['name']==='Audio','Edit failed');
check(count($m->all())===1,'Listing failed');
$db->insert('scan_profiles',['id'=>1,'profile_type_id'=>$id]);
check((int)$m->all()[0]['used_by']===1,'Usage count failed');
try{$m->remove($id);throw new \RuntimeException('Deleted assigned preset');}
catch(\DomainException) {}
check((bool)$m->find($id),'Deletion constraint failed');
$db->delete('scan_profiles',['id'=>1]);
$m->remove($id);
check(!$m->find($id),'Unused preset removal failed');
echo "PASS preset creation, extension normalization, editing, assigned-profile guard and deletion\n";
