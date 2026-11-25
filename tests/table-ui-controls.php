<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function ckTable(bool $result,string $message):void {
    if(!$result)throw new RuntimeException($message);
}
$root=dirname(__DIR__);
$serverTables=[
    'jobs/index.html.twig'=>['job-kind','job-profile','job-state','sort_heading','job_filters'],
    'jobs/detail.html.twig'=>['job-event-severity','sort_heading','event_filters'],
    'reports/index.html.twig'=>['scan-state','sort_heading','history_filters'],
    'reports/run.html.twig'=>['file-status-filter','sort_heading','file_filters'],
    'actions/index.html.twig'=>['action-profile','action-kind','action-state','sort_heading'],
    'search/index.html.twig'=>['search-file-state','search-action','sort_heading','filters'],
    'duplicates/group.html.twig'=>['member-filename','member-status','sort_heading','table_filters'],
    'duplicates/index.html.twig'=>['dup-file-filter','dup-confidence-filter','dup-min-files',
        'dup-min-size','sort_heading','table_filters'],
    'rename/index.html.twig'=>['rename-name-filter','sort_heading','paging']
];
foreach($serverTables as $template=>$needles) {
    $text=file_get_contents($root.'/templates/'.$template);
    ckTable(str_contains($text,"from '_table_headers.html.twig'"),
        'Shared SQL-backed sort headings missing: '.$template);
    if(!in_array($template,['reports/index.html.twig','reports/run.html.twig','rename/index.html.twig'],true))
        ckTable(!str_contains($text,'data-local-table='),
            'A paginated table may not be client-side sorted as if it were complete: '.$template);
    foreach($needles as $needle)
        ckTable(str_contains($text,$needle),'Missing table control in '.$template.': '.$needle);
}
foreach([
    'dashboard/index.html.twig'=>['recent scans','recent jobs'],
    'profiles/index.html.twig'=>['profiles','file type presets'],
    'profiles/types.html.twig'=>['file type presets'],
    'accounts/index.html.twig'=>['accounts'],
    'reports/index.html.twig'=>['profile snapshots'],
    'rename/index.html.twig'=>['recent rename operations'],
    'actions/rename-detail.html.twig'=>['rename batch items'],
    'actions/move.html.twig'=>['move batch files'],
    'actions/bulk.html.twig'=>['bulk action files'],
    'actions/delete.html.twig'=>['delete batch files'],
    'rename/selected.html.twig'=>['selected rename files'],
    'reports/run.html.twig'=>['recent warning events'],
    'system/index.html.twig'=>['recent failures']
] as $template=>$labels) {
    $text=file_get_contents($root.'/templates/'.$template);
    foreach($labels as $label)
        ckTable(str_contains($text,'data-local-table="'.$label.'"'),
            'Local sort/filter control missing from '.$template.': '.$label);
}
$base=file_get_contents($root.'/templates/base.html.twig');
ckTable(str_contains($base,"asset('assets/table-local.js')"),
    'Local table utility was not included globally.');
$js=file_get_contents($root.'/public/assets/table-local.js');
foreach(['data-local-table','aria-sort','local-row-filtered','sortValue','search.addEventListener','local-table-pager','pageStatus'] as $needle)
    ckTable(str_contains($js,$needle),'Local table utility missing '.$needle);

$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader($root.'/templates'));
$twig->addFunction(new Twig\TwigFunction('path',static fn(string $route,array $args=[]) =>
    '/'.$route.($args?'?'.http_build_query($args):'')));
$html=$twig->createTemplate(
    "{% from '_table_headers.html.twig' import sort %}".
    "{{ sort('app_jobs',{'profile':5,'kind':'scan','state':'failed','sort':'status','dir':'asc'},".
    "'status','asc','status','Status') }}"
)->render();
foreach(['aria-sort="ascending"','profile=5','kind=scan','state=failed','sort=status','dir=desc'] as $needle)
    ckTable(str_contains($html,$needle),'Sort heading did not preserve active filters: '.$needle);
ckTable(str_contains($html,'aria-label='),'Sort control missing accessible label.');
echo "PASS shared database-backed sort controls and scope-preserving filter links on nine paginated views; local filtering on smaller tables\n";
