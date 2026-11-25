<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;

function assertProfileUx(bool $okay,string $description):void
{
    if(!$okay)throw new RuntimeException($description);
}
$root=dirname(__DIR__);
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader($root.'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.
        ($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'csrf-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$base=[
    'type_name'=>'All File Types','hash_mode'=>'crc32_3part',
    'file_exists_policy'=>'fail','enabled'=>true,
    'schedule_interval'=>0,'schedule_next'=>null,'schedule_error'=>null,
    'completed_scans'=>1,'retained_runs'=>1,
    'finished_at'=>'2026-11-22 17:24:52','last_saved_at'=>'2026-11-22 17:24:52',
    'active_state'=>null,'active_phase'=>null,
    'active_job_id'=>null,'active_kind'=>null,'active_heartbeat'=>null,
    'job_overdue'=>false
];
$profiles=[
    $base+['id'=>1,'name'=>'Audio Books','source_root'=>'/volume1/data/AudioBooks',
        'duplicate_groups'=>33,'files_indexed'=>3565],
    array_replace($base,['id'=>2,'name'=>'TV Shows',
        'source_root'=>'/volume1/data/TV Shows',
        'duplicate_groups'=>38,'files_indexed'=>11639]),
    array_replace($base,['id'=>3,'name'=>'Movies',
        'source_root'=>'/volume1/data/Movies',
        'duplicate_groups'=>13,'files_indexed'=>1020,
        'active_state'=>'running','active_phase'=>'hashing_duplicates',
        'active_job_id'=>10,'active_kind'=>'duplicate-verify',
        'active_heartbeat'=>'2026-11-22 19:11:18','job_overdue'=>true])
];
$render=$twig->render('profiles/index.html.twig',[
    'app'=>['user'=>null,'environment'=>'prod','flashes'=>[],
        'request'=>Request::create('/profiles')],
    'profiles'=>$profiles,'types'=>[
        ['name'=>'All File Types','extensions'=>''],
        ['name'=>'Movies','extensions'=>'mkv;mp4']],
    'busy_count'=>1,'unresponsive_count'=>1
]);
$doc=new DOMDocument();
@$doc->loadHTML($render);
$xpath=new DOMXPath($doc);
$rows=$xpath->query('//table[contains(@class,"profiles-overview-table")]/tbody/tr');
assertProfileUx($rows->length===3,'Expected 3 profile rows.');
foreach($rows as $row) {
    $cells=$xpath->query('./td',$row);
    assertProfileUx($cells->length===6,'Each profile must have exactly 6 concise columns.');
    $toolbar=$xpath->query('.//*[contains(@class,"profile-action-toolbar")]',$row);
    assertProfileUx($toolbar->length===1,'Each profile must have a single action toolbar.');
    $icons=$xpath->query('.//*[contains(@class,"action-icon-button")]',$toolbar->item(0));
    assertProfileUx($icons->length===6,'Each profile should have exactly six visible action icons.');
}
assertProfileUx(
    $xpath->query('//table[contains(@class,"profiles-overview-table")]/colgroup/col')->length===6,
    'Stable width for each of 6 table columns is not defined.');
assertProfileUx(str_contains($render,'Unresponsive') &&
    str_contains($render,'Diagnose stuck job #10') &&
    str_contains($render,'/app_job_detail?id=10') &&
    str_contains($render,'/app_worker_log?job=10') &&
    str_contains($render,'/app_job_resolve?id=10') &&
    str_contains($render,'csrf-resolve_stale_job_10') &&
    str_contains($render,'does not terminate an OS process'),
    'A stale verification must be clearly labelled and offer direct diagnosis.');
assertProfileUx(str_contains($render,'3 total') &&
    str_contains($render,'1 active or queued') &&
    str_contains($render,'33 groups') &&
    str_contains($render,'3,565 files indexed') &&
    str_contains($render,'CRC32 3 Part (fast)') &&
    str_contains($render,'File exists:'),
    'The compact overview lost important profile settings or scan results.');
assertProfileUx(str_contains($render,'File-type presets') &&
    str_contains($render,'Manage presets (2)') &&
    str_contains($render,'profile-schedule-label') &&
    str_contains($render,'app_profile_edit'),
    'Preset management or schedule saving is missing.');
assertProfileUx(str_contains($render,'disabled') &&
    str_contains($render,'Job Monitor'),
    'An active/stale scan must prevent a second scan and link to recovery.');
$css=file_get_contents($root.'/public/assets/app.css');
assertProfileUx(str_contains($css,'.profile-action-toolbar{display:flex') &&
    str_contains($css,'flex-wrap:nowrap') &&
    str_contains($css,'.profiles-overview-table{min-width:1110px;table-layout:fixed}'),
    'Action icons might wrap vertically inside a cramped table cell.');
$controller=file_get_contents($root.'/src/Module/Profiles/Controller/ProfilesController.php');
assertProfileUx(str_contains($controller,'job_overdue') &&
    str_contains($controller,'time()-600'),
    'Profiles page has no overdue job state detection.');
if(in_array('--preview',$argv,true)) {
    $previewDir=$root.'/dist';
    if(!is_dir($previewDir))mkdir($previewDir,0755,true);
    $full=str_replace(
        '<link rel="stylesheet" href="/assets/app.css?v=0.7.15-job-debug">',
        '<style>'.$css.'</style>',$render
    );
    // Use same stylesheet for visual preview regardless of release cache version.
    $full=preg_replace(
        '~<link rel="stylesheet" href="[^"]*app\.css[^"]*">~',
        '<style>'.$css.'</style>',$full
    );
    file_put_contents($previewDir.'/profiles-preview.html',$full);
    echo "PREVIEW ".$previewDir."/profiles-preview.html\n";
}
echo "PASS compact six-column profiles, six horizontal action icons, schedule, results, stale-worker diagnosis and optional visual preview\n";
