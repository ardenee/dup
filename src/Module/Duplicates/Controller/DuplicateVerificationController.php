<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\Duplicates\Service\DuplicateVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DuplicateVerificationController extends AbstractController
{
    #[Route('/duplicates/verify',name:'app_duplicates_verify',methods:['POST'])]
    public function verify(Request $request,DuplicateVerificationService $service):Response
    {
        if(!$this->isCsrfTokenValid('verify_duplicate_candidates',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid verification token.');
        $user=$this->getUser();
        if(!$user instanceof Account)throw $this->createAccessDeniedException();
        $id=filter_var($request->request->get('profile'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        if($id===false)throw $this->createNotFoundException('Choose a scan profile.');
        if($request->request->get('confirm')!=='VERIFY')
            throw $this->createAccessDeniedException('Confirm the secondary verification.');
        try {
            $job=$service->queue($id,$user->id);
            $this->addFlash('success','Second-stage SHA-256 verification queued as job #'.$job.
                '. Only existing duplicate candidates will be read.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates',['profile'=>$id]);
        }
    }
}
