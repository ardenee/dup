<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
function ensureHistory(bool $valid,string $message):void {
    if(!$valid) throw new RuntimeException($message);
}
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'csrf-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn(string $role)=>true
] as $name=>$fn) $twig->addFunction(new Twig\TwigFunction($name,$fn));
$app=['app'=>['user'=>null,'flashes'=>[],'environment'=>'test']];
$group=[
    'id'=>20,'run_id'=>31,'size_bytes'=>256,'file_count'=>2,'reclaimable_bytes'=>256,
    'rules_version'=>1,'profile_name'=>'Music','profile_id'=>2,
    'source_root'=>'/data/Music','first_file_id'=>4,'first_filename'=>'Track.mp3',
    'first_mtime_epoch'=>1780000000,'first_hash_value'=>str_repeat('a',64),
    'first_error_code'=>null,'first_directory'=>'/data/Music',
    'relative_path'=>'Track.mp3','full_path'=>'/data/Music/Track.mp3'
];
$view=$twig->render('duplicates/index.html.twig',$app+[
    'groups'=>[$group], 'historical'=>true, 'selected_run'=>31,
    'run_info'=>['id'=>31,'profile_id'=>2,'state'=>'archived','profile_name'=>'Music'],
    'profiles'=>[['id'=>2,'name'=>'Music','duplicate_groups'=>1]],
    'selected_profile'=>2,'previous'=>null,'next'=>30,
    'totals'=>['total_groups'=>1,'total_members'=>2,'reclaimable_bytes'=>256],
    'page_start'=>1,'page_end'=>1,'page_rows'=>2,'page_listed_bytes'=>512,'page_reclaimable'=>256
]);
foreach(['Historical snapshot','read-only','Track.mp3','run=31','Historical record','data-scan-run'] as $needle)
    ensureHistory(str_contains($view,$needle),'Historical browser missing '.$needle);
foreach(['data-dup-rename','data-dup-move','data-dup-select','preview_link_','preview_file_','app_file_download'] as $forbidden)
    ensureHistory(!str_contains($view,$forbidden),'Historical browser exposed mutation: '.$forbidden);
$members=$twig->render('duplicates/_members.html.twig',$app+[
    'group'=>$group,'historical'=>true,
    'members'=>[[
        'file_entry_id'=>5,'relative_path'=>'Track (copy).mp3',
        'full_path'=>'/data/Music/Track (copy).mp3','size_bytes'=>256,
        'mtime_epoch'=>1780000000,'hash_value'=>str_repeat('a',64),
        'error_code'=>null
    ]],
    'start_number'=>2,'previous'=>null,'next'=>null
]);
ensureHistory(str_contains($members,'Track (copy).mp3'),'Historical member missing');
foreach(['data-dup-select','app_file_view','app_file_download','preview_link_','preview_file_'] as $forbidden)
    ensureHistory(!str_contains($members,$forbidden),'Historical members exposed action: '.$forbidden);
$detail=$twig->render('duplicates/group.html.twig',$app+[
    'group'=>$group,'historical'=>true,
    'members'=>[[
        'file_entry_id'=>5,'path'=>'/data/Music','filename'=>'Track (copy).mp3',
        'size_bytes'=>256,'mtime_epoch'=>1780000000,
        'hash_value'=>str_repeat('a',64),'error_code'=>null
    ]],
    'previous'=>null,'next'=>null
]);
ensureHistory(str_contains($detail,'Historical scan #31'),'Group detail missing history notice');
foreach(['app_file_view','app_file_download','app_rename_file','app_link_preview','app_action_preview'] as $forbidden)
    ensureHistory(!str_contains($detail,$forbidden),'Historical group detail exposed action: '.$forbidden);
echo "PASS historical duplicate browser, inline members and group details are read-only and retain scan navigation\n";
