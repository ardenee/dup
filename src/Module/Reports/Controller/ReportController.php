<?php
declare(strict_types=1);

namespace App\Module\Reports\Controller;

use App\Module\Reports\Repository\ReportRepository;
use App\Module\Reports\Service\ScanHistoryCleanup;
use App\Module\Reports\Service\ScanRetentionService;
use App\Module\Accounts\Domain\Account;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReportController extends AbstractController
{
    /** Invalid cursors do not silently broaden the query. */
    private function positiveCursor(Request $request,string $key): ?int
    {
        $input=$request->query->get($key);
        if($input===null || $input==='') return null;
        $cursor=filter_var($input,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if($cursor===false) throw $this->createNotFoundException('Invalid report filter.');
        return $cursor;
    }


    #[Route('/reports/cleanup',name:'app_reports_cleanup',methods:['GET','POST'])]
    public function cleanup(
        Request $request,ScanRetentionService $retention,ReportRepository $reports
    ):Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');
        $raw=$request->isMethod('POST')?
            $request->request->get('days','90'):$request->query->get('days','90');
        $days=filter_var($raw,FILTER_VALIDATE_INT);
        if($days===false || !in_array($days,ScanRetentionService::DAYS,true))
            throw $this->createNotFoundException('Invalid retention period.');
        $rawProfile=$request->isMethod('POST')?
            $request->request->get('profile',''):$request->query->get('profile','');
        $profile=($rawProfile==='' || $rawProfile===null)?null:
            filter_var($rawProfile,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $profiles=$reports->profiles();
        if($profile===false || ($profile!==null && !in_array($profile,
            array_map(static fn(array $p):int=>(int)$p['id'],$profiles),true)))
            throw $this->createNotFoundException('Unknown scan profile.');
        if($request->isMethod('POST')) {
            if(!$this->isCsrfTokenValid('prune_scans',(string)$request->request->get('_token','')))
                throw $this->createAccessDeniedException('Invalid cleanup token.');
            $actor=$this->getUser();
            if(!$actor instanceof Account) throw $this->createAccessDeniedException();
            try {
                $removed=$retention->prune($days,$profile,$actor->id,
                    (string)$request->request->get('confirm',''));
                $this->addFlash('success',$removed.' old scans removed from the database. Files on disk and the latest completed results were preserved.');
            } catch(\DomainException $ex) {
                $this->addFlash('warning',$ex->getMessage());
            }
            return $this->redirectToRoute('app_reports_cleanup',
                array_filter(['days'=>$days,'profile'=>$profile]));
        }
        return $this->render('reports/cleanup.html.twig',[
            'days'=>$days,'profile'=>$profile,'profiles'=>$profiles,
            'eligible'=>$retention->estimate($days,$profile)
        ]);
    }

    #[Route('/reports',name:'app_reports',methods:['GET'])]
    public function index(Request $request,ReportRepository $reports): Response
    {
        $profile=$this->positiveCursor($request,'profile');
        $before=$this->positiveCursor($request,'before');
        $after=$this->positiveCursor($request,'after');
        if($before!==null && $after!==null)
            throw $this->createNotFoundException('Only one history cursor is allowed.');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','desc');
        $state=(string)$request->query->get('state','');
        if(!isset(ReportRepository::RUN_SORTS[$sort]) ||
           !in_array($dir,['asc','desc'],true) ||
           ($state!=='' && !in_array($state,
               ['queued','running','completed','archived','failed','cancelled'],true)))
            throw $this->createNotFoundException('Unknown scan-history filter or sorting.');
        $snapshots=$reports->snapshots($profile);
        $history=$reports->history($profile,$before,$after,$sort,$dir,$state?:null);
        return $this->render('reports/index.html.twig',[
            'profiles'=>$reports->profiles(),
            'selected_profile'=>$profile,
            'snapshots'=>$snapshots,
            'totals'=>$reports->totals($snapshots),
            'history'=>$history['rows'],
            'next'=>$history['next'],
            'previous'=>$history['previous'],
            'history_total'=>$history['total'],
            'history_sort'=>$sort,'history_dir'=>$dir,'history_state'=>$state,
            'history_filters'=>array_filter([
                'profile'=>$profile,'state'=>$state,'sort'=>$sort,'dir'=>$dir
            ],static fn($v)=>$v!==null && $v!==''),
            'recent_failures'=>$reports->recentFailures()
        ]);
    }

    #[Route('/reports/run/{id<\\d+>}',name:'app_report_run',methods:['GET'])]
    public function run(int $id,Request $request,ReportRepository $reports,ScanHistoryCleanup $cleanup): Response
    {
        $run=$reports->run($id);
        if(!$run) throw $this->createNotFoundException('Scan run not found.');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','asc');
        $status=(string)$request->query->get('status','');
        $states=$reports->flaggedStates($id);
        if(!isset(ReportRepository::FILE_STATE_SORTS[$sort]) ||
           !in_array($dir,['asc','desc'],true) ||
           ($status!=='' && !in_array($status,$states,true)))
            throw $this->createNotFoundException('Invalid file status filter.');
        $flags=$reports->flaggedFiles($id,$this->positiveCursor($request,'after'),
            $sort,$dir,$status?:null,$this->positiveCursor($request,'before'));
        return $this->render('reports/run.html.twig',[
            'run'=>$run,
            'flagged'=>$flags['rows'],
            'next'=>$flags['next'],'previous'=>$flags['previous'],
            'flagged_total'=>$flags['total'],'file_states'=>$states,
            'file_sort'=>$sort,'file_dir'=>$dir,'file_status'=>$status,
            'file_filters'=>array_filter(['id'=>$id,'sort'=>$sort,'dir'=>$dir,'status'=>$status],
                static fn($v)=>$v!==''),
            'warnings'=>$reports->warnings($id),
            'cleanup'=>$this->isGranted('ROLE_ADMIN')?$cleanup->inspect($id):null
        ]);
    }

    #[Route('/reports/run/{id<\\d+>}/remove',name:'app_report_remove',methods:['POST'])]
    public function remove(int $id,Request $request,ScanHistoryCleanup $cleanup): Response
    {
        if(!$this->isCsrfTokenValid('remove_scan_'.$id,(string)$request->request->get('_token','')))
            throw $this->createAccessDeniedException('Invalid scan removal token.');
        $actor=$this->getUser();
        if(!$actor instanceof Account) throw $this->createAccessDeniedException();
        try {
            $old=$cleanup->remove($id,$actor->id,(string)$request->request->get('confirm',''));
            $this->addFlash('success','Scan #'.$id.' removed from the database. '.
                ($old['is_current']?'Current results cleared; older runs remain historical.':'Current results preserved.'));
            return $this->redirectToRoute('app_reports',['profile'=>$old['profile_id']]);
        } catch(\DomainException $ex) {
            $this->addFlash('warning',$ex->getMessage());
            return $this->redirectToRoute('app_report_run',['id'=>$id]);
        }
    }

    #[Route(
        '/reports/run/{id<\\d+>}/export/{kind<files|duplicates|states|events>}',
        name:'app_report_csv',methods:['GET']
    )]
    public function csv(int $id,string $kind,ReportRepository $reports): StreamedResponse
    {
        if(!$reports->run($id)) throw $this->createNotFoundException('Scan run not found.');
        $response=new StreamedResponse(static function() use ($reports,$id,$kind): void {
            $output=fopen('php://output','wb');
            if($output===false) throw new \RuntimeException('Cannot open CSV output stream.');
            try {
                // Windows spreadsheet programs recognize UTF-8 content reliably.
                fwrite($output,"\xEF\xBB\xBF");
                [$headers,$result]=$reports->csv($id,$kind);
                fputcsv($output,$headers,',','"','');
                while(($row=$result->fetchAssociative())!==false) {
                    $safe=[];
                    foreach($row as $value) {
                        $text=$value===null?'':(string)$value;
                        // Quoted CSV alone does not prevent spreadsheet formulas.
                        // Protect values beginning with formulas after whitespace.
                        if(preg_match('/^[\\p{Z}\\p{C}\\x00-\\x20]*[=+\\-@]/u',$text)===1)
                            $text="'".$text;
                        $safe[]=$text;
                    }
                    fputcsv($output,$safe,',','"','');
                }
            } finally {
                fclose($output);
            }
        });
        $response->headers->set('Content-Type','text/csv; charset=UTF-8');
        $response->headers->set('Content-Disposition',
            'attachment; filename="dup-run-'.$id.'-'.$kind.'.csv"');
        $response->headers->set('Cache-Control','private, no-store, max-age=0');
        $response->headers->set('X-Content-Type-Options','nosniff');
        return $response;
    }
}
