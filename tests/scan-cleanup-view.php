<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']);
foreach([
    'path'=>static fn(string $route,array $args=[])=>'/'.$route,
    'asset'=>static fn(string $asset)=>'/assets/'.$asset,
    'csrf_token'=>static fn(string $key)=>'token-'.$key,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn(string $role)=>$role==='ROLE_ADMIN'
] as $name=>$callback) $twig->addFunction(new Twig\TwigFunction($name,$callback));
$run=[
    'id'=>71,'profile_id'=>2,'profile_name'=>'Media',
    'state'=>'completed','started_at'=>'2026-11-24 08:00:00',
    'finished_at'=>'2026-11-24 09:00:00',
    'files_seen'=>3,'files_indexed'=>2,'bytes_indexed'=>1000,
    'error_count'=>0,'job_state'=>'completed',
    'files'=>[
        'ignored_files'=>0,'hashed_files'=>2,'symlinks_indexed'=>0,
        'quarantined_files'=>0,'flagged_files'=>0
    ],
    'groups'=>[
        'groups'=>1,'verified_groups'=>1,'verified_bytes'=>500,
        'candidate_groups'=>0,'candidate_bytes'=>0
    ]
];
$cleanup=[
    'is_current'=>true,'files'=>2,'directories'=>1,
    'groups'=>1,'jobs'=>1,'can_remove'=>true,'blocked_reason'=>''
];
$args=['app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'run'=>$run,'flagged'=>[],'warnings'=>[],'next'=>null,'cleanup'=>$cleanup
];
$html=$twig->render('reports/run.html.twig',$args);
foreach(['Clear current scan results','token-remove_scan_71','Type CLEAR','Older completed scans'] as $needle)
    if(!str_contains($html,$needle)) throw new RuntimeException('Missing scan cleanup interface: '.$needle);
$args['cleanup']['is_current']=false;
$args['cleanup']['can_remove']=false;
$args['cleanup']['blocked_reason']='Recovery data still referenced';
$blocked=$twig->render('reports/run.html.twig',$args);
if(!str_contains($blocked,'Removal blocked') || !str_contains($blocked,'Recovery data still referenced')
    || str_contains($blocked,'name="confirm"'))
    throw new RuntimeException('Blocked scan removal offered a confirmation form.');
$args['cleanup']=null;
$reader=$twig->render('reports/run.html.twig',$args);
if(str_contains($reader,'Type DELETE') || str_contains($reader,'Type CLEAR') || str_contains($reader,'remove_scan_71'))
    throw new RuntimeException('Read-only report exposed scan removal.');
echo "PASS scan removal UI: explicit CLEAR, blockers, CSRF and read-only render\n";
