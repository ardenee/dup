<?php
declare(strict_types=1);
namespace App\Module\Profiles\Controller;

use App\Module\Profiles\Repository\ProfileRepository;
use App\Module\FileActions\Service\ActionSettings;
use App\Module\Profiles\Service\DirectoryBrowser;
use App\Module\Scans\Service\FileHasher;
use App\Module\Jobs\Service\JobStore;
use App\Module\Profiles\Service\ScanScheduleService;
use App\Module\Accounts\Domain\Account;
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
            'profiles'=>$repository->profiles(),'types'=>$repository->types()
        ]);
    }

    /** Same form and same validation for both create and edit. */
    private function validate(Request $request,ProfileRepository $repository,DirectoryBrowser $browser): array
    {
        $errors=[];
        $name=trim((string)$request->request->get('name',''));
        $source=trim((string)$request->request->get('source_root',''));
        $move=trim((string)$request->request->get('move_root',''));
        $type=filter_var($request->request->get('profile_type_id'),FILTER_VALIDATE_INT);
        $size=filter_var($request->request->get('minimum_bytes'),FILTER_VALIDATE_INT,
            ['options'=>['min_range'=>0]]);
        $mode=(string)$request->request->get('hash_mode','crc32_3part');
        $fileExists=(string)$request->request->get('file_exists_policy',ActionSettings::FAIL);
        if(!in_array($fileExists,[ActionSettings::FAIL,ActionSettings::NUMBER,ActionSettings::OVERWRITE],true))
            $errors[]='Choose a valid File Exists policy.';
        if(mb_strlen($name)<1 || mb_strlen($name)>140)
            $errors[]='Profile name must be 1–140 characters.';
        if(!$type || !in_array($type,array_map(
            static fn($row)=>(int)$row['id'],$repository->types()),true))
            $errors[]='Choose a valid file-type preset.';
        if($size===false) $errors[]='Minimum size must be a non-negative number of bytes.';
        if(!FileHasher::isSupported($mode)) $errors[]='Choose a supported hashing strategy.';

        $canonicalSource=$browser->canonicalDirectory($source);
        $canonicalMove=$browser->canonicalDirectory($move);
        if($canonicalSource===null)
            $errors[]='Source folder is not accessible to the PHP account.';
        if($canonicalMove===null)
            $errors[]='Move folder is not accessible to the PHP account.';

        return [$errors,$name,$canonicalSource,$canonicalMove,$type,$size,$mode,$fileExists];
    }

    #[Route('/profiles/new',name:'app_profile_new',methods:['GET','POST'])]
    public function create(Request $request,ProfileRepository $repository,DirectoryBrowser $browser): Response
    {
        $errors=[];
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('create_profile',(string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid form token.');
            [$errors,$name,$source,$move,$type,$size,$mode,$fileExists]=$this->validate($request,$repository,$browser);
            if(!$errors) {
                $repository->create($name,$source,$move,$type,$size,$mode,$fileExists);
                $this->addFlash('success','Profile created. Start a scan to index its files.');
                return $this->redirectToRoute('app_profiles');
            }
        }
        return $this->render('profiles/new.html.twig',[
            'types'=>$repository->types(),'errors'=>$errors,'profile'=>null
        ]);
    }

    #[Route('/profiles/{id<\\d+>}/edit',name:'app_profile_edit',methods:['GET','POST'])]
    public function edit(int $id,Request $request,ProfileRepository $repository,DirectoryBrowser $browser): Response
    {
        $profile=$repository->find($id);
        if(!$profile) throw $this->createNotFoundException('Profile not found.');
        $errors=[];
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('edit_profile_'.$id,(string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid form token.');
            [$errors,$name,$source,$move,$type,$size,$mode,$fileExists]=$this->validate($request,$repository,$browser);
            if(!$errors) {
                try {
                    $repository->update($id,$name,$source,$move,$type,$size,$mode,$fileExists);
                    $this->addFlash('success','Profile settings updated. Existing scan history was preserved.');
                    return $this->redirectToRoute('app_profiles');
                } catch(\DomainException $ex) {
                    $errors[]=$ex->getMessage();
                }
            }
        }
        return $this->render('profiles/new.html.twig',[
            'types'=>$repository->types(),'errors'=>$errors,'profile'=>$profile
        ]);
    }

    #[Route('/profiles/{id<\\d+>}/enabled',name:'app_profile_enabled',methods:['POST'])]
    public function enabled(int $id,Request $request,ProfileRepository $repository): Response
    {
        if(!$this->isCsrfTokenValid('toggle_profile_'.$id,(string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $profile=$repository->find($id);
        if(!$profile) throw $this->createNotFoundException('Profile not found.');
        try {
            $newState=!(bool)$profile['enabled'];
            $repository->setEnabled($id,$newState);
            $this->addFlash('success','Profile '.($newState?'enabled':'disabled').
                '. Historical scan data remains available.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_profiles');
    }

    #[Route('/profiles/{id<\\d+>}/schedule',name:'app_profile_schedule',methods:['POST'])]
    public function schedule(int $id,Request $request,ScanScheduleService $schedules):Response
    {
        if(!$this->isCsrfTokenValid('schedule_profile_'.$id,
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid schedule token.');
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        $interval=filter_var($request->request->get('interval_minutes'),FILTER_VALIDATE_INT);
        try {
            if($interval===false) throw new \DomainException('Choose a scan interval.');
            $schedules->save($id,$interval,$user->id);
            $this->addFlash('success',$interval===0?
                'Automatic scanning disabled for this profile.':
                'Schedule saved. The background worker will start scans automatically.');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
        }
        return $this->redirectToRoute('app_profiles');
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
