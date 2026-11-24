<?php
declare(strict_types=1);
namespace App\Module\Jobs\Controller;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\WorkerMaintenance;
use App\Module\Jobs\Service\WorkerHeartbeat;
use App\Module\Jobs\Service\JobHistoryCleanup;
use App\Module\Jobs\Service\WorkerLogReader;
use App\Module\Accounts\Domain\Account;
use App\Module\Jobs\Repository\JobHistoryRepository;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class JobsController extends AbstractController
{
    private function positive(Request $request,string $key):?int
    {
        $raw=$request->query->get($key);
        if($raw===null || $raw==='') return null;
        $value=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($value===false) throw $this->createNotFoundException('Invalid job filter.');
        return $value;
    }

    private const STATES=['queued','running','cancelling','completed','failed','cancelled'];

    #[Route('/jobs',name:'app_jobs',methods:['GET'])]
    public function index(
        Request $request,JobHistoryRepository $history,
        WorkerHeartbeat $heartbeat
    ): Response
    {
        $profile=$this->positive($request,'profile');
        $before=$this->positive($request,'before');
        $after=$this->positive($request,'after');
        if($before!==null && $after!==null)
            throw $this->createNotFoundException('Invalid job paging.');
        $state=(string)$request->query->get('state','');
        if($state!=='' && !in_array($state,self::STATES,true))
            throw $this->createNotFoundException('Unknown job state.');
        $kind=(string)$request->query->get('kind','');
        $kinds=['scan','rebuild-duplicates','duplicate-verify','file-action','file-restore',
            'file-rename','file-move','file-move-restore','file-link','file-unlink',
            'bulk-link','bulk-quarantine','bulk-restore','bulk-unlink','file-delete'];
        if($kind!=='' && !in_array($kind,$kinds,true))
            throw $this->createNotFoundException('Invalid job type.');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','desc');
        if(!isset(JobHistoryRepository::SORTS[$sort]) || !in_array($dir,['asc','desc'],true))
            throw $this->createNotFoundException('Invalid job sorting.');
        $profiles=$history->profiles();
        if($profile!==null && !in_array($profile,
            array_map(static fn(array $p):int=>(int)$p['id'],$profiles),true))
            throw $this->createNotFoundException('Unknown profile.');
        $page=$history->page($profile,$state?:null,$before,$after,$sort,$dir,$kind?:null);
        return $this->render('jobs/index.html.twig',['stale_cutoff'=>gmdate('Y-m-d H:i:s',time()-600),
            'is_dsm'=>\App\Core\Setup\HostEnvironment::isSynology(dirname(__DIR__,4)),
            'worker'=>$heartbeat->report(),
            'jobs'=>$page['rows'],'page'=>$page,
            'profiles'=>$profiles,'selected_profile'=>$profile,
            'selected_state'=>$state,'selected_kind'=>$kind,'job_kinds'=>$kinds,'sort'=>$sort,'dir'=>$dir,
            'job_filters'=>array_filter(['profile'=>$profile,'state'=>$state,'kind'=>$kind,
                'sort'=>$sort,'dir'=>$dir],static fn($value):bool=>$value!==null && $value!=='')
        ]);
    }

    #[Route('/jobs/{id<\\d+>}',name:'app_job_detail',methods:['GET'])]
    public function detail(int $id,Request $request,JobHistoryRepository $history,JobHistoryCleanup $cleanup,
        WorkerHeartbeat $heartbeat,WorkerMaintenance $maintenance):Response
    {
        $before=$this->positive($request,'before');
        $after=$this->positive($request,'after');
        if($before!==null && $after!==null)
            throw $this->createNotFoundException('Invalid event paging.');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','desc');
        $severity=(string)$request->query->get('severity','');
        if(!isset(JobHistoryRepository::EVENT_SORTS[$sort]) ||
           !in_array($dir,['asc','desc'],true) ||
           !in_array($severity,['','info','warning','error'],true))
            throw $this->createNotFoundException('Invalid event sorting or filter.');
        $details=$history->detail($id,$before,$after,$sort,$dir,$severity);
        if(!$details) throw $this->createNotFoundException('Job does not exist.');
        return $this->render('jobs/detail.html.twig',$details+[
            'cleanup'=>$cleanup->inspect($id),'event_sort'=>$sort,'event_dir'=>$dir,
            'is_dsm'=>\App\Core\Setup\HostEnvironment::isSynology(dirname(__DIR__,4)),
            'stale_cutoff'=>gmdate('Y-m-d H:i:s',time()-600),
            'worker_status'=>$heartbeat->report(),
            'job_owner_active'=>$maintenance->ownerActive($id),
            'event_severity'=>$severity,'event_filters'=>array_filter([
                'id'=>$id,'sort'=>$sort,'dir'=>$dir,'severity'=>$severity
            ],static fn($v)=>$v!=='')
        ]);
    }


    #[Route('/jobs/worker-log',name:'app_worker_log',methods:['GET'])]
    public function workerLog(WorkerLogReader $logs,WorkerHeartbeat $heartbeat):Response
    {
        return $this->render('jobs/worker-log.html.twig',[
            'log'=>$logs->latest(),'worker'=>$heartbeat->report()
        ],new Response(status:200,headers:['Cache-Control'=>'private, no-store']));
    }

    #[Route('/jobs/cleanup',name:'app_job_cleanup',methods:['GET','POST'])]
    public function cleanup(Request $request,JobHistoryCleanup $cleanup):Response
    {
        $raw=$request->isMethod('POST')
            ?$request->request->get('days','90'):$request->query->get('days','90');
        $days=filter_var($raw,FILTER_VALIDATE_INT);
        if($days===false || !in_array($days,JobHistoryCleanup::DAYS,true))
            throw $this->createNotFoundException('Invalid job retention period.');
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('prune_job_history',
                (string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid cleanup token.');
            $actor=$this->getUser();
            if(!$actor instanceof Account) throw $this->createAccessDeniedException();
            try {
                $count=$cleanup->prune($days,$actor->id,
                    (string)$request->request->get('confirm',''));
                $this->addFlash('success',$count.' old job logs removed. Scan results and audit records retained.');
            } catch(\DomainException $ex) {
                $this->addFlash('warning',$ex->getMessage());
            }
            return $this->redirectToRoute('app_job_cleanup',['days'=>$days]);
        }
        return $this->render('jobs/cleanup.html.twig',[
            'days'=>$days,'eligible'=>$cleanup->estimate($days)
        ]);
    }

    #[Route('/jobs/{id<\\d+>}/remove',name:'app_job_remove',methods:['POST'])]
    public function remove(int $id,Request $request,JobHistoryCleanup $cleanup):Response
    {
        if(!$this->isCsrfTokenValid('remove_job_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid remove-job token.');
        $actor=$this->getUser();
        if(!$actor instanceof Account) throw $this->createAccessDeniedException();
        try {
            $cleanup->remove($id,$actor->id,(string)$request->request->get('confirm',''));
            $this->addFlash('success','Job #'.$id.' and its events removed. Scan results remain available.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_job_detail',['id'=>$id]);
        }
    }

    #[Route('/jobs/recover',name:'app_jobs_recover',methods:['POST'])]
    public function recover(Request $request,WorkerMaintenance $maintenance):Response
    {
        if(!$this->isCsrfTokenValid('recover_stalled_jobs',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid recovery token.');
        if($request->request->get('confirm')!=='RECOVER')
            throw $this->createAccessDeniedException('Explicit recovery confirmation required.');
        $actor=$this->getUser();
        if(!$actor instanceof Account) throw $this->createAccessDeniedException();
        try {
            $count=$maintenance->recover($actor->id);
            $this->addFlash($count>0?'success':'warning',
                $count.' stale job(s) marked failed. No files or scan results were removed. '.
                'You can now retry eligible failed scans.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_jobs');
    }

    #[Route('/jobs/{id<\d+>}/resolve',name:'app_job_resolve',methods:['POST'])]
    public function resolve(int $id,Request $request,WorkerMaintenance $maintenance):Response
    {
        if(!$this->isCsrfTokenValid('resolve_stale_job_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid resolve-job token.');
        if($request->request->get('confirm')!=='RESOLVE')
            throw $this->createAccessDeniedException('Explicit confirmation required.');
        $actor=$this->getUser();
        if(!$actor instanceof Account)throw $this->createAccessDeniedException();
        try {
            $state=$maintenance->resolveOne($id,$actor->id);
            $this->addFlash('success','Job #'.$id.' has been marked '.$state.
                '. Its worker lease is released. Original scan results and diagnostic events were retained.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_job_detail',['id'=>$id]);
    }

    #[Route('/jobs/{id<\d+>}/retry',name:'app_job_retry',methods:['POST'])]
    public function retry(int $id,Request $request,WorkerMaintenance $maintenance):Response
    {
        if(!$this->isCsrfTokenValid('retry_job_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid retry token.');
        $actor=$this->getUser();
        if(!$actor instanceof Account) throw $this->createAccessDeniedException();
        try {
            $newId=$maintenance->retry($id,$actor->id);
            $this->addFlash('success',
                'New job #'.$newId.' queued. The original failed job remains in history.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_jobs');
    }

    #[Route('/api/jobs',name:'app_jobs_api',methods:['GET'])]
    public function status(Connection $db,WorkerHeartbeat $heartbeat): JsonResponse
    {
        return new JsonResponse([
            'jobs'=>$db->fetchAllAssociative(
                'SELECT id,kind,phase,state,progress_current,progress_total,' .
                'created_at,started_at,finished_at,heartbeat_at,error_code,' .
                'progress_bytes_current,progress_bytes_total,progress_detail,'.
                'last_progress_at,last_failure_reason ' .
                'FROM jobs ORDER BY id DESC LIMIT 100'
            ),
            'worker'=>$heartbeat->report()
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
        return $request->request->get('return_to')==='detail'
            ?$this->redirectToRoute('app_job_detail',['id'=>$id])
            :$this->redirectToRoute('app_jobs');
    }
}
