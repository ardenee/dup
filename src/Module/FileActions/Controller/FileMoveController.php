<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\FileMoveService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FileMoveController extends AbstractController
{
    private function actor(): int
    {
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        return $user->id;
    }

    #[Route('/move/preview',name:'app_move_preview',methods:['POST'])]
    public function preview(Request $request,FileMoveService $moves): Response
    {
        if(!$this->isCsrfTokenValid('move_selected',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        $raw=$request->request->all('files');
        $ids=[];
        foreach($raw as $item) {
            $id=filter_var($item,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false) throw $this->createNotFoundException('Invalid selected file.');
            $ids[]=$id;
        }
        try {
            $batch=$moves->plan($ids,$this->actor());
            return $this->redirectToRoute('app_move_detail',['id'=>$batch]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates');
        }
    }

    #[Route('/move/{id<\d+>}',name:'app_move_detail',methods:['GET'])]
    public function detail(int $id,FileMoveService $moves): Response
    {
        $batch=$moves->details($id);
        if(!$batch) throw $this->createNotFoundException('Move batch not found.');
        return $this->render('actions/move.html.twig',['batch'=>$batch]);
    }

    #[Route('/move/{id<\d+>}/confirm',name:'app_move_confirm',methods:['POST'])]
    public function confirm(int $id,Request $request,FileMoveService $moves): Response
    {
        if(!$this->isCsrfTokenValid('confirm_move_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        if($request->request->get('confirm')!=='MOVE')
            throw $this->createAccessDeniedException('Type MOVE to confirm.');
        try {
            $job=$moves->confirm($id,$this->actor());
            $this->addFlash('success','Move job #'.$job.' queued. Nothing will be overwritten.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_move_detail',['id'=>$id]);
        }
    }

    #[Route('/move/{id<\d+>}/restore',name:'app_move_restore',methods:['POST'])]
    public function restore(int $id,Request $request,FileMoveService $moves): Response
    {
        if(!$this->isCsrfTokenValid('restore_move_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        if($request->request->get('confirm')!=='RESTORE')
            throw $this->createAccessDeniedException('Type RESTORE to confirm.');
        try {
            $job=$moves->restore($id,$this->actor());
            $this->addFlash('success','Restore job #'.$job.' queued.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_move_detail',['id'=>$id]);
        }
    }
}
