<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),[
    'autoescape'=>'html'
]);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn(string $role)=>true
] as $name=>$fn) $twig->addFunction(new Twig\TwigFunction($name,$fn));
$app=['app'=>['user'=>null,'flashes'=>[],'environment'=>'test']];
$group=[
    'id'=>20,'run_id'=>31,'size_bytes'=>256,'file_count'=>3,'reclaimable_bytes'=>512,
    'rules_version'=>1,'profile_name'=>'Music','profile_id'=>2,
    'source_root'=>'/data/Music','first_file_id'=>4,'first_filename'=>'Track.mp3',
    'first_mtime_epoch'=>1780000000,'first_hash_value'=>str_repeat('a',64),
    'first_error_code'=>null,'first_directory'=>'/data/Music',
    'relative_path'=>'Track.mp3','full_path'=>'/data/Music/Track.mp3'
];
$view=$twig->render('duplicates/index.html.twig',$app+[
    'groups'=>[$group], 'historical'=>false, 'selected_run'=>null,
    'run_info'=>null,
    'profiles'=>[['id'=>2,'name'=>'Music','duplicate_groups'=>1]],
    'selected_profile'=>2,'previous'=>null,'next'=>null,
    'totals'=>['total_groups'=>1,'total_members'=>3,'reclaimable_bytes'=>512],
    'page_start'=>1,'page_end'=>1,'page_rows'=>3,'page_listed_bytes'=>768,
    'page_reclaimable'=>512
]);
foreach(['data-dup-rename','data-dup-move','data-dup-delete','data-dup-link',
    'Select matching files','data-dup-select-visible','data-dup-clear-selection',
    'data-bulk-duplicate-url','data-bulk-duplicate-token',
    'id="dup-bulk-delete-form"','data-dup-delete-form',
    'form="dup-bulk-delete-form"','name="return_profile"',
    'token-delete_selected','data-dup-action-ids'] as $needle)
    if(!str_contains($view,$needle))throw new RuntimeException('Bulk browser missing '.$needle);
// The delete button must use the browser's native POST form, never a
// dynamically created form whose form.submit() can silently fail.
if(!preg_match('~<button[^>]*type="submit"[^>]*form="dup-bulk-delete-form"[^>]*data-dup-delete~',$view))
    throw new RuntimeException('Bulk delete must submit a persistent native form.');
if(!preg_match('~<form[^>]*id="dup-bulk-delete-form"[^>]*method="post"[^>]*action="/app_delete_preview"~',$view))
    throw new RuntimeException('Bulk delete preview endpoint must receive POST.');
$javascript=file_get_contents(dirname(__DIR__).'/public/assets/duplicates.js');
foreach(["actionIds.forEach(field => { field.value = csv; })",
    "form.addEventListener('submit'", "const csv = [...selected.keys()].join(',')",
    "deleteButton.disabled = selected.size === 0"] as $needle)
    if(!str_contains($javascript,$needle))
        throw new RuntimeException('Bulk delete selection/validation missing '.$needle);
if(str_contains($javascript,"deleteButton?.addEventListener('click'") ||
    str_contains($javascript,"form.submit()"))
    throw new RuntimeException('A temporary form handler must not be used for any bulk action.');
foreach(['form="dup-bulk-rename-form"','form="dup-bulk-move-form"',
    'form="dup-bulk-link-form"','data-select-url'] as $needle)
    if(!str_contains($view,$needle))
        throw new RuntimeException('Bulk actions must use native forms and group selections: '.$needle);
if(str_contains($view,'Limit: 50') || str_contains($view,'up to 50'))
    throw new RuntimeException('Unnecessary bulk file limit is still in the interface.');
$item=[
    'id'=>5,'source_path'=>'/data/Music/Track Copy.mp3',
    'target_path'=>'/recovery/.dup-quarantine/entry-5/Track Copy.mp3',
    'link_target_path'=>null,'survivor_file_entry_id'=>4,
    'survivor_directory'=>'/data/Music','survivor_filename'=>'Track.mp3',
    'expected_size'=>256,'state'=>'planned','result_code'=>null
];
$batch=[
    'id'=>11,'profile_name'=>'Music','action_kind'=>'bulk_quarantine',
    'items'=>[$item],'state'=>'planned',
    'expires_at'=>'2026-12-01 12:00:00'
];
$preview=$twig->render('actions/bulk.html.twig',$app+['batch'=>$batch]);
foreach(['Track Copy.mp3','Track.mp3','QUARANTINE','token-bulk_confirm_11',
    'unselected','Confirm and queue bulk action'] as $needle)
    if(!str_contains($preview,$needle)) throw new RuntimeException('Bulk preview missing '.$needle);
$batch['action_kind']='bulk_link';
$batch['items'][0]['link_target_path']='/data/Music/Track.mp3';
$linked=$twig->render('actions/bulk.html.twig',$app+['batch'=>$batch]);
if(!str_contains($linked,'Bulk symbolic links') || !str_contains($linked,'Type LINK'))
    throw new RuntimeException('Bulk link preview missing authorization');
$batch['state']='completed';
$restoring=$twig->render('actions/bulk.html.twig',$app+['batch'=>$batch]);
if(!str_contains($restoring,'Queue bulk restore') || !str_contains($restoring,'token-bulk_restore_11'))
    throw new RuntimeException('Bulk restore authorization missing');
echo "PASS bulk controls, survivor selection, previews, CSRF and restore UI\n";
