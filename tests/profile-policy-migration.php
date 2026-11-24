<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require dirname(__DIR__).'/migrations/Version20261124001000.php';

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Schema\Schema;
use DoctrineMigrations\Version20261124001000;
use Psr\Log\NullLogger;
use App\Module\FileActions\Service\ActionSettings;

function verifyMigration(bool $condition,string $why):void {
    if(!$condition) throw new RuntimeException($why);
}

foreach(['number',null] as $global) {
    $db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
    $db->executeStatement('CREATE TABLE scan_profiles(id INTEGER PRIMARY KEY,name TEXT)');
    $db->executeStatement('CREATE TABLE action_settings(setting_key TEXT PRIMARY KEY,setting_value TEXT,updated_at TEXT)');
    $db->executeStatement("INSERT INTO scan_profiles(id,name) VALUES(1,'Music'),(2,'Movies')");
    if($global!==null)
        $db->insert('action_settings',[
            'setting_key'=>'destination_exists','setting_value'=>$global,
            'updated_at'=>gmdate('Y-m-d H:i:s')
        ]);
    $migration=new Version20261124001000($db,new NullLogger());
    $migration->up(new Schema());
    verifyMigration(count($migration->getSql())===2,'Missing schema migration steps.');
    foreach($migration->getSql() as $step) {
        $db->executeStatement($step->getStatement(),$step->getParameters(),$step->getTypes());
    }
    $settings=new ActionSettings($db);
    $expect=$global??'fail';
    verifyMigration($settings->policy(1)===$expect && $settings->policy(2)===$expect,
        'Migration lost the existing global preference.');
    $db->executeStatement("INSERT INTO scan_profiles(id,name) VALUES(3,'New profile')");
    verifyMigration($settings->policy(3)==='fail','New profiles did not default to Fail.');
    $db->update('scan_profiles',['file_exists_policy'=>'overwrite'],['id'=>1]);
    verifyMigration($settings->policy(1)==='overwrite' && $settings->policy(2)===$expect,
        'Changes to one profile propagated to another profile.');
}
echo "PASS original migration SQL copies legacy preference once, defaults new profiles to Fail and isolates later edits\n";
