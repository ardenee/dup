<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobDebugLog;
use Symfony\Component\HttpFoundation\Request;

function checkDiagnostics(bool $yes,string $message):void {
    if(!$yes)throw new RuntimeException($message);
}
$logger=new JobDebugLog();
$state=$logger->status();
foreach(['directory','directory_exists','directory_readable','worker_log_exists',
    'worker_log_readable','worker_log_updated','web_user',
    'open_basedir','status','help'] as $name) {
    checkDiagnostics(array_key_exists($name,$state),'Missing diagnostic storage field: '.$name);
}
checkDiagnostics(in_array($state['status'],[
    'ready','not_created','permission_denied','awaiting_worker_log','log_permission_denied'
],true),'Diagnostic status should provide an actionable distinction.');
checkDiagnostics(str_ends_with($state['directory'],'/var/log/dup-jobs'),
    'Diagnostic storage points outside the intended private runtime directory.');
$twig=new Twig\Environment(new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.
        ($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
$html=$twig->render('jobs/worker-log.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[],
        'request'=>Request::create('/jobs/worker-log')],
    'storage'=>$state,
    'selected_job'=>0,
    'debug'=>['available'=>false,'path'=>$logger->path(0),'rows'=>[],
        'message'=>'No log yet'],
    'log'=>['available'=>false,'path'=>'/var/packages/dup/var/worker.log',
        'reason'=>'No old log'],
    'supervisor_log'=>['available'=>false,'path'=>'/var/packages/dup/var/runtime/log/dup-jobs/supervisor.log',
        'reason'=>'No supervisor log']
]);
foreach(['Diagnostic storage check','Web PHP account','Worker lifecycle log exists / readable',
    'Diagnostic directory','PHP open_basedir','No log yet'] as $needle)
    checkDiagnostics(str_contains($html,$needle),
        'Storage diagnosis was missing from the view: '.$needle);
checkDiagnostics(str_contains($html,htmlspecialchars($state['directory'],ENT_QUOTES)),
    'Diagnostic path incorrectly displayed.');
$source=file_get_contents(dirname(__DIR__).
    '/src/Module/Jobs/Controller/JobsController.php');
$start=strpos($source,'public function workerLog(');
$end=strpos($source,'#[Route(', $start);
checkDiagnostics($start!==false && $end!==false &&
    str_contains(substr($source,$start,$end-$start),"'storage'=>".'$debug->status()'),
    'The admin diagnostics route is not surfacing file permission checks.');
echo "PASS diagnostic storage status, actionable filesystem permissions, DB-free view and admin log troubleshooting\n";
