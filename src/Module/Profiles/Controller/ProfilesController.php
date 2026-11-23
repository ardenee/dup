<?php
declare(strict_types=1);
namespace App\Module\Profiles\Controller;
use App\Module\Profiles\Repository\ProfileRepository;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Jobs\Service\JobStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProfilesController extends AbstractController
{
    #[Route('/profiles',name:'app_profiles',methods:['GET'])]
    public function index(ProfileRepository $repository): Response
    {
        return $this->render('profiles/index.html.twig',[
            'profiles'=>$repository->profiles(), 'types'=>$repository->types()
        ]);
    }

    #[Route('/profiles/new',name:'app_profile_new',methods:['GET','POST'])]
    public function create(Request $request,ProfileRepository $repository,DirectoryBrowser $browser): Response
    {
        $types=$repository->types();
        $errors=[];
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('create_profile',(string)$request->request->get('_token',''))) {
                throw $this->createAccessDeniedException('Invalid form token.');
            }
            $name=trim((string)$request->request->get('name',''));
            $source=trim((string)$request->request->get('source_root',''));
            $move=trim((string)$request->request->get('move_root',''));
            $type=filter_var($request->request->get('profile_type_id'),FILTER_VALIDATE_INT);
            $size=filter_var($request->request->get('minimum_bytes'),FILTER_VALIDATE_INT,
                ['options'=>['min_range'=>0]]);
            $mode=(string)$request->request->get('hash_mode','sha256');
            if(mb_strlen($name)<1 || mb_strlen($name)>140) $errors[]='Profile name must be 1–140 characters.';
            if(!$type || !in_array($type,array_map(fn($row)=>(int)$row['id'],$types),true))
                $errors[]='Choose a valid profile type.';
            if($size===false) $errors[]='Minimum size must be a non-negative number of bytes.';
            if(!in_array($mode,['size','sha256'],true)) $errors[]='Choose a supported hash mode.';
            // Check real paths on the host, not paths in the user's browser.
            foreach(['Source'=>$source,'Move'=>$move] as $label=>$path) {
                if($browser->canonicalDirectory($path) === null)
                    $errors[]=$label.' folder is not accessible to the server PHP account.';
            }
            if(!$errors) {
                $repository->create($name,$browser->canonicalDirectory($source),$browser->canonicalDirectory($move),
                    (int)$type,(int)$size,$mode);
                $this->addFlash('success','Profile created. Queue a scan when the CLI worker is configured.');
                return $this->redirectToRoute('app_profiles');
            }
        }
        return $this->render('profiles/new.html.twig',[
            'types'=>$types,'errors'=>$errors
        ]);
    }

    #[Route('/profiles/{id<\\d+>}/scan',name:'app_profile_scan',methods:['POST'])]
    public function scan(int $id,Request $request,JobStore $jobs): Response
    {
        if(!$this->isCsrfTokenValid('scan_profile_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        try {
            $jobId=$jobs->queueScan($id);
            $this->addFlash('success','Queued scan job #'.$jobId.'.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_profiles');
        }
    }
}
