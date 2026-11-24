<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use App\Module\Jobs\Service\WorkerFailureCode;
use App\Module\Jobs\Service\JobFailureCode;
use App\Module\Duplicates\Service\VerificationFailureCode;

function checkCatalog(bool $condition,string $message):void {
    if(!$condition)throw new RuntimeException($message);
}
$codes=VerificationFailureCode::catalog()+WorkerFailureCode::catalog()+JobFailureCode::catalog();
checkCatalog(count($codes)===430 &&
    count($codes)===count(array_unique(array_keys($codes))),
    'Combined 430-entry error registry must contain no duplicate codes.');
$twig=new Twig\Environment(
    new Twig\Loader\FilesystemLoader(dirname(__DIR__).'/templates'),
    ['autoescape'=>'html']
);
foreach([
    'path'=>static fn(string $name,array $query=[])=>'/'.$name.
        ($query?'?'.http_build_query($query):''),
    'asset'=>static fn(string $path)=>'/assets/'.$path,
    'dup_navigation'=>static fn()=>[],
    'is_granted'=>static fn()=>true,
    'csrf_token'=>static fn()=>''
] as $name=>$func)$twig->addFunction(new Twig\TwigFunction($name,$func));
$html=$twig->render('jobs/error-codes.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'search'=>'DUP-HASH-105',
    'matches'=>['DUP-HASH-105'=>$codes['DUP-HASH-105']],
    'catalog_total'=>count($codes)
]);
checkCatalog(str_contains($html,'Error code reference') &&
    str_contains($html,'DUP-HASH-105') &&
    str_contains($html,'Candidate size changed') &&
    str_contains($html,'430 registered error conditions') &&
    str_contains($html,'trace ID'),
    'Error-code reference does not explain the code/trace distinction.');
$none=$twig->render('jobs/error-codes.html.twig',[
    'app'=>['user'=>null,'environment'=>'test','flashes'=>[]],
    'search'=>'DUP-INVALID-999','matches'=>[],
    'catalog_total'=>count($codes)
]);
checkCatalog(str_contains($none,'No registered diagnostic code matches'),
    'Unrecognized/legacy codes do not get guidance.');
$controller=file_get_contents(dirname(__DIR__).
    '/src/Module/Jobs/Controller/JobsController.php');
checkCatalog(str_contains($controller,"name:'app_job_error_codes'") &&
    str_contains($controller,'WorkerFailureCode::catalog()') &&
    str_contains($controller,'VerificationFailureCode::catalog()') &&
    str_contains($controller,'JobFailureCode::catalog()'),
    'Error-code dictionary controller is incomplete.');
$jobDetail=file_get_contents(dirname(__DIR__).'/templates/jobs/detail.html.twig');
checkCatalog(str_contains($jobDetail,'app_job_error_codes') &&
    str_contains($jobDetail,'job.error_code'),
    'Job detail error codes are not clickable to their reference.');
echo "PASS 430 unique diagnostic codes, searchable admin reference, safe unknown-code view and linked job details\n";
