<?php
declare(strict_types=1);
namespace App\Module\System\Controller;

use App\Module\System\Service\HealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SystemController extends AbstractController
{
    #[Route('/health',name:'app_health',methods:['GET'])]
    public function health(HealthService $health): JsonResponse
    {
        $report=$health->report();
        // Public health check intentionally excludes paths, versions and diagnostics.
        return new JsonResponse(['status'=>$report['status']],$report['ready']?200:503);
    }

    #[Route('/api/system/status',name:'app_system_status',methods:['GET'])]
    public function details(HealthService $health): JsonResponse
    {
        return new JsonResponse($health->report(),200,[
            'Cache-Control'=>'private, no-store, max-age=0',
            'X-Content-Type-Options'=>'nosniff'
        ]);
    }

    #[Route('/system',name:'app_system',methods:['GET'])]
    public function index(HealthService $health): Response
    {
        return $this->render('system/index.html.twig',['health'=>$health->report()]);
    }
}
