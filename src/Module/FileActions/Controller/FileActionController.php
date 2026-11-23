<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\FileActionService;
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

    #[Route('/actions',name:'app_actions',methods:['GET'])]
    public function index(FileActionService $actions): Response
    {
        return $this->render('actions/index.html.twig',['batches'=>$actions->history()]);
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

    #[Route('/actions/{id<\\d+>}',name:'app_action_detail',methods:['GET'])]
    public function detail(int $id,FileActionService $actions): Response
    {
        $batch=$actions->find($id);
        if(!$batch) throw $this->createNotFoundException();
        return $this->render('actions/detail.html.twig',['batch'=>$batch]);
    }

    #[Route('/actions/{id<\\d+>}/confirm',name:'app_action_confirm',methods:['POST'])]
    public function confirm(int $id,Request $request,FileActionService $actions): Response
    {
        if(!$this->isCsrfTokenValid('confirm_action_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        if($request->request->get('confirm')!=='QUARANTINE')
            throw $this->createAccessDeniedException('Enter QUARANTINE to authorize this action.');
        try {
            $job=$actions->confirm($id,$this->adminId());
            $this->addFlash('success','Quarantine queued as job #'.$job.
                '. The worker will reverify the file and its surviving duplicate.');
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
