<?php
declare(strict_types=1);
namespace App\Module\Search\Controller;

use App\Module\Search\Repository\SearchRepository;
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
    public function index(Request $request,SearchRepository $search): Response
    {
        $term=trim((string)$request->query->get('q',''));
        if(mb_strlen($term)>200)
            throw $this->createNotFoundException('Search term is too long.');
        $mode=(string)$request->query->get('mode','all');
        if(!in_array($mode,['all','duplicates','moved'],true))
            throw $this->createNotFoundException('Invalid search mode.');
        $profile=$this->optionalId($request,'profile');
        $after=$this->optionalId($request,'after');
        $before=$this->optionalId($request,'before');
        if($after!==null && $before!==null)
            throw $this->createNotFoundException('Invalid paging parameters.');
        $caseSensitive=$request->query->get('case')==='1';
        $submitted=$request->query->has('q');
        $results=$submitted
            ?$search->find($term,$profile,$mode,$caseSensitive,$after,$before)
            :['rows'=>[],'total'=>0,'next'=>null,'previous'=>null];

        return $this->render('search/index.html.twig',[
            'profiles'=>$search->profiles(),'term'=>$term,'mode'=>$mode,
            'profile'=>$profile,'case_sensitive'=>$caseSensitive,
            'submitted'=>$submitted,'results'=>$results,
            'filters'=>[
                'q'=>$term,'mode'=>$mode,
                'profile'=>$profile,'case'=>$caseSensitive?'1':'0'
            ]
        ]);
    }
}
