<?php
declare(strict_types=1);
namespace App\Module\Search\Controller;

use App\Module\Search\Repository\SearchRepository;
use App\Module\Search\Repository\ActionHistoryRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SearchController extends AbstractController
{
    private function optionalId(Request $request,string $key): ?int
    {
        $input=$request->query->get($key);
        if($input===null || $input==='') return null;
        $id=filter_var($input,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($id===false) throw $this->createNotFoundException('Invalid search cursor or profile.');
        return $id;
    }

    #[Route('/search',name:'app_search',methods:['GET'])]
    public function index(
        Request $request,SearchRepository $search,ActionHistoryRepository $history
    ): Response
    {
        $term=trim((string)$request->query->get('q',''));
        if(mb_strlen($term)>200)
            throw $this->createNotFoundException('Search term is too long.');
        $mode=(string)$request->query->get('mode','all');
        if(!in_array($mode,['all','duplicates','moved','history'],true))
            throw $this->createNotFoundException('Invalid search mode.');
        $profile=$this->optionalId($request,'profile');
        $after=$this->optionalId($request,'after');
        $before=$this->optionalId($request,'before');
        if($after!==null && $before!==null)
            throw $this->createNotFoundException('Invalid paging parameters.');
        $caseSensitive=$request->query->get('case')==='1';
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','desc');
        $fileState=(string)$request->query->get('file_state','');
        $actionKind=(string)$request->query->get('action','');
        if(!in_array($dir,['asc','desc'],true))
            throw $this->createNotFoundException('Invalid search sorting.');
        $supported=$mode==='history' ?
            ActionHistoryRepository::SORTS : SearchRepository::SORTS;
        if(!isset($supported[$sort])) $sort='id'; // mode switch defaults to ID
        if($fileState!=='' && !in_array($fileState,
            ['indexed','ignored','symlink','moved','linked','quarantined','error'],true))
            throw $this->createNotFoundException('Invalid file status.');
        if($actionKind!=='' && !in_array($actionKind,
            ['move','link','quarantine','bulk_link','bulk_quarantine','delete'],true))
            throw $this->createNotFoundException('Invalid action kind.');
        $submitted=$request->query->has('q');
        $results=$submitted
            ?($mode==='history'
                ?$history->find($term,$profile,$caseSensitive,$after,$before,
                    $sort,$dir,$actionKind)
                :$search->find($term,$profile,$mode,$caseSensitive,$after,$before,
                    $sort,$dir,$fileState))
            :['rows'=>[],'total'=>0,'next'=>null,'previous'=>null];

        return $this->render('search/index.html.twig',[
            'profiles'=>$search->profiles(),'term'=>$term,'mode'=>$mode,
            'profile'=>$profile,'case_sensitive'=>$caseSensitive,
            'submitted'=>$submitted,'results'=>$results,
            'sort'=>$sort,'dir'=>$dir,
            'file_state'=>$fileState,'action_kind'=>$actionKind,
            'filters'=>[
                'q'=>$term,'mode'=>$mode,
                'profile'=>$profile,'case'=>$caseSensitive?'1':'0',
                'file_state'=>$fileState,'action'=>$actionKind,
                'sort'=>$sort,'dir'=>$dir
            ]
        ]);
    }
}
