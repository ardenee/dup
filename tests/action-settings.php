<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\FileActions\Service\ActionSettings;
use App\Module\Renaming\Service\RenameRules;
use Doctrine\DBAL\DriverManager;

function checkPolicy(bool $pass,string $why):void {
    if(!$pass) throw new RuntimeException($why);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement(
    'CREATE TABLE action_settings(setting_key VARCHAR(40) PRIMARY KEY,setting_value VARCHAR(40),updated_at TEXT)'
);
$db->executeStatement(
    'CREATE TABLE audit_events(id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INTEGER,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT)'
);
$service=new ActionSettings($db);
checkPolicy($service->policy()===ActionSettings::FAIL,'Unsafe default file-exists policy');
$service->save(ActionSettings::NUMBER,8);
checkPolicy($service->policy()===ActionSettings::NUMBER,'Append Number policy not persisted');
$service->save(ActionSettings::FAIL,8);
checkPolicy($service->policy()===ActionSettings::FAIL,'Fail policy not persisted');
$service->save(ActionSettings::OVERWRITE,8);
checkPolicy($service->policy()===ActionSettings::OVERWRITE,'Overwrite policy not persisted');
checkPolicy((int)$db->fetchOne('SELECT COUNT(*) FROM action_settings')===1,'Duplicate settings keys created');
checkPolicy((int)$db->fetchOne('SELECT COUNT(*) FROM audit_events')===3,'Policy change audit missing');
foreach(['delete','skip',''] as $unsafe) {
    try {$service->save($unsafe,8);throw new RuntimeException('Unsafe policy accepted: '.$unsafe);}
    catch(DomainException) {}
}
checkPolicy($service->policy()===ActionSettings::OVERWRITE,'Invalid policy mutated defaults');
checkPolicy(RenameRules::isNumberedVariant('music.m4b','music (3).m4b'),'Numbered variant rejected');
checkPolicy(!RenameRules::isNumberedVariant('music.m4b','music2.m4b'),'Unexpected filename accepted');
checkPolicy(!RenameRules::isNumberedVariant('music.m4b','music (0).m4b'),'Invalid suffix accepted');

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
$twig->addFunction(new Twig\TwigFunction('path',static fn($name,$args=[])=>'/'.$name));
$twig->addFunction(new Twig\TwigFunction('csrf_token',static fn($name)=>'csrf-'.$name));
$twig->addFunction(new Twig\TwigFunction('asset',static fn($name)=>'/assets/'.$name));
$twig->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>[]));
$twig->addFunction(new Twig\TwigFunction('is_granted',static fn()=>true));
$html=$twig->render('actions/settings.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'policy'=>ActionSettings::FAIL
]);
foreach(['Fail','Append Number','Overwrite','value="overwrite"','csrf-action_settings'] as $needle)
    checkPolicy(str_contains($html,$needle),'Missing settings UI: '.$needle);
echo "PASS saved Fail/Append Number/Overwrite settings, audit, numbered variant validation and UI\n";
