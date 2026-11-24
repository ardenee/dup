<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function checkDash(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException($why);
}
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
$admin=true;
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[]
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$twig->addFunction(new Twig\TwigFunction('is_granted',static function() use (&$admin):bool {
    return $admin;
}));
$payload=[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'metrics'=>[
        'profiles'=>2,'completed_scans'=>5,'files'=>240,
        'duplicate_groups'=>12,'verified_potential_bytes'=>1000,'active_jobs'=>0
    ],
    'firstProfileId'=>2,
    'scans'=>[
        ['id'=>15,'profile_id'=>2,'state'=>'archived','finished_at'=>'2026-11-20 18:00:00',
         'started_at'=>'2026-11-20 17:00:00','duplicate_groups'=>8,
         'files_indexed'=>32,'profile_name'=>'Audio'],
        ['id'=>20,'profile_id'=>2,'state'=>'completed','finished_at'=>'2026-11-24 18:00:00',
         'started_at'=>'2026-11-24 17:00:00','duplicate_groups'=>4,
         'files_indexed'=>42,'profile_name'=>'Audio']
    ],
    'jobs'=>[
        ['id'=>23,'kind'=>'scan','phase'=>'completed','state'=>'completed',
         'progress_current'=>42,'progress_total'=>42,'run_id'=>15,'run_state'=>'archived']
    ]
];
$html=$twig->render('dashboard/index.html.twig',$payload);
foreach(['Browse current duplicates','Browse older scans','Recent scans',
    'run=15','app_report_run','Open scan results','Retained scan results',
    'Start or configure scans','File action history'] as $needle)
    checkDash(str_contains($html,$needle),'Dashboard missing a useful shortcut: '.$needle);
$admin=false;
$reader=$twig->render('dashboard/index.html.twig',$payload);
checkDash(str_contains($reader,'Browse older scans') &&
    !str_contains($reader,'Start or configure scans'),
    'Read-only dashboard exposed administrative actions');
checkDash(str_contains($reader,'Find a file'),
    'Read-only dashboard lacks search shortcut');
checkDash(!str_contains($reader,'/app_jobs') &&
    !str_contains($reader,'/app_job_detail'),
    'Read-only dashboard exposed administrator-only job links');
echo "PASS dashboard shortcuts, current/old scan links, job results, and read-only role\n";
