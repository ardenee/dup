<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
$twig->addFunction(new Twig\TwigFunction('path',
    static fn(string $route,array $args=[])=>'/'.$route.($args?'?'.http_build_query($args):'')));
$twig->addFunction(new Twig\TwigFunction('asset',static fn(string $path)=>'/assets/'.$path));
$twig->addFunction(new Twig\TwigFunction('csrf_token',static fn(string $key)=>'test-token-'.$key));
$twig->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>[]));
$twig->addFunction(new Twig\TwigFunction('is_granted',static fn()=>true));
$base=['app'=>['user'=>null,'environment'=>'test','flashes'=>[]]];
$batch=[
    'id'=>45,'profile_name'=>'Audio','action_kind'=>'rename',
    'source_path'=>'/music/<script>alert(1)</script>.mp3',
    'item_count'=>2,'failed_items'=>1,'state'=>'needs_review',
    'requested_at'=>'2026-11-24 08:01:00','completed_at'=>null,
    'error_code'=>'RENAME_FAILED'
];
$html=$twig->render('actions/index.html.twig',$base+[
    'page'=>['rows'=>[$batch],'total'=>65,'previous'=>46,'next'=>44,'limit'=>25],
    'profiles'=>[['id'=>1,'name'=>'Audio']],
    'states'=>['completed','needs_review'],
    'profile'=>1,'kind'=>'rename','state'=>'needs_review',
    'filters'=>['profile'=>1,'kind'=>'rename','state'=>'needs_review']
]);
foreach (['Batch / operation','#45','Previous (newer)','Next (older)','65 matching batches','RENAME_FAILED'] as $needle)
    if(!str_contains($html,$needle)) throw new RuntimeException('Missing history UI: '.$needle);
if(str_contains($html,'<script>') || !str_contains($html,'&lt;script&gt;'))
    throw new RuntimeException('Filename escaped incorrectly');
if(!str_contains($html,'after=46') || !str_contains($html,'before=44'))
    throw new RuntimeException('History links dropped the cursor');
if(!str_contains($html,'state=needs_review') || !str_contains($html,'kind=rename'))
    throw new RuntimeException('History paging lost filters');
$details=$twig->render('actions/rename-detail.html.twig',$base+[
    'batch'=>$batch+[
        'actor_name'=>'admin','items'=>[
            ['source_path'=>'/music/one.mp3','target_path'=>'/music/two.mp3',
             'expected_size'=>200,'state'=>'renamed','result_code'=>null],
            ['source_path'=>'/music/three.mp3','target_path'=>'/music/four.mp3',
             'expected_size'=>300,'state'=>'failed','result_code'=>'NAME_CONFLICT']
        ]
    ]
]);
foreach (['NAME_CONFLICT','/music/two.mp3','/music/four.mp3','Manual review required'] as $needle)
    if(!str_contains($details,$needle)) throw new RuntimeException('Missing rename detail: '.$needle);
echo "PASS action history templates, filter cursors, escaped paths, full rename batch diagnostics\n";
