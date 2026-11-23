<?php
declare(strict_types=1);
namespace App\Module\Profiles\Controller;

use App\Module\Profiles\Service\ProfileTypeManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfileTypesController extends AbstractController
{
    #[Route('/profiles/types',name:'app_profile_types',methods:['GET'])]
    public function index(Request $request,ProfileTypeManager $types): Response
    {
        $editId=filter_var($request->query->get('edit'),FILTER_VALIDATE_INT);
        $current=$editId && $editId>0?$types->find($editId):null;
        if($editId && !$current) throw $this->createNotFoundException('Preset not found.');
        return $this->render('profiles/types.html.twig',[
            'types'=>$types->all(),'editing'=>$current
        ]);
    }

    #[Route('/profiles/types/save',name:'app_profile_type_save',methods:['POST'])]
    public function save(Request $request,ProfileTypeManager $types): Response
    {
        if(!$this->isCsrfTokenValid('save_profile_type',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $raw=$request->request->get('id');
        $id=$raw?filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):null;
        if($raw && $id===false) throw $this->createNotFoundException('Invalid preset.');
        try {
            $types->save($id,(string)$request->request->get('name',''),
                (string)$request->request->get('extensions',''));
            $this->addFlash('success','File-type preset saved. Existing completed scans remain unchanged.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_profile_types',
                $id?['edit'=>$id]:[]);
        }
        return $this->redirectToRoute('app_profile_types');
    }

    #[Route('/profiles/types/{id<\\d+>}/delete',name:'app_profile_type_delete',methods:['POST'])]
    public function delete(int $id,Request $request,ProfileTypeManager $types): Response
    {
        if(!$this->isCsrfTokenValid('delete_profile_type_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        try {
            $types->remove($id);
            $this->addFlash('success','Unused file-type preset deleted.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_profile_types');
    }
}
