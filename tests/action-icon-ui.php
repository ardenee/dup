<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
function verifyIcons(bool $condition,string $why):void {
    if(!$condition)throw new RuntimeException($why);
}
$root=dirname(__DIR__);
$sprite=file_get_contents($root.'/templates/_action_sprite.html.twig');
$macro=file_get_contents($root.'/templates/_action_icons.html.twig');
$base=file_get_contents($root.'/templates/base.html.twig');
verifyIcons(str_contains($base,"include \"_action_sprite.html.twig\""),
    'Action icons are not available across all pages.');
verifyIcons(str_contains($macro,'class="action-icon"'),'Shared SVG helper is missing.');
$targets=[
    'dashboard/index.html.twig'=>['View duplicate results','View scan report'],
    'profiles/index.html.twig'=>['Browse scans and duplicates','Start scan','Reports and scan history','Edit profile and File Exists settings','Save automatic scan schedule'],
    'profiles/types.html.twig'=>['Edit file type preset','Delete unused preset'],
    'jobs/index.html.twig'=>['Job details and events','Scan duplicate results','Request cancellation of job','Delete job #'],
    'reports/index.html.twig'=>['Browse scan duplicate results','Review report and manage scan'],
    'rename/index.html.twig'=>['Rename this file','View rename batch details'],
    'accounts/index.html.twig'=>['Edit account','Enable account','Disable account','Delete account permanently']
];
foreach($targets as $template=>$tooltips) {
    $source=file_get_contents($root.'/templates/'.$template);
    verifyIcons(str_contains($source,"from '_action_icons.html.twig' import icon"),
        'Missing shared icon helper in '.$template);
    foreach($tooltips as $title)
        verifyIcons(str_contains($source,$title),
            'Icon tooltip/accessible label missing: '.$template.' / '.$title);
    preg_match_all("~icon\\('([a-z-]+)'\\)~",$source,$names);
    verifyIcons(count($names[1])>0,'No icon actions present in '.$template);
    foreach($names[1] as $icon)
        verifyIcons(str_contains($sprite,'id="app-icon-'.$icon.'"'),
            'SVG artwork missing for '.$icon.' in '.$template);
    preg_match_all('~<(?:a|button)\b[^>]*class="[^"]*action-icon-button[^"]*"[^>]*>~s',$source,$controls);
    verifyIcons(count($controls[0])>0,'No accessible icon controls in '.$template);
    foreach($controls[0] as $control)
        verifyIcons(str_contains($control,'title=') && str_contains($control,'aria-label='),
            'Icon-only control needs tooltip and accessible name: '.$template);
}
$profiles=file_get_contents($root.'/templates/profiles/new.html.twig');
verifyIcons(str_contains($profiles,'name="file_exists_policy"'),
    'Profile edit form does not contain File Exists preferences.');
$nav=file_get_contents($root.'/src/Module/FileActions/FileActionsModule.php');
verifyIcons(!str_contains($nav,'app_action_settings'),
    'Obsolete global File Exists navigation is still available.');
echo "PASS unified action icons, tooltips, accessible names and profile-scoped File Exists UI across all six requested areas\n";
