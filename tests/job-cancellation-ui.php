<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

function checkUi(bool $valid,string $why):void
{
    if(!$valid)throw new RuntimeException($why);
}
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $id)=>'csrf-'.$id,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));

$job=[
    'id'=>8,'kind'=>'duplicate-verify','phase'=>'hashing_duplicates',
    'state'=>'cancelling','profile_name'=>'Movies','run_id'=>4,
    'run_state'=>'completed','file_action_batch_id'=>null,'active_profile_id'=>1,
    'history_prunable_at'=>null,'error_code'=>null,
    'progress_current'=>0,'progress_total'=>26,
    'progress_bytes_current'=>0,'progress_bytes_total'=>2512555861,
    'progress_detail'=>'Preparing SHA-256 verification',
    'heartbeat_at'=>'2026-11-22 18:24:02',
    'created_at'=>'2026-11-22 18:00:00',
    'started_at'=>'2026-11-22 18:00:01',
    'finished_at'=>null,'last_failure_reason'=>null
];
$base=[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'is_dsm'=>true,'page'=>['total'=>1,'previous'=>null,'next'=>null],
    'profiles'=>[],'selected_profile'=>null,'selected_state'=>'',
    'job_filters'=>[],'sort'=>'id','dir'=>'desc','stale_cutoff'=>'2026-11-24 15:00:00',
    'worker'=>['state'=>'stalled','active'=>0,'jobs_responding'=>0,
        'last_heartbeat'=>null,'recovery_candidates'=>1]
];
$html=$twig->render('jobs/index.html.twig',$base+['jobs'=>[$job]]);
foreach(['data-job-resolve','/app_job_resolve?id=8',
    'Finish stalled cancellation #8','csrf-resolve_stale_job_8',
    'Cancellation requested','data-rendered-state="cancelling"'] as $needle)
    checkUi(str_contains($html,$needle),'Stalled cancellation lacks direct action: '.$needle);
checkUi(!str_contains($html,'Recovery history protected') &&
    !str_contains($html,'/app_job_remove?id=8'),
    'An unfinished job should not offer deletion or generic recovery-protected text.');

$working=$base;
$working['worker']['active']=1;
$html=$twig->render('jobs/index.html.twig',$working+['jobs'=>[$job]]);
checkUi(str_contains($html,'action="/app_job_resolve?id=8"'),
    'An unrelated active worker should not hide a stalled cancellation.');

$finished=$job;
$finished['state']='cancelled';
$finished['active_profile_id']=null;
$finished['finished_at']='2026-11-24 15:00:00';
$html=$twig->render('jobs/index.html.twig',$base+['jobs'=>[$finished]]);
foreach(['Delete job #8 and its event log','/app_job_remove?id=8',
    '/app_job_retry?id=8','csrf-remove_job_8'] as $needle)
    checkUi(str_contains($html,$needle),
        'Resolved verification lacks retry/delete action: '.$needle);
checkUi(!str_contains($html,'data-job-resolve'),
    'Finished job still offers stale cancellation.');

$running=$job;$running['state']='running';
$running['heartbeat_at']='2026-11-24 15:01:00';
$html=$twig->render('jobs/index.html.twig',$base+['jobs'=>[$running]]);
checkUi(str_contains($html,'Request cancellation of job #8') &&
    str_contains($html,'/app_job_cancel?id=8'),
    'Running verification cannot be cancelled from the job table.');

$action=$running;$action['kind']='file-delete';$action['file_action_batch_id']=5;
$html=$twig->render('jobs/index.html.twig',$base+['jobs'=>[$action]]);
checkUi(!str_contains($html,'/app_job_cancel?id=8') &&
    !str_contains($html,'/app_job_resolve?id=8') &&
    !str_contains($html,'/app_job_remove?id=8'),
    'Running destructive file operation got inappropriate cancel/resolve/delete controls.');

$js=file_get_contents(dirname(__DIR__).'/public/assets/jobs.js');
checkUi(str_contains($js,'window.location.reload()') &&
    str_contains($js,'row.dataset.renderedState'),
    'Live job status changes do not refresh newly available action icons.');
echo "PASS Job Monitor direct cancellation, stalled resolution, retry/delete, live action refresh, active-worker and file-action guards\n";
