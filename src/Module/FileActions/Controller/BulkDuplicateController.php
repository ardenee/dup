<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\BulkDuplicateService;
use App\Module\FileActions\Service\SelectedFileIds;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class BulkDuplicateController extends AbstractController
{
    private function actor():int
    {
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        return $user->id;
    }

    #[Route('/actions/bulk/preview',name:'app_bulk_preview',methods:['POST'])]
    public function preview(Request $request,BulkDuplicateService $service):Response
    {
        if(!$this->isCsrfTokenValid('bulk_duplicate_action',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid batch preview token.');
        $kind=(string)$request->request->get('action_kind','');
        if(!in_array($kind,BulkDuplicateService::ACTIONS,true))
            throw $this->createNotFoundException('Invalid bulk action.');
        try {
            $ids=SelectedFileIds::fromRequest($request);
            $id=$service->plan($ids,$this->actor(),$kind);
            return $this->redirectToRoute('app_bulk_detail',['id'=>$id]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates');
        }
    }

    #[Route('/actions/bulk/{id<\d+>}',name:'app_bulk_detail',methods:['GET'])]
    public function detail(int $id,BulkDuplicateService $service):Response
    {
        $batch=$service->details($id);
        if(!$batch) throw $this->createNotFoundException('Bulk action batch not found.');
        return $this->render('actions/bulk.html.twig',['batch'=>$batch]);
    }

    #[Route('/actions/bulk/{id<\d+>}/confirm',name:'app_bulk_confirm',methods:['POST'])]
    public function confirm(int $id,Request $request,BulkDuplicateService $service):Response
    {
        if(!$this->isCsrfTokenValid('bulk_confirm_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid batch confirmation token.');
        $batch=$service->details($id);
        if(!$batch) throw $this->createNotFoundException();
        $required=$batch['action_kind']==='bulk_link'?'LINK':'QUARANTINE';
        if($request->request->get('confirm')!==$required)
            throw $this->createAccessDeniedException('Enter '.$required.' to confirm.');
        try {
            $job=$service->confirm($id,$this->actor());
            $this->addFlash('success','Bulk action queued as job #'.$job.
                '. Every selected file and its survivor will be verified before any change.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_bulk_detail',['id'=>$id]);
    }

    #[Route('/actions/bulk/{id<\d+>}/restore',name:'app_bulk_restore',methods:['POST'])]
    public function restore(int $id,Request $request,BulkDuplicateService $service):Response
    {
        if(!$this->isCsrfTokenValid('bulk_restore_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid batch restore token.');
        if($request->request->get('confirm')!=='RESTORE')
            throw $this->createAccessDeniedException('Enter RESTORE to confirm.');
        try {
            $job=$service->restore($id,$this->actor());
            $this->addFlash('success','Bulk restore queued as job #'.$job.
                '. Original paths will not be overwritten.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_bulk_detail',['id'=>$id]);
    }
}
