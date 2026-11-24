<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\JobDebugLog;

function checkProbe(bool $yes,string $message):void
{
    if(!$yes)throw new RuntimeException($message);
}

$directory=sys_get_temp_dir().DIRECTORY_SEPARATOR.'dup-debug-probe-'.bin2hex(random_bytes(8));
$logger=new JobDebugLog($directory);
try {
    $status=$logger->status();
    checkProbe($status['status']==='not_created',
        'Uncreated debug directory should report not_created.');
    $result=$logger->probe();
    checkProbe($result['success']===true && $result['written']===true &&
        $result['read_back']===true && $result['error']===null,
        'Web debug write/read probe did not confirm a round trip.');
    checkProbe(is_file($logger->path(0)) &&
        str_contains((string)$logger->export(0),'diagnostics.web_write_probe'),
        'Probe was not stored in the requested private directory.');
    $logger->write(18,'debug','verification.db.start',['query'=>'candidate_count']);
    $logger->write(19,'debug','verification.db.start',['query'=>'candidate_page']);
    $recent=$logger->recentJobs();
    checkProbe(count($recent)===2 &&
        array_column($recent,'id')===[19,18],
        'Recent per-job debug log list should work without DB access.');
    $logger->purge(18);
    $logger->purge(19);
    checkProbe($logger->status()['status']==='ready',
        'Successful write should produce ready storage state.');

    $blocked=$directory.DIRECTORY_SEPARATOR.'cannot-create-directory';
    file_put_contents($blocked,'file blocks directory');
    $failedLogger=new JobDebugLog($blocked);
    $failed=$failedLogger->probe();
    checkProbe($failed['success']===false && !$failed['written'] &&
        !$failed['read_back'] && is_string($failed['error']) &&
        str_contains($failed['error'],'Could not create private diagnostic directory'),
        'Blocked log path was falsely reported as successful or gave no reason.');
    checkProbe($failedLogger->lastWriteError()!==null,
        'Diagnostic write failure was silently ignored.');

    $worker=file_get_contents(dirname(__DIR__).
        '/src/Module/Jobs/Command/WorkJobsCommand.php');
    checkProbe(str_contains($worker,'$bootLogWritable') &&
        str_contains($worker,'DUP CLI DEBUG LOG UNAVAILABLE') &&
        str_contains($worker,'Worker refuses to claim jobs without independent diagnostics.'),
        'Worker continues to claim jobs even when its logger is broken.');

    $controller=file_get_contents(dirname(__DIR__).
        '/src/Module/Jobs/Controller/JobsController.php');
    checkProbe(str_contains($controller,"name:'app_job_debug_test'") &&
        str_contains($controller,"methods:['POST']") &&
        str_contains($controller,'$debug->probe()') &&
        str_contains($controller,"isCsrfTokenValid('test_job_debug'"),
        'Admin debug probe route is missing authorization or write verification.');

    $twig=new Twig\Environment(
        new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
        ['autoescape'=>'html']
    );
    foreach([
        'path'=>static fn(string $name,array $args=[])=>'/'.$name,
        'asset'=>static fn(string $name)=>'/assets/'.$name,
        'csrf_token'=>static fn(string $name)=>'csrf-'.$name,
        'dup_navigation'=>static fn()=>[],
        'is_granted'=>static fn()=>true
    ] as $name=>$fn)$twig->addFunction(new Twig\TwigFunction($name,$fn));
    $html=$twig->render('jobs/worker-log.html.twig',[
        'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
        'selected_job'=>0,
        'storage'=>$logger->status(),
        'debug'=>$logger->read(0),
        'log'=>['available'=>false,'path'=>'/var/packages/dup/var/worker.log',
            'reason'=>'Unavailable'],
        'supervisor_log'=>['available'=>false,
            'path'=>'/var/packages/dup/var/runtime/log/dup-jobs/supervisor.log',
            'reason'=>'Unavailable']
    ]);
    checkProbe(str_contains($html,'Test debug logging now') &&
        str_contains($html,'csrf-test_job_debug') &&
        str_contains($html,'/app_job_debug_test') &&
        str_contains($html,'Web Station account') &&
        str_contains($html,'worker.boot'),
        'Web UI does not clearly explain and expose the diagnostic self-test.');
    echo "PASS web debug write/read self-test, failure explanations, strict worker boot, CSRF and clear CLI/web distinction\n";
} finally {
    $logger->purge(0);
    if(is_file($directory.'/cannot-create-directory'))@unlink($directory.'/cannot-create-directory');
    @rmdir($directory);
}
