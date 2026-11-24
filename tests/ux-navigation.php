<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Core\Module\ModuleRegistry;
use App\Module\Accounts\AccountManagementModule;
use App\Module\Dashboard\DashboardModule;
use App\Module\Duplicates\DuplicatesModule;
use App\Module\FileActions\FileActionsModule;
use App\Module\Files\ViewerModule;
use App\Module\Jobs\JobsModule;
use App\Module\Profiles\ProfilesModule;
use App\Module\Renaming\RenamingModule;
use App\Module\Reports\ReportsModule;
use App\Module\Search\SearchModule;
use App\Module\System\SystemModule;
use Symfony\Component\HttpFoundation\Request;

function assertNav(bool $yes,string $why):void {
    if(!$yes) throw new RuntimeException($why);
}
// Check plugin section metadata using real modules in deliberately scrambled order.
$registry=new ModuleRegistry([
    new SystemModule(),new ViewerModule(),new FileActionsModule(),
    new SearchModule(),new ProfilesModule(),new DashboardModule(),
    new ReportsModule(),new RenamingModule(),new DuplicatesModule(),
    new JobsModule(),new AccountManagementModule()
]);
$links=$registry->navigation();
$routes=array_column($links,'route');
assertNav(count($routes)===12,'Lost or duplicated a navigation destination');
assertNav(count(array_unique($routes))===12,'Duplicate navigation routes');
assertNav($routes[0]==='app_dashboard','Overview should be the first link');
$groups=array_values(array_unique(array_column($links,'section')));
assertNav($groups===['overview','find','scan','actions','settings'],
    'Sidebar module order does not reflect user tasks');
$admin=true;
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),['autoescape'=>'html']);
$twig->addFunction(new Twig\TwigFunction('dup_navigation',static fn()=>$registry->navigation()));
$twig->addFunction(new Twig\TwigFunction('is_granted',static function(string $role) use (&$admin):bool {
    return $admin;
}));
$twig->addFunction(new Twig\TwigFunction('asset',static fn(string $name)=>'/assets/'.$name));
$twig->addFunction(new Twig\TwigFunction('path',static function(string $name,array $args=[]):string {
    $names=[
        'app_dashboard'=>'/','app_duplicates'=>'/duplicates','app_search'=>'/search',
        'app_profiles'=>'/profiles','app_jobs'=>'/jobs','app_reports'=>'/reports',
        'app_rename'=>'/rename','app_actions'=>'/actions',
        'app_ignore_folders'=>'/settings/ignored-folders',
        'app_action_settings'=>'/settings/actions',
        'app_viewer_settings'=>'/settings/viewer','app_accounts'=>'/accounts',
        'app_system'=>'/system','app_logout'=>'/logout'
    ];
    return $names[$name]??'/invalid-route';
}));
$twig->addFunction(new Twig\TwigFunction('csrf_token',static fn(string $name)=>'token-'.$name));
$user=new class {
    public function getUserIdentifier():string {return 'admin';}
};
$app=['user'=>$user,'request'=>Request::create('/duplicates/6'),
    'environment'=>'dev','flashes'=>[]];
$html=$twig->render('base.html.twig',['app'=>$app]);
$pos=-1;
foreach(['OVERVIEW','FIND FILES','SCAN &amp; MONITOR','FILE OPERATIONS','SETTINGS'] as $heading) {
    $next=strpos($html,$heading,$pos+1);
    assertNav($next!==false && $next>$pos,'Sidebar section absent or out of order: '.$heading);
    $pos=$next;
}
assertNav(str_contains($html,'href="/duplicates" class="nav-link active" aria-current="page"'),
    'Nested duplicate-group pages do not highlight the parent navigation');
assertNav(!str_contains($html,'File exists rules'),'Global File Exists is still exposed in navigation');
$admin=false;
$readOnly=$twig->render('base.html.twig',['app'=>$app]);
foreach(['User accounts','Ignored folders','File actions & restores','Job monitor'] as $private)
    assertNav(!str_contains($readOnly,$private),'Read-only role sees admin navigation: '.$private);
foreach(['Duplicates','Find files','Scan history &amp; reports'] as $allowed)
    assertNav(str_contains($readOnly,$allowed),'Read-only user lost navigation: '.$allowed);
echo "PASS user-task sidebar grouping, stable module order, nested-route selection and role-aware links\n";
