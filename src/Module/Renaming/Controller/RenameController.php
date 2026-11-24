<?php
declare(strict_types=1);
namespace App\Module\Renaming\Controller;

use App\Module\Accounts\Domain\Account;
use App\Module\Renaming\Service\RenameRules;
use App\Module\Renaming\Service\RenameService;
use App\Module\FileActions\Service\ActionSettings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class RenameController extends AbstractController
{
    private function actor(): int
    {
        $user=$this->getUser();
        if(!$user instanceof Account) throw $this->createAccessDeniedException();
        return $user->id;
    }

    private function number(mixed $value,int $default=0): int
    {
        $parsed=filter_var($value,FILTER_VALIDATE_INT);
        return $parsed===false?$default:$parsed;
    }

    #[Route('/rename',name:'app_rename',methods:['GET'])]
    public function index(Request $request,RenameService $renames,ActionSettings $settings): Response
    {
        $profiles=$renames->profiles();
        $first=(int)($profiles[0]['id']??0);
        $profile=$this->number($request->query->get('profile'),$first);
        if($profile===0) $profile=$first;
        if($profile>0 && !in_array($profile,array_map(
            static fn(array $row)=>(int)$row['id'],$profiles),true))
            throw $this->createNotFoundException('Unknown profile.');
        $filters=trim((string)$request->query->get('filters',''));
        if(strlen($filters)>1000) throw $this->createNotFoundException('Too many filters.');
        $dots=$this->number($request->query->get('dots'),0);
        $unders=$this->number($request->query->get('unders'),0);
        $collapse=$request->query->get('collapse','1')==='1';
        $dups=$request->query->get('dups')==='1';
        $find=(string)$request->query->get('find','');
        $replace=(string)$request->query->get('replace','');
        if(strlen($find)>255 || strlen($replace)>255)
            throw $this->createNotFoundException('Find/replace text too long.');
        $collision=(string)$request->query->get('collision',
            $settings->policy()===ActionSettings::NUMBER?'number':'reject');
        if(!in_array($collision,['reject','number'],true))
            throw $this->createNotFoundException('Invalid collision policy.');
        $after=$this->number($request->query->get('after'),0);
        if(!in_array($dots,[0,1,2],true) || !in_array($unders,[0,1,2],true))
            throw $this->createNotFoundException('Invalid cleanup options.');
        $candidates=$profile>0?$renames->candidates(
            $profile,$filters,$dots,$unders,$collapse,$dups,$after>0?$after:null,$collision==='number',$find,$replace
        ):['rows'=>[],'next'=>null,'scanned'=>0];
        return $this->render('rename/index.html.twig',[
            'profiles'=>$profiles,'profile'=>$profile,'filters'=>$filters,
            'dots'=>$dots,'unders'=>$unders,'collapse'=>$collapse,
            'dups'=>$dups,'collision'=>$collision,'find'=>$find,'replace'=>$replace,
            'candidates'=>$candidates,'batches'=>$renames->batches(),
            'paging'=>[
                'profile'=>$profile,'filters'=>$filters,'dots'=>$dots,
                'unders'=>$unders,'collapse'=>$collapse?'1':'0',
                'dups'=>$dups?'1':'0','collision'=>$collision,
                'find'=>$find,'replace'=>$replace
            ]
        ]);
    }

    #[Route('/rename/queue',name:'app_rename_queue',methods:['POST'])]
    public function queue(Request $request,RenameService $renames): Response
    {
        if(!$this->isCsrfTokenValid('queue_renames',(string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $profile=$this->number($request->request->get('profile'));
        $filters=trim((string)$request->request->get('filters',''));
        $dots=$this->number($request->request->get('dots'));
        $unders=$this->number($request->request->get('unders'));
        $collapse=$request->request->get('collapse')==='1';
        $find=(string)$request->request->get('find','');
        $replace=(string)$request->request->get('replace','');
        $ids=$request->request->all('files');
        $planned=$request->request->all('planned_names');
        $selected=[];
        foreach($ids as $raw) {
            $id=filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false) throw $this->createNotFoundException('Invalid file selection.');
            $selected[]=$id;
        }
        $overrides=[];
        foreach($selected as $id) {
            if(isset($planned[$id])) {
                if(!is_string($planned[$id]) || strlen($planned[$id])>255)
                    throw $this->createNotFoundException('Invalid planned filename.');
                $overrides[$id]=$planned[$id];
            }
        }
        try {
            $job=$renames->queue($profile,$this->actor(),$selected,$filters,$dots,$unders,$collapse,$overrides,$find,$replace);
            $this->addFlash('success','Filename changes queued as job #'.$job.'. No existing files will be overwritten.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_rename',['profile'=>$profile,'filters'=>$filters]);
        }
    }

    /**
     * Original DUP bulk rename from selected file rows. Preview is read-only;
     * every target name is explicitly confirmed before queueing.
     */
    #[Route('/rename/selected',name:'app_rename_selected',methods:['POST'])]
    public function selected(Request $request,RenameService $renames): Response
    {
        if(!$this->isCsrfTokenValid('rename_selected',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $ids=$request->request->all('files');
        if(count($ids)<1 || count($ids)>50)
            throw $this->createNotFoundException('Select between 1 and 50 files to rename.');
        $files=[];$profile=0;
        foreach($ids as $value) {
            $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false || isset($files[$id]))
                throw $this->createNotFoundException('Invalid or repeated file selection.');
            $file=$renames->file($id);
            if(!$file || $file['error_code']!==null || (int)$file['is_ignored']!==0)
                throw $this->createNotFoundException('A selected file is no longer in the latest scan.');
            if($profile!==0 && $profile!==(int)$file['profile_id'])
                throw $this->createNotFoundException('Select files from one scan profile at a time.');
            $profile=(int)$file['profile_id'];
            $files[$id]=$file;
        }
        return $this->render('rename/selected.html.twig',[
            'files'=>$files,'profile'=>$profile
        ]);
    }

    #[Route('/rename/selected/queue',name:'app_rename_selected_queue',methods:['POST'])]
    public function queueSelected(Request $request,RenameService $renames): Response
    {
        if(!$this->isCsrfTokenValid('queue_selected_renames',
            (string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid form token.');
        $profile=$this->number($request->request->get('profile'));
        $names=$request->request->all('names');
        if(!$names || count($names)>50 || $profile<1)
            throw $this->createNotFoundException('Invalid selection.');
        $ids=[];$overrides=[];
        foreach($names as $key=>$value) {
            $id=filter_var($key,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id===false || !is_string($value) || strlen($value)>255)
                throw $this->createNotFoundException('Invalid filename selection.');
            $file=$renames->file($id);
            if(!$file || (int)$file['profile_id']!==$profile ||
               $file['error_code']!==null)
                throw $this->createNotFoundException('Selected scan record has changed.');
            if(trim($value)===(string)$file['filename']) continue; // unchanged entry
            $ids[]=$id;
            $overrides[$id]=$value;
        }
        try {
            $job=$renames->queue($profile,$this->actor(),$ids,'',0,0,false,$overrides);
            $this->addFlash('success','Queued file rename job #'.$job.'. Existing files cannot be overwritten.');
            return $this->redirectToRoute('app_jobs');
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_duplicates',['profile'=>$profile]);
        }
    }

    #[Route('/rename/file/{id<\\d+>}',name:'app_rename_file',methods:['GET','POST'])]
    public function renameFile(int $id,Request $request,RenameService $renames): Response
    {
        $file=$renames->file($id);
        if(!$file || $file['error_code']!==null)
            throw $this->createNotFoundException('The indexed file is unavailable.');
        $error=null;
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('rename_file_'.$id,
                (string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid form token.');
            try {
                $newName=trim((string)$request->request->get('new_filename',''));
                RenameRules::validateName($newName);
                $job=$renames->queue((int)$file['profile_id'],$this->actor(),[$id],
                    '',0,0,false,[$id=>$newName]);
                $this->addFlash('success','File rename queued as job #'.$job.'.');
                return $this->redirectToRoute('app_jobs');
            } catch(\DomainException $ex) {
                $error=$ex->getMessage();
            }
        }
        return $this->render('rename/file.html.twig',[
            'file'=>$file,'error'=>$error
        ]);
    }
}
