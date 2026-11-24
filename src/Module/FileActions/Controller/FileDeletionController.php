<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\FileDeletionService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class FileDeletionController extends AbstractController
{
    private function actor():int
    {
        $user=$this->getUser();
        if(!$user instanceof Account)throw $this->createAccessDeniedException();
        return $user->id;
    }

    #[Route('/delete/preview',name:'app_delete_preview',methods:['POST'])]
    public function preview(Request $request,FileDeletionService $service):Response
    {
        if(!$this->isCsrfTokenValid('delete_selected',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid deletion token.');
        $raw=$request->request->all('files');
        if(!$raw || count($raw)>FileDeletionService::MAX_FILES)
            throw $this->createNotFoundException('Select 1 to 50 files.');
        $ids=[];
        foreach($raw as $item) {
            $id=filter_var($item,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false || in_array($id,$ids,true))
                throw $this->createNotFoundException('Invalid file selection.');
            $ids[]=$id;
        }
        try {
            return $this->redirectToRoute('app_delete_detail',[
                'id'=>$service->plan($ids,$this->actor())
            ]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates');
        }
    }

    #[Route('/delete/{id<\d+>}',name:'app_delete_detail',methods:['GET'])]
    public function detail(int $id,FileDeletionService $service):Response
    {
        $batch=$service->details($id);
        if(!$batch)throw $this->createNotFoundException('Delete batch not found.');
        return $this->render('actions/delete.html.twig',['batch'=>$batch]);
    }

    #[Route('/delete/{id<\d+>}/confirm',name:'app_delete_confirm',methods:['POST'])]
    public function confirm(int $id,Request $request,FileDeletionService $service):Response
    {
        if(!$this->isCsrfTokenValid('confirm_delete_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid delete confirmation token.');
        if($request->request->get('confirm')!=='DELETE')
            throw $this->createAccessDeniedException('Type DELETE to authorize permanent deletion.');
        try {
            $job=$service->confirm($id,$this->actor());
            $this->addFlash('success','Permanent deletion queued as job #'.$job.'.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_delete_detail',['id'=>$id]);
        }
    }
}
