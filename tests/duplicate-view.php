<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$twig=new \Twig\Environment(new \Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
$twig->addFunction(new \Twig\TwigFunction('path',static function(string $name,array $args=[]):string {
    return '/'.$name.($args?'?'.http_build_query($args):'');
}));
$twig->addFunction(new \Twig\TwigFunction('asset',static fn(string $name)=>'/assets/'.$name));
$twig->addFunction(new \Twig\TwigFunction('csrf_token',static fn(string $name)=>'test-token-'.$name));
$twig->addFunction(new \Twig\TwigFunction('dup_navigation',static fn()=>[]));
$twig->addFunction(new \Twig\TwigFunction('is_granted',static fn()=>true));
$common=[
    'app'=>[
        'user'=>null,'environment'=>'test','flashes'=>[]
    ]
];
$view=$twig->render('duplicates/index.html.twig',$common+[
    'groups'=>[[
        'id'=>7,'size_bytes'=>10485760,'file_count'=>3,
        'reclaimable_bytes'=>20971520,'rules_version'=>2,
        'profile_name'=>'Audio Books','profile_id'=>1,
        'source_root'=>'/volume1/AudioBooks',
        'first_file_id'=>91,'first_filename'=>'Part_001.m4b',
        'first_mtime_epoch'=>1770000000,
        'first_hash_value'=>'A12B34C5','first_error_code'=>null,
        'first_directory'=>'/volume1/AudioBooks/Author',
        'relative_path'=>'Author/Part_001.m4b',
        'full_path'=>'/volume1/AudioBooks/Author/Part_001.m4b'
    ]],
    'totals'=>['total_groups'=>1,'total_members'=>3,'reclaimable_bytes'=>20971520],
    'profiles'=>[['id'=>1,'name'=>'Audio Books','duplicate_groups'=>1]],
    'selected_profile'=>1,'previous'=>null,'next'=>null,
    'page_start'=>1,'page_end'=>1,'page_rows'=>3,
    'page_listed_bytes'=>31457280,'page_reclaimable'=>20971520
]);
function ensure(bool $ok,string $description):void {
    if(!$ok) throw new \RuntimeException($description);
}
ensure(substr_count($view,'<table')===1,'The results must use exactly one table');
ensure(str_contains($view,'Author/Part_001.m4b'),'Relative file path missing');
ensure(str_contains($view,'10.00 MiB'),'Human-readable file size missing');
ensure(str_contains($view,'CRC32 3 Part'),'Matching method missing');
ensure(str_contains($view,'data-dup-toggle'),'Expandable row missing');
ensure(str_contains($view,'data-dup-invert'),'Original inverse selection missing');
ensure(str_contains($view,'data-dup-copy'),'Copy paths action missing');
ensure(str_contains($view,'Groups <strong>1–1</strong>'),'Group pagination missing');
ensure(str_contains($view,'Possible cleanup'),'Footer missing');
$fragment=$twig->render('duplicates/_members.html.twig',$common+[
    'group'=>['id'=>7,'file_count'=>3,'rules_version'=>2],
    'members'=>[
        ['file_entry_id'=>92,'size_bytes'=>10485760,'mtime_epoch'=>1770000000,
         'hash_value'=>'A12B34C5','error_code'=>null,'member_id'=>2,
         'relative_path'=>'Author/Part_001-copy.m4b',
         'full_path'=>'/volume1/AudioBooks/Author/Part_001-copy.m4b'],
        ['file_entry_id'=>93,'size_bytes'=>10485760,'mtime_epoch'=>1770000000,
         'hash_value'=>'A12B34C5','error_code'=>null,'member_id'=>3,
         'relative_path'=>'Author/Part_001-duplicate.m4b',
         'full_path'=>'/volume1/AudioBooks/Author/Part_001-duplicate.m4b']
    ],
    'start_number'=>2,'previous'=>null,'next'=>null
]);
ensure(substr_count($fragment,'<tr')===2,'Expected two sibling rows');
ensure(!str_contains($fragment,'<table'),'Expanded file list must not nest tables');
ensure(str_contains($fragment,'Part_001-copy.m4b'),'Matching filename missing');
ensure(str_contains($fragment,'data-file-path'),'Full path selection missing');
echo "PASS compact duplicate table, original columns and footer, readable file paths, inline sibling rows\n";
