<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Repository\JobHistoryRepository;
use Doctrine\DBAL\DriverManager;

function assertJobs(bool $yes,string $why):void {
    if(!$yes)throw new RuntimeException($why);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
foreach([
    'CREATE TABLE scan_profiles(id INT PRIMARY KEY,name TEXT)',
    'CREATE TABLE scan_runs(id INT PRIMARY KEY,state TEXT)',
    'CREATE TABLE jobs(id INT PRIMARY KEY,kind TEXT,profile_id INT,run_id INT,file_action_batch_id INT,'.
        'state TEXT,phase TEXT,progress_current INT,progress_total INT,created_at TEXT,history_prunable_at TEXT,'.
        'started_at TEXT,finished_at TEXT,heartbeat_at TEXT,error_code TEXT,attempts INT)',
    'CREATE TABLE job_events(id INT PRIMARY KEY,job_id INT,severity TEXT,code TEXT,context TEXT,created_at TEXT)'
] as $sql)$db->executeStatement($sql);
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_current BIGINT NOT NULL DEFAULT 0');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_bytes_total BIGINT DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN progress_detail VARCHAR(255) DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN last_progress_at DATETIME DEFAULT NULL');
$db->executeStatement('ALTER TABLE jobs ADD COLUMN last_failure_reason TEXT DEFAULT NULL');
$db->insert('scan_profiles',['id'=>1,'name'=>'Audio']);
$db->insert('scan_profiles',['id'=>2,'name'=>'Archive']);
$db->insert('scan_runs',['id'=>44,'state'=>'archived']);
for($id=1;$id<=125;$id++) {
    $db->insert('jobs',[
        'id'=>$id,'kind'=>$id%2?'scan':'file-action','profile_id'=>$id%2?1:2,
        'run_id'=>44,'file_action_batch_id'=>$id%2?null:$id,
        'state'=>$id%3===0?'failed':'completed','phase'=>'completed',
        'progress_current'=>$id,'progress_total'=>$id,
        'created_at'=>'2026-11-24 01:00:00','started_at'=>'2026-11-24 01:01:00',
        'finished_at'=>'2026-11-24 01:02:00','heartbeat_at'=>'2026-11-24 01:02:00',
        'error_code'=>$id%3===0?'WORKER_STOPPED':null,'attempts'=>1
    ]);
}
for($event=1;$event<=125;$event++) {
    $db->insert('job_events',[
        'id'=>$event,'job_id'=>125,'severity'=>'warning',
        'code'=>'FILE_CHECK','context'=>json_encode([
            'reason'=>'Injected <script>alert(1)</script>','file_entry_id'=>$event
        ]),
        'created_at'=>'2026-11-24 02:00:00'
    ]);
}
$repo=new JobHistoryRepository($db);
$page=$repo->page(null,null,null,null);
assertJobs($page['total']===125 && count($page['rows'])===50,'First job page size/total incorrect');
assertJobs((int)$page['rows'][0]['id']===125 && (int)$page['rows'][49]['id']===76,
    'Jobs are not newest first');
assertJobs($page['previous']===null && $page['next']===76,'Job paging buttons incorrect');
$older=$repo->page(null,null,76,null);
assertJobs((int)$older['rows'][0]['id']===75 && (int)$older['rows'][49]['id']===26,
    'Older job page incorrect');
assertJobs($older['previous']===75 && $older['next']===26,
    'Backward/forward job navigation incorrect');
$newer=$repo->page(null,null,null,26);
assertJobs((int)$newer['rows'][0]['id']===76 && (int)$newer['rows'][49]['id']===27,
    'Returning toward newer jobs failed');
$filtered=$repo->page(1,'failed',null,null);
assertJobs($filtered['total']===21,'Profile+state filter returned incorrect total');
foreach($filtered['rows'] as $row)
    assertJobs((int)$row['profile_id']===1 && $row['state']==='failed',
        'The job filter included a mismatched entry.');
$details=$repo->detail(125);
assertJobs($details!==false && count($details['events'])===100,
    'Job events are not bounded and paged');
assertJobs($details['next']===26,'Job event paging missed older events');
assertJobs($details['events'][0]['fields']['file_entry_id']===125,
    'Event context was not decoded');
$olderEvents=$repo->detail(125,$details['next'],null);
assertJobs(count($olderEvents['events'])===25 &&
    (int)$olderEvents['events'][0]['id']===25,'Old job events are inaccessible');
assertJobs($repo->detail(500)===false,'Unknown job should be rejected');

$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $args=[])=>'/'.$name.($args?'?'.http_build_query($args):''),
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'csrf_token'=>static fn(string $name)=>'token-'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true
] as $name=>$func)$twig->addFunction(new Twig\TwigFunction($name,$func));
$app=['app'=>['user'=>null,'environment'=>'test','flashes'=>[]]];
$html=$twig->render('jobs/index.html.twig',$app+[
    'is_dsm'=>false,
    'worker'=>['active'=>0,'last_heartbeat'=>null],
    'jobs'=>$page['rows'],'page'=>$page,
    'profiles'=>$repo->profiles(),'selected_profile'=>null,
    'selected_state'=>'','job_filters'=>[]
]);
foreach(['Job history','job-profile','job-state','Older jobs','Job details and events',
    'Scan duplicate results','50 jobs on this page','125 matching jobs'] as $needle)
    assertJobs(str_contains($html,$needle),'Job monitor UI missing '.$needle);
assertJobs(!str_contains($html,'/app_job_events'),'Raw events JSON remains primary workflow');
$detailHtml=$twig->render('jobs/detail.html.twig',$app+$details);
assertJobs(str_contains($detailHtml,'Job events and errors'),
    'Event log missing in job details.');
foreach(['Job #125','Job events and errors','Next page','View duplicate results',
    'Scan report #44'] as $needle)
    assertJobs(str_contains($detailHtml,$needle),'Job detail missing '.$needle);
assertJobs(!str_contains($detailHtml,'<script>alert(1)</script>'),
    'Event context was not escaped');
assertJobs(str_contains($detailHtml,'&lt;script&gt;'),
    'Escaped event context was not shown');
$runningScan=$details;
$runningScan['job']['kind']='scan';
$runningScan['job']['state']='running';
assertJobs(str_contains($twig->render('jobs/detail.html.twig',$app+$runningScan),'Cancel job'),
    'A running scan cannot be cancelled from its detail page');
$runningAction=$details;
$runningAction['job']['kind']='file-move';
$runningAction['job']['state']='running';
$runningDetail=$twig->render('jobs/detail.html.twig',$app+$runningAction);
assertJobs(!str_contains($runningDetail,'Cancel job') &&
    str_contains($runningDetail,'cannot be interrupted'),
    'Unsafe cancellation displayed during file operation');
$byType=$repo->page(null,null,null,null,'type','asc');
$sortValues=array_column($byType['rows'],'kind');
$sortedValues=$sortValues;sort($sortedValues);
assertJobs($sortValues===$sortedValues,'Job type sorting did not cover the full results.');
$onlyScan=$repo->page(null,null,null,null,'id','desc','scan');
assertJobs($onlyScan['total']===63 && count($onlyScan['rows'])===50,
    'Job type filter failed to preserve total across pages.');
$moreScans=$repo->page(null,null,$onlyScan['next'],null,'id','desc','scan');
assertJobs($moreScans['total']===63 && count($moreScans['rows'])===13 &&
    $moreScans['previous']!==null,'Filtered job cursor did not preserve type.');
echo "PASS 125 jobs, profile/state filters, keyset pagination, 125 readable events, links, escaping and safe cancellation\n";
