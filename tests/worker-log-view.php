<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\WorkerLogReader;

function testLog(bool $okay,string $why):void
{
    if(!$okay)throw new RuntimeException($why);
}
$reader=new WorkerLogReader();
$view=$reader->latest();
testLog(isset($view['available'],$view['path']) &&
    $view['path']===WorkerLogReader::DSM_PATH,
    'Worker log reader did not use a fixed admin-only path.');
testLog(!str_contains(file_get_contents(dirname(__DIR__).
    '/src/Module/Jobs/Service/WorkerLogReader.php'),'$_GET'),
    'Worker log path is controlled by client query values.');

$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $params=[])=>'/'.$name,
    'asset'=>static fn(string $name)=>'/assets/'.$name,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true,
    'csrf_token'=>static fn()=>''
] as $name=>$function)
    $twig->addFunction(new Twig\TwigFunction($name,$function));
$html=$twig->render('jobs/worker-log.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'log'=>[
        'available'=>true,'path'=>WorkerLogReader::DSM_PATH,
        'text'=>"<script>alert('bad')</script>\n".
            'DUP worker: PHP worker PID 4321 exited (status 137)',
        'last_updated'=>'2026-11-24 16:00:00','truncated'=>false
    ],
    'worker'=>['state'=>'stalled','active'=>0,
        'jobs_responding'=>0,'jobs_running'=>1]
]);
testLog(str_contains($html,'Worker diagnostics') &&
    str_contains($html,'worker.log') &&
    str_contains($html,'status 137') &&
    str_contains($html,'Refresh log'),
    'Diagnostic template does not expose the meaningful worker information.');
testLog(str_contains($html,'&lt;script&gt;') &&
    !str_contains($html,'<script>alert'),
    'Process log was displayed as executable HTML.');
$missing=$twig->render('jobs/worker-log.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'log'=>[
        'available'=>false,'path'=>WorkerLogReader::DSM_PATH,
        'text'=>'','reason'=>'Log cannot be opened by Web Station'
    ],
    'worker'=>['state'=>'not_reporting','active'=>0]
]);
testLog(str_contains($missing,'Log cannot be opened by Web Station'),
    'Missing/denied DSM log path has no diagnostic guidance.');
$security=file_get_contents(dirname(__DIR__).'/config/packages/security.yaml');
testLog(str_contains($security,"path: '^/', roles: ROLE_ADMIN"),
    'Worker diagnostics have no administrator access boundary.');
$controller=file_get_contents(dirname(__DIR__).
    '/src/Module/Jobs/Controller/JobsController.php');
testLog(str_contains($controller,"name:'app_worker_log'") &&
    str_contains($controller,"methods:['GET']"),
    'Worker log endpoint is not an authenticated read-only page.');
echo "PASS read-only admin worker logs, bounded path, escaped fatal output, missing-log guidance and route\n";
