<?php
declare(strict_types=1);

namespace App\Module\Reports\Controller;

use App\Module\Reports\Repository\ReportRepository;
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

    #[Route('/reports',name:'app_reports',methods:['GET'])]
    public function index(Request $request,ReportRepository $reports): Response
    {
        $profile=$this->positiveCursor($request,'profile');
        $before=$this->positiveCursor($request,'before');
        $after=$this->positiveCursor($request,'after');
        if($before!==null && $after!==null)
            throw $this->createNotFoundException('Only one history cursor is allowed.');
        $snapshots=$reports->snapshots($profile);
        $history=$reports->history($profile,$before,$after);
        return $this->render('reports/index.html.twig',[
            'profiles'=>$reports->profiles(),
            'selected_profile'=>$profile,
            'snapshots'=>$snapshots,
            'totals'=>$reports->totals($snapshots),
            'history'=>$history['rows'],
            'next'=>$history['next'],
            'previous'=>$history['previous'],
            'recent_failures'=>$reports->recentFailures()
        ]);
    }

    #[Route('/reports/run/{id<\\d+>}',name:'app_report_run',methods:['GET'])]
    public function run(int $id,Request $request,ReportRepository $reports): Response
    {
        $run=$reports->run($id);
        if(!$run) throw $this->createNotFoundException('Scan run not found.');
        $flags=$reports->flaggedFiles($id,$this->positiveCursor($request,'after'));
        return $this->render('reports/run.html.twig',[
            'run'=>$run,
            'flagged'=>$flags['rows'],
            'next'=>$flags['next'],
            'warnings'=>$reports->warnings($id)
        ]);
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
