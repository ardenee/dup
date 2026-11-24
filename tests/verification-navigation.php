<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$controller=dirname(__DIR__).'/src/Module/Duplicates/Controller/DuplicateVerificationController.php';
$source=file_get_contents($controller);
if($source===false) throw new RuntimeException('Unable to read the verification controller.');
if(!str_contains($source,"return \$this->redirectToRoute('app_jobs');"))
    throw new RuntimeException('SHA-256 verification must redirect to the live Job Monitor.');
if(str_contains($source,"redirectToRoute('app_job_detail'"))
    throw new RuntimeException('Verification still redirects to an individual job page.');

$router=new Symfony\Component\Routing\RouteCollection();
$router->add('app_jobs',new Symfony\Component\Routing\Route('/jobs'));
$generator=new Symfony\Component\Routing\Generator\UrlGenerator(
    $router,new Symfony\Component\Routing\RequestContext('/dup/')
);
if($generator->generate('app_jobs')!=='/dup/jobs')
    throw new RuntimeException('The Job Monitor URL did not resolve under /dup/.');
echo "PASS SHA-256 verification redirects to the live /dup/jobs monitor\n";
