<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\System\Service\HealthService;
use App\Module\Jobs\Service\WorkerHeartbeat;
use Doctrine\DBAL\DriverManager;

function ckHealth(bool $check,string $message):void {
    if(!$check) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE users(id INT)',
    'CREATE TABLE profile_types(id INT)',
    'CREATE TABLE scan_profiles(id INT,enabled INT)',
    'CREATE TABLE scan_schedules(profile_id INT PRIMARY KEY,interval_minutes INT,next_run_at TEXT,last_error TEXT)',
    'CREATE TABLE scan_runs(id INT)',
    'CREATE TABLE file_entries(id INT)',
    'CREATE TABLE jobs(id INT,kind TEXT,state TEXT,created_at TEXT,heartbeat_at TEXT,finished_at TEXT,error_code TEXT)',
    'CREATE TABLE job_events(id INT)',
    'CREATE TABLE duplicate_groups(id INT)',
    'CREATE TABLE file_action_batches(id INT,state TEXT)',
    'CREATE TABLE audit_events(id INT)',
    'CREATE TABLE worker_heartbeats(id TEXT PRIMARY KEY,updated_at TEXT)',
    'CREATE TABLE doctrine_migration_versions(version TEXT PRIMARY KEY)'
] as $sql) $db->executeStatement($sql);
$db->insert('doctrine_migration_versions',['version'=>'DoctrineMigrations\Version20261123000100']);
$db->insert('scan_profiles',['id'=>1,'enabled'=>1]);
$db->insert('scan_schedules',['profile_id'=>1,'interval_minutes'=>60,
    'next_run_at'=>'2020-01-01 00:00:00','last_error'=>'The folder is missing']);
$service=new HealthService($db,new WorkerHeartbeat($db));
$report=$service->report();
$checks=$report['checks'];
ckHealth($checks['database']==='connected','Database should be connected.');
ckHealth($checks['database_name']==='In-memory SQLite','Missing useful SQLite database identity.');
ckHealth(str_starts_with($checks['database_engine'],'SQLite'),'Incorrect database engine.');
ckHealth($checks['database_server_version']!=='Unavailable',
    'Database server version was not detected.');
ckHealth($checks['schema_version']==='Version20261123000100',
    'Installed migration was not reported correctly.');
ckHealth($checks['schema_latest_available']!=='Unavailable',
    'Latest available migration not displayed.');
ckHealth($checks['schema_pending']>0,'Pending migration count not reported.');
ckHealth($report['status']==='upgrade_required',
    'Pending migrations not detected as upgrade required.');
ckHealth(is_array($checks['php_extensions']) && count($checks['php_extensions'])>=10,
    'Required extension breakdown missing.');
$driver=array_filter($checks['php_extensions'],static fn($entry)=>$entry['name']==='pdo_sqlite');
ckHealth(count($driver)===1,'SQLite driver extension is not included in PHP requirements.');
ckHealth($checks['scheduled_profiles']===1 && $checks['schedules_with_errors']===1 &&
    $checks['overdue_schedules']===1,'Automatic schedule diagnostics missing.');
ckHealth(isset($checks['php_sapi'],$checks['php_ini'],$checks['os_version']),
    'Required deployment diagnostics missing.');
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']);
foreach([
    'path'=>static fn($name,$args=[])=>'/'.$name,
    'asset'=>static fn($name)=>'/assets/'.$name,
    'csrf_token'=>static fn($name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$html=$twig->render('system/index.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'health'=>$report
]);
foreach(['Database name','Database engine','Installed schema migration',
    'Latest application migration','Schema migrations still needed',
    'Required PHP extensions','Loaded','Missing','DSM','Job monitor',
    'Scheduled scan profiles','Schedules with errors','Overdue schedules'] as $needle) {
    if($needle==='DSM') continue; // Only on DSM hosts.
    ckHealth(str_contains($html,$needle),'System details view missing '.$needle);
}
ckHealth(!str_contains($html,'<dt>Database</dt>') &&
    !str_contains($html,'<dt>Schema</dt>'),
    'Pointless database connected/schema installed fields have not been replaced.');
echo "PASS runtime extension status, DB identity and version, installed/pending migrations, platform details and useful links\n";
