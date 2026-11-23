<?php
declare(strict_types=1);
namespace App\Module\Profiles\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\Profiles\Service\IgnoreRuleService;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class IgnoreRulesController extends AbstractController
{
    #[Route('/settings/ignored-folders',name:'app_ignore_folders',methods:['GET'])]
    public function index(IgnoreRuleService $rules): Response
    {
        return $this->render('profiles/settings/ignore-folders.html.twig',[
            'names'=>implode("\n",$rules->rules())
        ]);
    }

    #[Route('/settings/ignored-folders',name:'app_ignore_folders_save',methods:['POST'])]
    public function save(Request $request,IgnoreRuleService $rules): Response
    {
        if(!$this->isCsrfTokenValid('ignore_rules',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        $names=(string)$request->request->get('names','');
        $user=$this->getUser();
        try {
            $jobs=$rules->save($names,$user instanceof Account?$user->id:null);
            $this->addFlash('success','Folder exclusions saved. '.$jobs.
                ' duplicate recalculation job(s) queued; no files will be rescanned.');
        } catch(\DomainException|UniqueConstraintViolationException $ex) {
            $this->addFlash('warning',$ex instanceof \DomainException?$ex->getMessage():
                'A profile became busy while saving. No settings were changed.');
        }
        return $this->redirectToRoute('app_ignore_folders');
    }
}
