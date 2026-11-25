<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use App\Module\Profiles\Service\ScanScheduleService;
use Symfony\Component\Routing\Attribute\Route;

function checkPolish(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException($why);
}
$root=dirname(__DIR__);
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader($root.'/templates'),
    ['autoescape'=>'html','strict_variables'=>false]
);
foreach([
    'path'=>static fn(string $route,array $args=[])=>'/'.$route.
        ($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $file)=>'/assets/'.$file,
    'csrf_token'=>static fn(string $token)=>'csrf-'.$token,
    'is_granted'=>static fn()=>true,
    'dup_navigation'=>static fn()=>[]
] as $name=>$callback) $twig->addFunction(new Twig\TwigFunction($name,$callback));
$app=['app'=>['user'=>null,'environment'=>'test','flashes'=>[],
    'request'=>Request::create('/search')]];
$search=$twig->render('search/index.html.twig',$app+[
    'profiles'=>[['id'=>5,'name'=>'TV Shows']],
    'term'=>'1080p','mode'=>'all','profile'=>null,
    'submitted'=>true,'case_sensitive'=>false,
    'results'=>['rows'=>[[
        'id'=>49,'filename'=>'Episode.1080p.mkv',
        'directory'=>'/volume1/TV Shows','profile_id'=>5,'profile_name'=>'TV Shows',
        'size_bytes'=>1073741824,'mtime_epoch'=>1790000000,
        'is_symlink'=>0,'link_target'=>null,'hash_method'=>'sha256',
        'is_ignored'=>0,'error_code'=>null,'group_id'=>25
    ]],'total'=>1,'previous'=>null,'next'=>null],
    'sort'=>'modified','dir'=>'asc','filters'=>[],'file_state'=>'',
    'action_kind'=>''
]);
foreach(['Modified (UTC)','Unix timestamp: 1790000000',
    '1.00 GiB','1,073,741,824 bytes',
    'search-directory','search-filename','data-highlight-text',
    'data-match-terms="1080p"','/app_profile_view?id=5',
    '/app_duplicate_group?id=25','app-icon-download','app-icon-edit'] as $needle)
    checkPolish(str_contains($search,$needle),'Search result missing '.$needle);
checkPolish(str_contains($search,gmdate('Y-m-d H:i',1790000000).' UTC'),
    'Modified time is not formatted as a human-readable UTC date.');
checkPolish(!str_contains($search,'Modified (Unix time)'),'Search retained Unix label.');
$dashboard=$twig->render('dashboard/index.html.twig',$app+[
    'metrics'=>['profiles'=>3,'completed_scans'=>3,'files'=>16224,
        'duplicate_groups'=>71,'verified_potential_bytes'=>43162679080,
        'active_jobs'=>0],
    'jobs'=>[],'scans'=>[],'firstProfileId'=>null
]);
checkPolish(str_contains($dashboard,'40.20 GiB') &&
    str_contains($dashboard,'43,162,679,080 bytes'),
    'Dashboard recovery figure does not use human units and raw-byte tooltip.');
$reports=$twig->render('reports/index.html.twig',$app+[
    'profiles'=>[],'selected_profile'=>null,'history_state'=>'',
    'totals'=>['completed_profiles'=>3,'profiles'=>3,'files_indexed'=>16224,
        'bytes_indexed'=>6765186734909,'verified_groups'=>71,
        'verified_bytes'=>43162679080,'candidate_groups'=>0,'candidate_bytes'=>0],
    'snapshots'=>[
        ['profile_id'=>5,'profile_name'=>'TV Shows','run_id'=>40,
            'finished_at'=>'2026-11-25 01:00:00',
            'files_indexed'=>16224,'duplicate_groups'=>71,
            'verified_bytes'=>43162679080,'verified_groups'=>71,
            'candidate_bytes'=>0,'candidate_groups'=>0]
    ],'history'=>[],
    'history_total'=>0,'recent_failures'=>['failed_jobs'=>0,'queued_jobs'=>0,'running_jobs'=>0]
]);
checkPolish(str_contains($reports,'6.15 TiB') &&
    str_contains($reports,'40.20 GiB'),
    'Reports still display unformatted indexed/recovery bytes.');
checkPolish(str_contains($reports,'data-sort-value="43162679080"') &&
    str_contains($reports,'title="43,162,679,080 bytes"') &&
    !str_contains($reports,'title="&lt;span') &&
    !str_contains($reports,'title="<span'),
    'Populated report size tooltips contain nested or malformed HTML.');
foreach(['dashboard/index.html.twig','reports/index.html.twig','search/index.html.twig'] as $source) {
    $content=file_get_contents($root.'/templates/'.$source);
    checkPolish(substr_count($content,"from 'duplicates/_format.html.twig' import bytes")===1,
        'Duplicate size-formatting imports left in '.$source);
}
$profile=['id'=>5,'name'=>'TV Shows','source_root'=>'/volume1/TV Shows',
    'move_root'=>'/volume1/Archive','hash_mode'=>'sha256','enabled'=>true,
    'minimum_bytes'=>0,'profile_type_id'=>1,'file_exists_policy'=>'fail'];
$create=$twig->render('profiles/new.html.twig',$app+[
    'profile'=>null,'types'=>[['id'=>1,'name'=>'All files']],
    'errors'=>[],'schedule'=>false
]);
checkPolish(str_contains($create,'Off') &&
    str_contains($create,'Custom interval and start time'),
    'New profile schedule form does not render without an existing schedule.');
$edit=$twig->render('profiles/new.html.twig',$app+[
    'profile'=>$profile,'types'=>[['id'=>1,'name'=>'All files']],
    'errors'=>[],'schedule'=>['interval_minutes'=>20,'next_run_at'=>'2026-12-10 08:00:00']
]);
foreach(['Custom interval and start time','schedule_first_utc','profile-schedule.js',
    'schedule_amount','2026-12-10T08:00'] as $needle)
    checkPolish(str_contains($edit,$needle),'Custom schedule edit missing '.$needle);
$view=$twig->render('profiles/view.html.twig',$app+[
    'profile'=>$profile,'schedule'=>['interval_minutes'=>20,'next_run_at'=>'2026-12-10 08:00:00'],
    'schedule_label'=>ScanScheduleService::description(20),'scans'=>[],'jobs'=>[]
]);
checkPolish(str_contains($view,'Every 20 minutes') &&
    str_contains($view,'2026-12-10 08:00:00 UTC'),
    'Profile view did not display stored custom schedule.');
$profiles=$twig->render('profiles/index.html.twig',$app+[
    'profiles'=>[array_merge($profile,[
        'type_name'=>'All','completed_scans'=>0,'retained_runs'=>0,
        'schedule_interval'=>20,'schedule_label'=>'Every 20 minutes',
        'schedule_next'=>'2026-12-10 08:00:00','schedule_error'=>null,
        'active_state'=>null,'active_job_id'=>null
    ])],'types'=>[], 'busy_count'=>0,'unresponsive_count'=>0
]);
checkPolish(str_contains($profiles,'Every 20 minutes') &&
    !str_contains($profiles,'schedule-inline-form'),
    'Profiles overview still allows schedule modification.');
$dup=file_get_contents($root.'/templates/duplicates/index.html.twig');
foreach(['data-dup-rename','data-dup-move','data-dup-delete','data-dup-link',
    "dup_icon('trash')","dup_icon('move')"] as $needle)
    checkPolish(str_contains($dup,$needle),'Bulk duplicate icon missing '.$needle);
$rename=file_get_contents($root.'/templates/rename/index.html.twig');
checkPolish(str_contains($rename,'candidates.previous') &&
    str_contains($rename,'candidates.next') &&
    str_contains($rename,'data-highlight-text'),
    'Rename results lack previous/next controls or highlight hooks.');
$worker=file_get_contents($root.'/templates/jobs/worker-log.html.twig');
checkPolish(str_contains($worker,'diagnostic-test-explainer') &&
    str_contains($worker,'diagnostic-page-note'),
    'Diagnostic panel explanatory content remains disorganized.');
$js=file_get_contents($root.'/public/assets/table-local.js');
checkPolish(str_contains($js,'local-table-pager') &&
    str_contains($js,'25,50,100,0') &&
    str_contains($js,'previous.disabled') &&
    str_contains($js,'next.disabled'),
    'Long client-rendered table pagination is not implemented.');
echo "PASS human units, search metadata/links/icons, schedule view/edit, duplicate actions, rename highlighting and table pagination\n";
