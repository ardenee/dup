<?php
declare(strict_types=1);
namespace App\Module\FileActions\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Compatibility redirect for bookmarks from DUP 0.7.8.
 * No global File Exists setting can be modified here.
 */
final class ActionSettingsController extends AbstractController
{
    #[Route('/settings/actions',name:'app_action_settings',methods:['GET'])]
    public function index():Response
    {
        return $this->redirectToRoute('app_profiles');
    }

    #[Route('/settings/actions',name:'app_action_settings_save',methods:['POST'])]
    public function save():Response
    {
        $this->addFlash('warning',
            'File Exists is now configured independently within each scan profile.');
        return $this->redirectToRoute('app_profiles');
    }
}
