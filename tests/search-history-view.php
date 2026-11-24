<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function renderHistory(bool $admin,string $mode,array $rows):string
{
    $twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
    $twig->addFunction(new Twig\TwigFunction('path',static fn(string $name,array $args=[])=>'/'.$name));
    $twig->addFunction(new Twig\TwigFunction('asset',static fn(string $name)=>'/assets/'.$name));
    $twig->addFunction(new Twig\TwigFunction('csrf_token',static fn(string $key)=>'test-token'));
    $twig->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>[]));
    $twig->addFunction(new Twig\TwigFunction('is_granted',static fn()=> $admin));
    return $twig->render('search/index.html.twig',[
        'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
        'profiles'=>[['id'=>1,'name'=>'Audio']],
        'term'=>'','mode'=>$mode,'profile'=>null,'case_sensitive'=>false,
        'submitted'=>true,'results'=>['rows'=>$rows,'total'=>1,'next'=>null,'previous'=>null],
        'filters'=>['q'=>'','mode'=>$mode,'case'=>'0']
    ]);
}
$history=[
    'id'=>1,'batch_id'=>1,'run_id'=>37,'scan_finished_at'=>'2026-01-30',
    'requested_at'=>'2026-02-01','profile_name'=>'Audio',
    'source_path'=>'/media/Original.m4b','target_path'=>'/recovery/original.m4b',
    'link_target_path'=>'/media/Survivor.m4b','action_kind'=>'link',
    'item_state'=>'restored','batch_state'=>'restored','result_code'=>'LINK_REMOVED'
];
$readOnly=renderHistory(false,'history',[$history]);
if(!str_contains($readOnly,'Original.m4b') ||
   !str_contains($readOnly,'Survivor.m4b') ||
   !str_contains($readOnly,'Run #37') ||
   !str_contains($readOnly,'restored') ||
   str_contains($readOnly,'href="/app_action_detail"'))
    throw new RuntimeException('Historical read-only view missing data or contains restricted links.');
$admin=renderHistory(true,'history',[$history]);
if(!str_contains($admin,'href="/app_action_detail"'))
    throw new RuntimeException('Admin cannot inspect action batch from history.');
$file=[
    'id'=>2,'filename'=>'A-Link.m4b','directory'=>'/media','profile_name'=>'Audio',
    'size_bytes'=>18,'mtime_epoch'=>1780000000,'hash_method'=>null,
    'hash_value'=>null,'error_code'=>null,'is_ignored'=>false,'is_symlink'=>1,
    'link_target'=>'../Books/Original.m4b','group_id'=>null
];
$index=renderHistory(false,'all',[$file]);
if(!str_contains($index,'../Books/Original.m4b') ||
   !str_contains($index,'Symbolic link') ||
   !str_contains($index,'Link metadata only') ||
   str_contains($index,'href="/app_file_view"'))
    throw new RuntimeException('Search symlink view is not metadata-only.');
echo "PASS action history and symlink metadata render for both roles without exposing unauthorized actions\n";
