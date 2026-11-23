<?php
declare(strict_types=1);
namespace App\Module\Jobs\Controller;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
final class JobsController extends AbstractController
{
    #[Route('/jobs',name:'app_jobs',methods:['GET'])]
    public function index(Connection $db): Response
    {
        return $this->render('jobs/index.html.twig',[
            'jobs'=>$db->fetchAllAssociative('SELECT id,kind,phase,state,progress_current,progress_total,created_at,heartbeat_at,error_code FROM jobs ORDER BY id DESC LIMIT 100')
        ]);
    }

    #[Route('/api/jobs',name:'app_jobs_api',methods:['GET'])]
    public function status(Connection $db): JsonResponse
    {
        return new JsonResponse([
            'jobs'=>$db->fetchAllAssociative('SELECT id,kind,phase,state,progress_current,progress_total,heartbeat_at,error_code FROM jobs ORDER BY id DESC LIMIT 50')
        ],200,['Cache-Control'=>'no-store']);
    }
}
