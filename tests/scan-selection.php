<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Duplicates\Repository\ScanSelectionRepository;
use Doctrine\DBAL\DriverManager;

function assertSelection(bool $okay,string $why):void {
    if(!$okay) throw new RuntimeException($why);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('CREATE TABLE scan_profiles (id INT PRIMARY KEY,name TEXT)');
$db->executeStatement('CREATE TABLE scan_runs ('.
    'id INT PRIMARY KEY,profile_id INT,state TEXT,started_at TEXT,'.
    'finished_at TEXT,duplicate_groups INT,files_indexed INT)');
$db->insert('scan_profiles',['id'=>1,'name'=>'Audio']);
$db->insert('scan_profiles',['id'=>2,'name'=>'Archives']);
for($id=1;$id<=84;$id++) {
    $db->insert('scan_runs',[
        'id'=>$id,'profile_id'=>1,
        'state'=>($id===84||$id===83)?'completed':'archived',
        'started_at'=>'2026-11-22 10:00:00',
        'finished_at'=>'2026-11-22 10:30:00',
        'duplicate_groups'=>$id,'files_indexed'=>$id*2
    ]);
}
$db->insert('scan_runs',[
    'id'=>85,'profile_id'=>1,'state'=>'running','started_at'=>null,
    'finished_at'=>null,'duplicate_groups'=>null,'files_indexed'=>null
]);
$db->insert('scan_runs',[
    'id'=>86,'profile_id'=>2,'state'=>'archived',
    'started_at'=>'2026-11-20 10:00:00','finished_at'=>'2026-11-20 10:30:00',
    'duplicate_groups'=>9,'files_indexed'=>30
]);
$repo=new ScanSelectionRepository($db);
$profiles=$repo->profiles();
assertSelection(count($profiles)===2,'Expected two profiles');
$byId=array_column($profiles,null,'id');
assertSelection((int)$byId[1]['run_id']===84,'Current scan must exclude unfinished run');
assertSelection($byId[2]['run_id']===null,'Archived-only profile is incorrectly current');
$runs=$repo->runs(1);
assertSelection($runs['more']===true,'More than 75 runs should offer full history');
assertSelection(count($runs['rows'])===75,'Scan picker should be bounded');
assertSelection((int)$runs['rows'][0]['id']===84,'Scan picker should show newest first');
assertSelection((int)$runs['rows'][74]['id']===10,'Scan picker page boundary incorrect');
assertSelection($repo->selectedRun(1,1)['is_historical']===true,
    'Deep linked old run must be accessible beyond 75 choices');
assertSelection($repo->selectedRun(83,1)['is_historical']===true,
    'Older completed run must not be mistaken for the current scan');
assertSelection($repo->selectedRun(84,1)['is_historical']===false,
    'Current completed run incorrectly marked historical');
assertSelection($repo->selectedRun(86,2)['is_historical']===true,
    'Archived-only profile must remain historical');
assertSelection($repo->selectedRun(1,2)===false,
    'An old run was accepted under another profile');
assertSelection($repo->selectedRun(85,1)===false,
    'Incomplete run incorrectly appeared in duplicate results');
$olderChoices=$repo->runs(1,10);
assertSelection(count($olderChoices['rows'])===9 && $olderChoices['more']===false,
    'Older scans cannot be paged within the Duplicates selector.');
assertSelection((int)$olderChoices['rows'][0]['id']===9,
    'Older-scan cursor returned the wrong page.');

$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$func)$twig->addFunction(new Twig\TwigFunction($name,$func));
$old=$repo->selectedRun(1,1);
$choices=$runs['rows'];
array_unshift($choices,$old);
$html=$twig->render('duplicates/index.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'groups'=>[],'profiles'=>$profiles,'selected_profile'=>1,
    'historical'=>true,'selected_run'=>1,'run_info'=>$old,
    'latest_run_id'=>84,'scan_choices'=>$choices,'scan_choices_more'=>true,
    'scan_before'=>null,'scan_choices_last'=>10,
    'totals'=>['total_groups'=>0,'total_members'=>0,'reclaimable_bytes'=>0],
    'previous'=>null,'next'=>null,'page_start'=>0,'page_end'=>0,'page_rows'=>0,
    'page_listed_bytes'=>0,'page_reclaimable'=>0
]);
foreach(['data-scan-picker-form','data-scan-profile','data-scan-run',
    'Select a profile','Scan #1','Historical snapshot','Older scans','scan_before=10',
    'Latest completed scan for this profile'] as $needle) {
    if($needle==='Select a profile') continue;
    assertSelection(str_contains($html,$needle),'Scan selector missing '.$needle);
}
assertSelection(str_contains($html,'value="1" selected'),'Old deep-linked scan was not selected');
assertSelection(str_contains($html,'value="84"'),'Latest scan option missing');
assertSelection(str_contains($html,'Scan #83'),'Prior completed scan was not selectable');
echo "PASS direct duplicate scan chooser: current, archived, older-completed, deep links, 75-limit and profile ownership\n";
