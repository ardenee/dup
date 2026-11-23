<?php
declare(strict_types=1);
namespace App\Module\Profiles\Controller;

use App\Module\Profiles\Service\DirectoryBrowser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FolderBrowserController extends AbstractController
{
    #[Route('/api/folders',name:'app_folder_browse',methods:['GET'])]
    public function browse(Request $request,DirectoryBrowser $browser): JsonResponse
    {
        $value=$request->query->get('path');
        if($value!==null && !is_string($value)) {
            throw $this->createNotFoundException('Invalid directory request.');
        }
        try {
            return new JsonResponse($browser->list($value),200,[
                'Cache-Control'=>'no-store, private'
            ]);
        } catch(\DomainException $ex) {
            throw $this->createAccessDeniedException('Directory is not accessible.');
        } catch(\UnexpectedValueException $ex) {
            throw $this->createAccessDeniedException('Directory enumeration was denied.');
        }
    }
}
