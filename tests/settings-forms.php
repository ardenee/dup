<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
$twig->addFunction(new Twig\TwigFunction('path',static fn(string $route,array $args=[])=>'/'.$route));
$twig->addFunction(new Twig\TwigFunction('asset',static fn(string $path)=>'/assets/'.$path));
$twig->addFunction(new Twig\TwigFunction('csrf_token',static fn(string $key)=>'test-token-'.$key));
$twig->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>[]));
$twig->addFunction(new Twig\TwigFunction('is_granted',static fn()=>true));
$app=['app'=>['user'=>null,'environment'=>'test','flashes'=>[]]];
$html=$twig->render('accounts/index.html.twig',$app+[
    'accounts'=>[
        ['id'=>1,'username'=>'admin','roles'=>'["ROLE_ADMIN"]','disabled_at'=>null,'created_at'=>'2026-11-01'],
        ['id'=>2,'username'=>'reader','roles'=>'["ROLE_USER"]','disabled_at'=>null,'created_at'=>'2026-11-02']
    ],
    'editing'=>null,'self_id'=>1
]);
if(!str_contains($html,'Read-only user') || !str_contains($html,'Add account') ||
   !str_contains($html,'Disable') || !str_contains($html,'Administrator'))
    throw new RuntimeException('Account form failed to render expected roles/actions.');
$viewer=$twig->render('files/settings.html.twig',$app+[
    'settings'=>[
        'view_enable'=>'1','view_image_exts'=>'jpg,png',
        'view_text_exts'=>'txt,log','view_pdf_exts'=>'pdf'
    ]
]);
if(!str_contains($viewer,'Save viewer settings') ||
   !str_contains($viewer,'jpg,png') ||
   !str_contains($viewer,'Enable file viewer previews'))
    throw new RuntimeException('File viewer preferences form failed.');
$twigReadOnly=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'));
$twigReadOnly->addFunction(new Twig\TwigFunction('is_granted',static fn()=>false));
$twigReadOnly->addFunction(new Twig\TwigFunction('path',static fn(string $route,array $args=[])=>'/'.$route));
$twigReadOnly->addFunction(new Twig\TwigFunction('asset',static fn(string $path)=>'/assets/'.$path));
$twigReadOnly->addFunction(new Twig\TwigFunction('csrf_token',static fn(string $key)=>'test-token-'.$key));
$twigReadOnly->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>[]));
$frag=$twigReadOnly->render('duplicates/_members.html.twig',[
 'members'=>[['file_entry_id'=>24,'full_path'=>'/files/reader.mp3',
 'relative_path'=>'reader.mp3','mtime_epoch'=>1780000000,
 'size_bytes'=>1024,'hash_value'=>'abcd','error_code'=>null]],
 'group'=>['id'=>3,'file_count'=>2,'rules_version'=>2],
 'start_number'=>2,'previous'=>null,'next'=>null
]);
if(str_contains($frag,'data-dup-select') || str_contains($frag,'>Rename<') ||
   str_contains($frag,'preview_file_') || !str_contains($frag,'>View<'))
    throw new RuntimeException('Read-only view includes dangerous controls.');
echo "PASS account/viewer forms and read-only duplicate rows render without mutation controls\n";
