<?php
declare(strict_types=1);
namespace App\Module\Jobs\Controller;

use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JobsController extends AbstractController
{
    #[Route('/jobs',name:'app_jobs',methods:['GET'])]
    public function index(Connection $db): Response
    {
        return $this->render('jobs/index.html.twig',[
            'jobs'=>$db->fetchAllAssociative(
                'SELECT j.id,j.kind,j.phase,j.state,j.progress_current,j.progress_total,' .
                'j.created_at,j.started_at,j.finished_at,j.heartbeat_at,j.error_code,' .
                'p.name AS profile_name,j.run_id ' .
                'FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id ' .
                'ORDER BY j.id DESC LIMIT 100'
            )
        ]);
    }

    #[Route('/api/jobs',name:'app_jobs_api',methods:['GET'])]
    public function status(Connection $db): JsonResponse
    {
        return new JsonResponse([
            'jobs'=>$db->fetchAllAssociative(
                'SELECT id,kind,phase,state,progress_current,progress_total,' .
                'created_at,started_at,finished_at,heartbeat_at,error_code ' .
                'FROM jobs ORDER BY id DESC LIMIT 100'
            )
        ],200,['Cache-Control'=>'no-store']);
    }

    #[Route('/api/jobs/{id<\\d+>}/events',name:'app_job_events',methods:['GET'])]
    public function events(int $id,Connection $db): JsonResponse
    {
        if(!$db->fetchOne('SELECT id FROM jobs WHERE id=?',[$id]))
            throw $this->createNotFoundException('Job does not exist.');
        return new JsonResponse([
            'events'=>$db->fetchAllAssociative(
                'SELECT severity,code,context,created_at FROM job_events ' .
                'WHERE job_id=? ORDER BY id DESC LIMIT 100',[$id]
            )
        ],200,['Cache-Control'=>'no-store']);
    }

    #[Route('/jobs/{id<\\d+>}/cancel',name:'app_job_cancel',methods:['POST'])]
    public function cancel(int $id,Request $request,JobStore $store): Response
    {
        if(!$this->isCsrfTokenValid('cancel_job_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $changed=$store->requestCancel($id);
        $this->addFlash($changed?'success':'warning',$changed?
            'Cancellation requested. Running scans stop at a safe checkpoint.':
            'Cancellation is unavailable: the job has finished, is missing, or a file action is already executing.');
        return $this->redirectToRoute('app_jobs');
    }
}
