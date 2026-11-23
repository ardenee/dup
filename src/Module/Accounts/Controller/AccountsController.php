<?php
declare(strict_types=1);
namespace App\Module\Accounts\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Service\AccountManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AccountsController extends AbstractController
{
    private function actor():int
    {
        $current=$this->getUser();
        if(!$current instanceof Account)
            throw $this->createAccessDeniedException('Administrator login required.');
        return $current->id;
    }

    #[Route('/accounts',name:'app_accounts',methods:['GET'])]
    public function index(Request $request,AccountManager $users):Response
    {
        $edit=filter_var($request->query->get('edit'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]);
        $current=$edit?$users->find((int)$edit):null;
        if($edit && !$current) throw $this->createNotFoundException('Account not found.');
        return $this->render('accounts/index.html.twig',[
            'accounts'=>$users->list(),'editing'=>$current,'self_id'=>$this->actor()
        ]);
    }

    #[Route('/accounts/save',name:'app_accounts_save',methods:['POST'])]
    public function save(Request $request,AccountManager $users):Response
    {
        if(!$this->isCsrfTokenValid('account_save',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid account form token.');
        $idRaw=$request->request->get('id');
        $id=$idRaw?filter_var($idRaw,FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>1]]):null;
        if($idRaw && $id===false) throw $this->createNotFoundException();
        $actor=$this->actor();
        $password=(string)$request->request->get('password','');
        if($id===$actor && $password!=='') {
            $own=$users->find($actor);
            $current=(string)$request->request->get('current_password','');
            if(!$own || !password_verify($current,(string)$own['password_hash'])) {
                $this->addFlash('warning','Enter your current password to change your own password.');
                return $this->redirectToRoute('app_accounts',['edit'=>$actor]);
            }
        }
        try {
            $users->save($id,
                (string)$request->request->get('username',''),
                (string)$request->request->get('role','ROLE_USER'),
                $password,$actor
            );
            $this->addFlash('success','Account saved.');
            return $this->redirectToRoute('app_accounts');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_accounts',$id?['edit'=>$id]:[]);
        }
    }

    #[Route('/accounts/{id<\d+>}/enabled',name:'app_accounts_enabled',methods:['POST'])]
    public function enabled(int $id,Request $request,AccountManager $users):Response
    {
        if(!$this->isCsrfTokenValid('account_enabled_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid account form token.');
        $enable=$request->request->get('enabled')==='1';
        try {
            $users->enabled($id,$enable,$this->actor());
            $this->addFlash('success',$enable?'Account enabled.':'Account disabled; history retained.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_accounts');
    }
}
