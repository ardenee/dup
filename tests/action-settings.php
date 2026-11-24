<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\FileActions\Service\ActionSettings;
use App\Module\Renaming\Service\RenameRules;
use App\Module\Profiles\Repository\ProfileRepository;
use Doctrine\DBAL\DriverManager;

function checkPolicy(bool $pass,string $why):void {
    if(!$pass)throw new RuntimeException($why);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,name TEXT,file_exists_policy TEXT NOT NULL DEFAULT "fail")');
$db->executeStatement("INSERT INTO scan_profiles(id,name) VALUES(1,'Music'),(2,'Movies')");
$policy=new ActionSettings($db);
checkPolicy($policy->policy(1)===ActionSettings::FAIL,'Profile 1 must default to Fail');
checkPolicy($policy->policy(2)===ActionSettings::FAIL,'Profile 2 must default to Fail');
$db->update('scan_profiles',['file_exists_policy'=>ActionSettings::OVERWRITE],['id'=>1]);
checkPolicy($policy->policy(1)===ActionSettings::OVERWRITE,'Overwrite not read from the selected profile');
checkPolicy($policy->policy(2)===ActionSettings::FAIL,'Another profile inherited the wrong policy');
$db->update('scan_profiles',['file_exists_policy'=>ActionSettings::NUMBER],['id'=>2]);
checkPolicy($policy->policy(2)===ActionSettings::NUMBER,'Append Number not saved for other profile');
foreach(['skip','delete',''] as $unsafe){
    try { ActionSettings::validate($unsafe);throw new RuntimeException('Accepted unsupported policy: '.$unsafe); }
    catch(DomainException) {}
}
try {$policy->policy(3);throw new RuntimeException('Missing profile unexpectedly resolved');}
catch(DomainException) {}
checkPolicy(RenameRules::isNumberedVariant('music.m4b','music (3).m4b'),'Numbering validation rejected');
checkPolicy(!RenameRules::isNumberedVariant('music.m4b','music2.m4b'),'Wrong suffix allowed');

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
foreach([
    'path'=>static fn($name,$args=[])=>'/'.$name,
    'csrf_token'=>static fn($name)=>'csrf-'.$name,
    'asset'=>static fn($name)=>'/assets/'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$html=$twig->render('profiles/new.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'profile'=>null,'errors'=>[],'types'=>[]
]);
foreach(['name="file_exists_policy"','Fail','Append Number','Overwrite','applies to this scan profile'] as $needle)
    checkPolicy(stripos($html,$needle)!==false,'Profile settings UI missing '.$needle);
echo "PASS independent profile File Exists settings, Fail default, options and validation\n";
