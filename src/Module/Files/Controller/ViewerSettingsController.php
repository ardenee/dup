<?php
declare(strict_types=1);
namespace App\Module\Files\Controller;

use App\Module\Files\Service\ViewerSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ViewerSettingsController extends AbstractController
{
    #[Route('/settings/viewer',name:'app_viewer_settings',methods:['GET'])]
    public function index(ViewerSettings $settings): Response
    {
        return $this->render('files/settings.html.twig',['settings'=>$settings->all()]);
    }

    #[Route('/settings/viewer',name:'app_viewer_settings_save',methods:['POST'])]
    public function save(Request $request,ViewerSettings $settings): Response
    {
        if(!$this->isCsrfTokenValid('viewer_settings',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        try {
            $settings->save([
                'view_enable'=>$request->request->has('view_enable'),
                'view_image_exts'=>$request->request->get('view_image_exts',''),
                'view_text_exts'=>$request->request->get('view_text_exts',''),
                'view_pdf_exts'=>$request->request->get('view_pdf_exts','')
            ]);
            $this->addFlash('success','File viewer preferences saved. No rescan is needed.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_viewer_settings');
    }
}
