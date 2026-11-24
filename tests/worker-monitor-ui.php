<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function chkWorkerUi(bool $okay,string $problem):void {
    if(!$okay)throw new RuntimeException($problem);
}
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $params=[])=>'/'.$name,
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$callback)$twig->addFunction(new Twig\TwigFunction($name,$callback));
$basic=[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'is_dsm'=>true,'jobs'=>[],
    'page'=>['rows'=>[],'total'=>0,'next'=>null,'previous'=>null],
    'profiles'=>[],'selected_profile'=>null,'selected_state'=>'','job_filters'=>[]
];
$working=[
    'state'=>'working','active'=>0,'last_heartbeat'=>'2026-11-22 17:26:06',
    'jobs_running'=>1,'jobs_responding'=>1,'jobs_stalled'=>0,
    'queued_jobs'=>0,'recovery_candidates'=>1,
    'last_job_heartbeat'=>'2026-11-24 13:20:00'
];
$html=$twig->render('jobs/index.html.twig',$basic+['worker'=>$working]);
foreach(['Processing jobs','Jobs reporting progress','Last active job update',
    '2026-11-24 13:20:00','Refresh status','Reload job list',
    'data-api-url="/app_jobs_api"','data-worker-refresh',
    'data-worker-recovery','Last process heartbeat','Last checked',
    'On Synology','System health'] as $word) {
    chkWorkerUi(str_contains($html,$word),'Missing worker status feature: '.$word);
}
chkWorkerUi(!str_contains($html,'Not responding'),
    'A running scan is incorrectly marked as not responding.');
chkWorkerUi((bool)preg_match('/<form[^>]*data-worker-recovery[^>]*hidden/s',$html),
    'Recovery control is visible while a recent scan heartbeat exists.');

$stale=$working;
$stale['state']='stalled';$stale['jobs_responding']=0;
$html=$twig->render('jobs/index.html.twig',$basic+['worker'=>$stale]);
chkWorkerUi(str_contains($html,'Recover stale jobs'),
    'Unresponsive jobs do not have a manual recovery option.');
chkWorkerUi(!(bool)preg_match('/<form[^>]*data-worker-recovery[^>]*hidden/s',$html),
    'Recovery control is hidden when the last job is clearly stale.');
$js=file_get_contents(dirname(__DIR__).'/public/assets/jobs.js');
foreach(['updateWorker(payload.worker)','data-worker-refresh','data-worker-recovery',
    'setInterval','response.ok'] as $needle)
    chkWorkerUi(str_contains($js,$needle),'Job monitor is not kept up to date: '.$needle);
$controller=file_get_contents(dirname(__DIR__).'/src/Module/Jobs/Controller/JobsController.php');
foreach(["'worker'=>\$heartbeat->report()","name:'app_jobs_recover'",
    "name:'app_job_retry'","name:'app_jobs_api'"] as $needle)
    chkWorkerUi(str_contains($controller,$needle),
        'Worker routes or API status do not support the UI: '.$needle);
echo "PASS active scan status, manual refresh, protected recovery, retry, worker and job heartbeat display with live updates\n";
