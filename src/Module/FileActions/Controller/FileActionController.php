<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\FileActionService;
use App\Module\FileActions\Service\FileActionHistoryCleanup;
use App\Module\FileActions\Repository\FileActionHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FileActionController extends AbstractController
{
    private function adminId(): int
    {
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        return $user->id;
    }


    #[Route('/actions/cleanup',name:'app_actions_cleanup',methods:['GET','POST'])]
    public function cleanup(
        Request $request,FileActionHistoryCleanup $cleanup,
        FileActionHistoryRepository $history
    ):Response {
        $raw=$request->isMethod('POST')?
            $request->request->get('days','90'):$request->query->get('days','90');
        $days=filter_var($raw,FILTER_VALIDATE_INT);
        if($days===false || !in_array($days,FileActionHistoryCleanup::DAYS,true))
            throw $this->createNotFoundException('Invalid retention period.');
        $rawProfile=$request->isMethod('POST')?
            $request->request->get('profile',''):$request->query->get('profile','');
        $profile=($rawProfile==='' || $rawProfile===null)?null:
            filter_var($rawProfile,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $profiles=$history->profiles();
        if($profile===false || ($profile!==null && !in_array($profile,
            array_map(static fn(array $p):int=>(int)$p['id'],$profiles),true)))
            throw $this->createNotFoundException('Unknown profile.');
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('prune_action_history',
                (string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid cleanup token.');
            try {
                $count=$cleanup->prune($days,$profile,$this->adminId(),
                    (string)$request->request->get('confirm',''));
                $this->addFlash('success',$count.' resolved action histories removed. Audit and jobs retained.');
            } catch(\DomainException $ex) {
                $this->addFlash('warning',$ex->getMessage());
            }
            return $this->redirectToRoute('app_actions_cleanup',
                array_filter(['days'=>$days,'profile'=>$profile]));
        }
        return $this->render('actions/cleanup.html.twig',[
            'days'=>$days,'profile'=>$profile,'profiles'=>$profiles,
            'eligible'=>$cleanup->estimate($days,$profile)
        ]);
    }

    #[Route('/actions/{id<\\d+>}/remove',name:'app_action_remove',methods:['GET','POST'])]
    public function removeBatch(int $id,Request $request,FileActionHistoryCleanup $cleanup):Response
    {
        $batch=$cleanup->inspect($id);
        if(!$batch) throw $this->createNotFoundException('Action batch not found.');
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('remove_action_'.$id,
                (string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid removal token.');
            try {
                $cleanup->remove($id,$this->adminId(),
                    (string)$request->request->get('confirm',''));
                $this->addFlash('success','Resolved action history #'.$id.' removed. Audit records remain.');
                return $this->redirectToRoute('app_actions');
            } catch(\DomainException $ex) {
                $this->addFlash('warning',$ex->getMessage());
                return $this->redirectToRoute('app_action_remove',['id'=>$id]);
            }
        }
        return $this->render('actions/remove.html.twig',['batch'=>$batch]);
    }

    #[Route('/actions',name:'app_actions',methods:['GET'])]
    public function index(Request $request,FileActionHistoryRepository $history): Response
    {
        $kind=(string)$request->query->get('kind','');
        if($kind!=='' && !in_array($kind,['quarantine','link','move','rename','bulk_quarantine','bulk_link','delete'],true))
            throw $this->createNotFoundException('Invalid action type.');
        $state=(string)$request->query->get('state','');
        $states=$history->states();
        if($state!=='' && !in_array($state,$states,true))
            throw $this->createNotFoundException('Invalid action state.');
        $profile=$this->positiveQueryId($request,'profile');
        $before=$this->positiveQueryId($request,'before');
        $after=$this->positiveQueryId($request,'after');
        if($before!==null && $after!==null)
            throw $this->createNotFoundException('Only one paging cursor is allowed.');
        $profiles=$history->profiles();
        if($profile!==null && !in_array($profile,array_map(
            static fn(array $row)=>(int)$row['id'],$profiles
        ),true)) throw $this->createNotFoundException('Unknown profile.');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','desc');
        if(!isset(FileActionHistoryRepository::SORTS[$sort]) ||
           !in_array($dir,['asc','desc'],true))
            throw $this->createNotFoundException('Invalid action-history sorting.');
        return $this->render('actions/index.html.twig',[
            'page'=>$history->page($profile,$kind?:null,$state?:null,$before,$after,$sort,$dir),
            'profiles'=>$profiles,'states'=>$states,
            'profile'=>$profile,'kind'=>$kind,'state'=>$state,'sort'=>$sort,'dir'=>$dir,
            'filters'=>['profile'=>$profile,'kind'=>$kind,'state'=>$state,'sort'=>$sort,'dir'=>$dir]
        ]);
    }

    private function positiveQueryId(Request $request,string $field): ?int
    {
        $raw=$request->query->get($field);
        if($raw===null || $raw==='') return null;
        $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) throw $this->createNotFoundException('Invalid '.$field.' parameter.');
        return $id;
    }

    #[Route('/actions/preview/{id<\\d+>}',name:'app_action_preview',methods:['POST'])]
    public function preview(int $id,Request $request,FileActionService $actions): Response
    {
        if(!$this->isCsrfTokenValid('preview_file_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        try {
            $batchId=$actions->plan($id,$this->adminId());
            return $this->redirectToRoute('app_action_detail',['id'=>$batchId]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates');
        }
    }

    #[Route('/actions/link/preview/{id<\\d+>}',name:'app_link_preview',methods:['POST'])]
    public function previewLink(int $id,Request $request,FileActionService $actions): Response
    {
        if(!$this->isCsrfTokenValid('preview_link_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        try {
            $batch=$actions->plan($id,$this->adminId(),'link');
            return $this->redirectToRoute('app_action_detail',['id'=>$batch]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates');
        }
    }

    #[Route('/actions/{id<\\d+>}',name:'app_action_detail',methods:['GET'])]
    public function detail(
        int $id,FileActionService $actions,FileActionHistoryRepository $history
    ): Response
    {
        $batch=$actions->find($id);
        if(!$batch) throw $this->createNotFoundException();
        if($batch['action_kind']==='rename') {
            $rename=$history->renameDetails($id);
            if(!$rename) throw $this->createNotFoundException();
            return $this->render('actions/rename-detail.html.twig',['batch'=>$rename]);
        }
        if($batch['action_kind']==='delete')
            return $this->redirectToRoute('app_delete_detail',['id'=>$id]);
        if($batch['action_kind']==='move')
            return $this->redirectToRoute('app_move_detail',['id'=>$id]);
        if(in_array($batch['action_kind'],['bulk_quarantine','bulk_link'],true))
            return $this->redirectToRoute('app_bulk_detail',['id'=>$id]);
        return $this->render('actions/detail.html.twig',['batch'=>$batch]);
    }

    #[Route('/actions/{id<\\d+>}/confirm',name:'app_action_confirm',methods:['POST'])]
    public function confirm(int $id,Request $request,FileActionService $actions): Response
    {
        if(!$this->isCsrfTokenValid('confirm_action_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        $batch=$actions->find($id);
        $required=$batch && $batch['action_kind']==='link'?'LINK':'QUARANTINE';
        if($request->request->get('confirm')!==$required)
            throw $this->createAccessDeniedException('Enter '.$required.' to authorize this action.');
        try {
            $job=$actions->confirm($id,$this->adminId());
            $this->addFlash('success',($required==='LINK'?'Link':'Quarantine').
                ' queued as job #'.$job.'. The worker will verify both files before changing them.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_action_detail',['id'=>$id]);
    }

    #[Route('/actions/{id<\\d+>}/restore',name:'app_action_restore',methods:['POST'])]
    public function restore(int $id,Request $request,FileActionService $actions): Response
    {
        if(!$this->isCsrfTokenValid('restore_action_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        if($request->request->get('confirm')!=='RESTORE')
            throw $this->createAccessDeniedException('Enter RESTORE to authorize restoring the file.');
        try {
            $job=$actions->restore($id,$this->adminId());
            $this->addFlash('success','Restore queued as job #'.$job.
                '. Existing files at the original path will never be overwritten.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_action_detail',['id'=>$id]);
    }
}
