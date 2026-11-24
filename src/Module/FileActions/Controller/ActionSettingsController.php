<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\FileActions\Service\ActionSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ActionSettingsController extends AbstractController
{
    #[Route('/settings/actions',name:'app_action_settings',methods:['GET'])]
    public function index(ActionSettings $settings):Response
    {
        return $this->render('actions/settings.html.twig',['policy'=>$settings->policy()]);
    }

    #[Route('/settings/actions',name:'app_action_settings_save',methods:['POST'])]
    public function save(Request $request,ActionSettings $settings):Response
    {
        if(!$this->isCsrfTokenValid('action_settings',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid action settings token.');
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        try {
            $settings->save((string)$request->request->get('destination_exists',''),$user->id);
            $this->addFlash('success','File-exists preference saved. Existing previews and jobs are unchanged.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_action_settings');
    }
}
