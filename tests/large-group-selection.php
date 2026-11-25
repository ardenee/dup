<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Duplicates\Controller\DuplicateMembersController;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\DependencyInjection\Container;

function selectAssert(bool $yes,string $message):void {
    if(!$yes) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,source_root TEXT)',
    'CREATE TABLE scan_runs(id INTEGER PRIMARY KEY,profile_id INT,state TEXT)',
    'CREATE TABLE duplicate_groups(id INTEGER PRIMARY KEY,run_id INT,file_count INT)',
    'CREATE TABLE duplicate_group_members(id INTEGER PRIMARY KEY,group_id INT,file_entry_id INT)',
    'CREATE TABLE directories(id INTEGER PRIMARY KEY,path TEXT,is_ignored INT)',
    'CREATE TABLE file_entries(id INTEGER PRIMARY KEY,directory_id INT,filename TEXT,error_code TEXT)'
] as $sql)$db->executeStatement($sql);
$db->insert('scan_profiles',['id'=>1,'source_root'=>'/music']);
$db->insert('scan_runs',['id'=>3,'profile_id'=>1,'state'=>'completed']);
$db->insert('directories',['id'=>1,'path'=>'/music/album','is_ignored'=>0]);
$db->insert('duplicate_groups',['id'=>20,'run_id'=>3,'file_count'=>162]);
for($i=1;$i<=162;$i++){
    $db->insert('file_entries',['id'=>$i,'directory_id'=>1,
        'filename'=>sprintf('Track-%03d.mp3',$i),'error_code'=>null]);
    $db->insert('duplicate_group_members',['id'=>$i,'group_id'=>20,'file_entry_id'=>$i]);
}
$checker=new class {
    public function isGranted(mixed $attribute,mixed $subject=null):bool {return true;}
};
$container=new Container();
$container->set('security.authorization_checker',$checker);
$controller=new DuplicateMembersController();
$controller->setContainer($container);
$response=$controller->selection(20,$db);
$body=json_decode($response->getContent(),true,512,JSON_THROW_ON_ERROR);
selectAssert($response->getStatusCode()===200 && count($body['files'])===161,
    'Select matches unexpectedly paginated or truncated the 161-file group.');
selectAssert($body['files'][0]['id']==='2' &&
    $body['files'][160]['id']==='162' &&
    $body['files'][160]['path']==='/music/album/Track-162.mp3',
    'Group selection changed ordering, skipped the final member, or included survivor.');
selectAssert(str_contains((string)$response->headers->get('Cache-Control'),'no-store'),
    'Private selections must not be cached.');
$db->executeStatement("UPDATE file_entries SET error_code='CHANGED' WHERE id=145");
$response=$controller->selection(20,$db);
selectAssert($response->getStatusCode()===409,
    'Changed group returned a silently incomplete selection instead of a conflict.');
$db->executeStatement("UPDATE file_entries SET error_code=NULL WHERE id=145");
$db->insert('scan_runs',['id'=>4,'profile_id'=>1,'state'=>'completed']);
try {
    $controller->selection(20,$db);
    throw new RuntimeException('Historical group allowed mutation selection.');
} catch(Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {}

$container->set('security.authorization_checker',new class {
    public function isGranted(mixed $attribute,mixed $subject=null):bool {return false;}
});
try {
    $controller->selection(20,$db);
    throw new RuntimeException('A viewer received selectable file IDs.');
} catch(Symfony\Component\Security\Core\Exception\AccessDeniedException) {}
echo "PASS current verified 161-member selection, survivor, changed-file guard, historical and role protection\n";
