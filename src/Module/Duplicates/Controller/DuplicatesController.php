<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Controller;

use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;
use App\Module\Duplicates\Repository\ScanSelectionRepository;
use App\Module\Duplicates\Service\DuplicateVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DuplicatesController extends AbstractController
{
    private const PAGE_GROUPS=50;
    private const PAGE_MEMBERS=100;
    private const MEMBER_SORTS=[
        'id'=>'m.id','file'=>'f.filename','folder'=>'d.path',
        'size'=>'f.size_bytes','modified'=>'f.mtime_epoch',
        'hash'=>"COALESCE(f.hash_value,'')"
    ];
    private const SORTS=[
        'id'=>'g.id',
        'file'=>"COALESCE(firstf.filename,'')",
        'profile'=>'p.name',
        'size'=>'g.size_bytes',
        'matches'=>'g.file_count',
        'savings'=>'g.reclaimable_bytes',
        'modified'=>'COALESCE(firstf.mtime_epoch,0)',
        'confidence'=>'g.rules_version'
    ];

    /** Parse keyset cursor. No unbounded OFFSET and no hidden form input limits. */
    private function cursor(Request $request,string $key): ?int
    {
        $value=$request->query->get($key);
        if($value===null) return null;
        $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        return $id===false?null:$id;
    }

    #[Route('/duplicates',name:'app_duplicates',methods:['GET'])]
    public function index(Request $request,Connection $db,ScanSelectionRepository $selector,
        DuplicateVerificationService $verificationService): Response
    {
        $profiles=$selector->profiles();
        $profile=$this->cursor($request,'profile');
        $selectedRun=$this->cursor($request,'run');
        $scanBefore=$this->cursor($request,'scan_before');
        $historical=false;
        $runInfo=null;
        if($selectedRun!==null) {
            $runInfo=$selector->selectedRun($selectedRun,$profile);
            if(!$runInfo)
                throw $this->createNotFoundException('No completed scan matches this selection.');
            $profile=(int)$runInfo['profile_id'];
            $historical=(bool)$runInfo['is_historical'];
        }
        $latestRunId=null;
        if($profile!==null) foreach($profiles as $profileRow) {
            if((int)$profileRow['id']===$profile) {
                $latestRunId=$profileRow['run_id']===null?null:(int)$profileRow['run_id'];
                break;
            }
        }
        if($scanBefore!==null && $profile===null)
            throw $this->createNotFoundException('Choose a profile before paging its scans.');
        $history=$profile!==null?$selector->runs($profile,$scanBefore):['rows'=>[],'more'=>false];
        $scanChoicesLast=$history['rows']?(int)$history['rows'][count($history['rows'])-1]['id']:null;
        if($scanBefore!==null && !$history['rows'])
            throw $this->createNotFoundException('No older scans were found.');
        // A profile whose current results were cleared should still open its
        // latest retained snapshot, clearly marked historical and read-only.
        if($profile!==null && $selectedRun===null && $history['rows'] &&
            ($scanBefore!==null || $latestRunId===null)) {
            $selectedRun=(int)$history['rows'][0]['id'];
            $runInfo=$selector->selectedRun($selectedRun,$profile);
            $historical=true;
        }
        // A bookmarked older run may lie beyond the 75 recent choices.
        // Keep its selection visible without making the chooser unbounded.
        if($runInfo && !array_filter($history['rows'],
            static fn(array $run):bool=>(int)$run['id']===$selectedRun)) {
            array_unshift($history['rows'],$runInfo);
        }
        $verification=$profile!==null && !$historical
            ?$verificationService->eligibility($profile):null;
        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        $where=" FROM duplicate_groups g " .
            "JOIN scan_runs r ON r.id=g.run_id JOIN scan_profiles p ON p.id=r.profile_id " .
            "LEFT JOIN duplicate_group_members firstm ON firstm.id=" .
            "(SELECT MIN(fm.id) FROM duplicate_group_members fm WHERE fm.group_id=g.id) " .
            "LEFT JOIN file_entries firstf ON firstf.id=firstm.file_entry_id " .
            "LEFT JOIN directories firstd ON firstd.id=firstf.directory_id " .
            ($selectedRun!==null
                ? 'WHERE g.run_id=:selected_run'
                : "WHERE r.state='completed' AND r.id=(SELECT MAX(id) FROM scan_runs " .
                  "WHERE profile_id=r.profile_id AND state='completed')");
        $params=$selectedRun!==null?['selected_run'=>$selectedRun]:[];
        if($profile!==null && $selectedRun===null) {
            $where.=' AND p.id=:profile';
            $params['profile']=$profile;
        }
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','asc');
        if(!isset(self::SORTS[$sort]) || !in_array($dir,['asc','desc'],true))
            throw $this->createNotFoundException('Invalid duplicate group ordering.');
        $name=trim((string)$request->query->get('name',''));
        if(mb_strlen($name)>200) throw $this->createNotFoundException('File filter is too long.');
        $confidence=(string)$request->query->get('confidence','');
        if(!in_array($confidence,['','verified','candidate'],true))
            throw $this->createNotFoundException('Invalid duplicate match type.');
        $rawFiles=$request->query->get('min_files','');
        $minFiles=($rawFiles==='' || $rawFiles===null)?null:
            filter_var($rawFiles,FILTER_VALIDATE_INT,['options'=>['min_range'=>2,'max_range'=>100000]]);
        $rawSize=$request->query->get('min_size','');
        $minSize=($rawSize==='' || $rawSize===null)?null:
            filter_var($rawSize,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
        if($minFiles===false || $minSize===false)
            throw $this->createNotFoundException('Invalid duplicate group filter.');
        if($name!=='') {
            $where.=' AND EXISTS(SELECT 1 FROM duplicate_group_members fm '.
                'JOIN file_entries ff ON ff.id=fm.file_entry_id '.
                'JOIN directories fd ON fd.id=ff.directory_id '.
                "WHERE fm.group_id=g.id AND (LOWER(ff.filename) LIKE :filename_a ESCAPE '!' ".
                "OR LOWER(fd.path) LIKE :filename_b ESCAPE '!'))";
            $pattern=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($name));
            $params['filename_a']='%'.$pattern.'%';
            $params['filename_b']='%'.$pattern.'%';
        }
        if($confidence==='verified') $where.=' AND g.rules_version=1';
        if($confidence==='candidate') $where.=' AND g.rules_version<>1';
        if($minFiles!==null) {
            $where.=' AND g.file_count>=:min_files';
            $params['min_files']=$minFiles;
        }
        if($minSize!==null) {
            $where.=' AND g.size_bytes>=:min_size';
            $params['min_size']=$minSize;
        }
        // Duplicates historically names its cursors "before" (previous) and
        // "after" (next). KeysetPage uses the opposite conventional names.
        $page=KeysetPage::fetch(
            $db,
            'g.id,g.size_bytes,g.file_count,g.reclaimable_bytes,'.
            'g.rules_version,p.name AS profile_name,p.id AS profile_id,'.
            'p.source_root,firstf.id AS first_file_id,'.
            'firstf.filename AS first_filename,firstf.mtime_epoch AS first_mtime_epoch,'.
            'firstf.hash_value AS first_hash_value,firstf.error_code AS first_error_code,'.
            'firstd.path AS first_directory',
            $where,$params,'g.id',self::SORTS[$sort],strtoupper($dir),
            self::PAGE_GROUPS,$after,$before,
            in_array($sort,['id','size','matches','savings','modified','confidence'],true)
        );
        $rows=$page['rows'];
        foreach($rows as &$row) {
            $directory=str_replace('\\','/',(string)($row['first_directory']??''));
            $root=rtrim(str_replace('\\','/',(string)$row['source_root']),'/');
            $filename=(string)($row['first_filename']??'');
            $relative=$directory===$root?'':(
                $root!=='' && str_starts_with($directory,$root.'/')
                    ? ltrim(substr($directory,strlen($root)),'/').'/'
                    : ltrim($directory,'/').'/'
            );
            $row['relative_path']=$relative.$filename;
            $row['full_path']=rtrim($directory,'/').'/'.$filename;
        }
        unset($row);
        $prior=$page['previous']!==null;
        $later=$page['next']!==null;
        $baseParams=$params;
        $totals=$db->fetchAssociative(
            'SELECT COUNT(*) AS total_groups, '.
            'COALESCE(SUM(g.file_count),0) AS total_members, '.
            'COALESCE(SUM(g.reclaimable_bytes),0) AS reclaimable_bytes '.
            $where,$baseParams
        );
        $pageStart=$page['start'];
        $pageEnd=$rows?$pageStart+count($rows)-1:0;
        $pageRows=array_sum(array_map(static fn(array $g)=>(int)$g['file_count'],$rows));
        $pageListedBytes=array_sum(array_map(
            static fn(array $g)=>(int)$g['file_count']*(int)$g['size_bytes'],$rows
        ));
        $pageReclaimable=array_sum(array_map(
            static fn(array $g)=>(int)$g['reclaimable_bytes'],$rows
        ));
        return $this->render('duplicates/index.html.twig',[
            'groups'=>$rows,'profiles'=>$profiles,'selected_profile'=>$profile,
            'selected_run'=>$selectedRun,'run_info'=>$runInfo,'historical'=>$historical,
            'latest_run_id'=>$latestRunId,'verification'=>$verification,
            'scan_choices'=>$history['rows'],'scan_choices_more'=>$history['more'],
            'scan_before'=>$scanBefore,'scan_choices_last'=>$scanChoicesLast,
            'previous'=>$page['previous'],'next'=>$page['next'],
            'sort'=>$sort,'dir'=>$dir,'name_filter'=>$name,
            'confidence_filter'=>$confidence,'min_files'=>$minFiles,'min_size'=>$minSize,
            'table_filters'=>array_filter([
                'profile'=>$profile,'run'=>$selectedRun,
                'sort'=>$sort,'dir'=>$dir,'name'=>$name,
                'confidence'=>$confidence,'min_files'=>$minFiles,'min_size'=>$minSize
            ],static fn($v)=>$v!==null && $v!==''),
            'totals'=>$totals ?: ['total_groups'=>0,'total_members'=>0,'reclaimable_bytes'=>0],
            'page_start'=>$pageStart,'page_end'=>$pageEnd,'page_rows'=>$pageRows,
            'page_listed_bytes'=>$pageListedBytes,'page_reclaimable'=>$pageReclaimable
        ]);
    }

    #[Route('/duplicates/{id<\\d+>}',name:'app_duplicate_group',methods:['GET'])]
    public function group(int $id,Request $request,Connection $db): Response
    {
        $group=$db->fetchAssociative(
            "SELECT g.id,g.group_key,g.size_bytes,g.file_count,g.reclaimable_bytes," .
            "g.rules_version,r.id AS run_id,r.state AS run_state,p.id AS profile_id," .
            "p.name AS profile_name " .
            "FROM duplicate_groups g JOIN scan_runs r ON r.id=g.run_id " .
            "JOIN scan_profiles p ON p.id=r.profile_id " .
            "WHERE g.id=? AND r.state IN ('completed','archived')",[$id]
        );
        if(!$group) throw $this->createNotFoundException('Duplicate group not found.');
        $currentId=(int)$db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
            [(int)$group['profile_id']]
        );
        $historical=$currentId!==(int)$group['run_id'] || $group['run_state']!=='completed';

        $after=$this->cursor($request,'after');
        $before=$this->cursor($request,'before');
        $sort=(string)$request->query->get('sort','id');
        $dir=(string)$request->query->get('dir','asc');
        $filename=trim((string)$request->query->get('name',''));
        $status=(string)$request->query->get('status','');
        if(!isset(self::MEMBER_SORTS[$sort]) ||
           !in_array($dir,['asc','desc'],true) ||
           !in_array($status,['','available','changed'],true) ||
           mb_strlen($filename)>200)
            throw $this->createNotFoundException('Invalid group member filter.');
        $where=' FROM duplicate_group_members m JOIN file_entries f ON f.id=m.file_entry_id '.
            'JOIN directories d ON d.id=f.directory_id WHERE m.group_id=:group';
        $params=['group'=>$id];
        if($filename!=='') {
            $pattern=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($filename));
            $where.=" AND (LOWER(f.filename) LIKE :file_filter ESCAPE '!' ".
                "OR LOWER(d.path) LIKE :folder_filter ESCAPE '!')";
            $params['file_filter']='%'.$pattern.'%';
            $params['folder_filter']='%'.$pattern.'%';
        }
        if($status==='available') $where.=' AND f.error_code IS NULL';
        if($status==='changed') $where.=' AND f.error_code IS NOT NULL';
        $page=KeysetPage::fetch(
            $db,'m.id AS id,m.id AS member_id,f.id AS file_entry_id,'.
            'd.path,f.filename,f.size_bytes,f.mtime_epoch,f.hash_value,f.error_code',
            $where,$params,'m.id',self::MEMBER_SORTS[$sort],strtoupper($dir),
            self::PAGE_MEMBERS,$after,$before,
            in_array($sort,['id','size','modified'],true)
        );
        return $this->render('duplicates/group.html.twig',[
            'group'=>$group,'members'=>$page['rows'],'historical'=>$historical,
            'previous'=>$page['previous'],'next'=>$page['next'],
            'total_members'=>$page['total'],'sort'=>$sort,'dir'=>$dir,
            'name_filter'=>$filename,'status_filter'=>$status,
            'table_filters'=>array_filter([
                'id'=>$id,'sort'=>$sort,'dir'=>$dir,'name'=>$filename,'status'=>$status
            ],static fn($v)=>$v!=='')
        ]);
    }
}
