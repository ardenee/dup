<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']);
foreach([
    'path'=>static fn(string $route,array $args=[])=>'/'.$route.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $file)=>'/assets/'.$file,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$profile=[
    'id'=>5,'name'=>'Old music','source_root'=>'/old/Music','hash_mode'=>'sha256',
    'type_name'=>'All files','enabled'=>false,
    'finished_at'=>null,'files_indexed'=>null,'duplicate_groups'=>null,
    'last_saved_at'=>'2026-11-23 17:00:00','retained_runs'=>4,
    'completed_scans'=>4,'schedule_interval'=>1440,'schedule_next'=>'2026-11-25 09:00:00',
    'schedule_error'=>'Failed to read root directory',
    'active_state'=>null,'active_phase'=>null
];
$html=$twig->render('profiles/index.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'profiles'=>[$profile],'types'=>[]
]);
foreach(['Historical results available','4 retained scans',
    'Browse scans for Old music','profile=5','Reports and scan history','Completed scans',
    '4 retained scans','profile-schedule-label','2026-11-25 09:00:00',
    'Failed to read root directory','Ignored folders'] as $expected)
    if(!str_contains($html,$expected))
        throw new RuntimeException('Archived-only profile view missing: '.$expected);
if(str_contains($html,'Never scanned'))
    throw new RuntimeException('Archived-only profile was incorrectly described as never scanned.');
echo "PASS cleared-current profiles still show retained scans and direct historical browsing\n";
