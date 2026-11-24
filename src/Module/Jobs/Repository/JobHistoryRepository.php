<?php
declare(strict_types=1);

namespace App\Module\Jobs\Repository;

use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;

/** Bounded, read-only job monitor with stable keyset pagination. */
final class JobHistoryRepository
{
    private const PAGE_SIZE=50;
    private const EVENT_PAGE_SIZE=100;

    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> */
    public function profiles():array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id,name FROM scan_profiles ORDER BY name,id'
        );
    }

    /**
     * @return array{rows:list<array<string,mixed>>,total:int,previous:?int,next:?int}
     */
    public const SORTS=[
        'id'=>'j.id',
        'profile'=>"COALESCE(p.name,'')",
        'type'=>'j.kind',
        'stage'=>'j.phase',
        'status'=>'j.state',
        'progress'=>'j.progress_current',
        'created'=>"COALESCE(j.created_at,'')",
        'heartbeat'=>"COALESCE(j.heartbeat_at,'')",
        'finished'=>"COALESCE(j.finished_at,'')"
    ];

    public function page(
        ?int $profile,?string $state,?int $before,?int $after,
        string $sort='id',string $direction='desc',?string $kind=null
    ):array
    {
        if(!isset(self::SORTS[$sort]) || !in_array($direction,['asc','desc'],true))
            throw new \DomainException('Invalid job table sorting.');
        $where=' FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id '.
            'LEFT JOIN scan_runs r ON r.id=j.run_id WHERE 1=1';
        $params=[];
        if($profile!==null) {
            $where.=' AND j.profile_id=:profile';
            $params['profile']=$profile;
        }
        if($state!==null && $state!=='') {
            $where.=' AND j.state=:state';
            $params['state']=$state;
        }
        if($kind!==null && $kind!=='') {
            $where.=' AND j.kind=:kind';
            $params['kind']=$kind;
        }
        $page=KeysetPage::fetch(
            $this->db,
            'j.id,j.kind,j.phase,j.state,j.progress_current,j.progress_total,'.
            'j.created_at,j.started_at,j.finished_at,j.heartbeat_at,j.error_code,'.
            'j.progress_bytes_current,j.progress_bytes_total,j.progress_detail,'.
            'j.last_progress_at,j.last_failure_reason,'.
            'j.history_prunable_at,j.run_id,j.file_action_batch_id,j.profile_id,'.
            'j.active_profile_id,'.
            'p.name AS profile_name,r.state AS run_state',
            $where,$params,'j.id',self::SORTS[$sort],strtoupper($direction),
            self::PAGE_SIZE,$before,$after,in_array($sort,['id','progress'],true)
        );
        $page['rows']=$this->attachActionOutcomes($page['rows']);
        return $page;
    }

    /** Completed-job outcome is immutable in job_events even after a later restore. */
    public function attachActionOutcomes(array $jobs):array
    {
        if(!$jobs) return $jobs;
        $ids=array_map(static fn(array $j):int=>(int)$j['id'],$jobs);
        $params=implode(',',array_fill(0,count($ids),'?'));
        $events=$this->db->fetchAllAssociative(
            'SELECT job_id,code,context FROM job_events WHERE job_id IN ('.$params.') '.
            "AND code IN ('DELETE_BATCH_FINISHED','BULK_BATCH_FINISHED',".
            "'MOVE_BATCH_FINISHED','RENAME_BATCH_COMPLETE') ORDER BY id DESC",$ids
        );
        $outcomes=[];
        foreach($events as $event) {
            $key=(int)$event['job_id'];
            if(isset($outcomes[$key])) continue;
            $context=json_decode((string)$event['context'],true);
            if(!is_array($context)) continue;
            $successKey=match((string)$event['code']) {
                'DELETE_BATCH_FINISHED'=>'deleted',
                'BULK_BATCH_FINISHED'=>'successful',
                'MOVE_BATCH_FINISHED'=>'completed',
                'RENAME_BATCH_COMPLETE'=>'renamed',
                default=>null
            };
            if($successKey!==null && isset($context[$successKey],$context['failed']))
                $outcomes[$key]=[(int)$context[$successKey],(int)$context['failed']];
        }
        foreach($jobs as &$job) {
            [$job['action_succeeded'],$job['action_failed']]=$outcomes[(int)$job['id']]??[null,null];
        }
        unset($job);
        return $jobs;
    }

    public const EVENT_SORTS=[
        'id'=>'e.id','when'=>"COALESCE(e.created_at,'')",
        'severity'=>'e.severity','code'=>'e.code'
    ];

    /**
     * @return array{job:array<string,mixed>,events:list<array<string,mixed>>,
     * previous:?int,next:?int}|false
     */
    public function detail(
        int $id,?int $before=null,?int $after=null,
        string $sort='id',string $direction='desc',string $severity=''
    ):array|false
    {
        if($before!==null && $after!==null)
            throw new \DomainException('Only one event paging cursor is allowed.');
        if(!isset(self::EVENT_SORTS[$sort]) ||
            !in_array($direction,['asc','desc'],true) ||
            !in_array($severity,['','info','warning','error'],true))
            throw new \DomainException('Invalid job event sorting or filter.');
        $job=$this->db->fetchAssociative(
            'SELECT j.*,p.name AS profile_name,r.state AS run_state '.
            'FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id '.
            'LEFT JOIN scan_runs r ON r.id=j.run_id WHERE j.id=?',[$id]
        );
        if(!$job) return false;
        $job=$this->attachActionOutcomes([$job])[0];
        $source=' FROM job_events e WHERE e.job_id=:job';
        $params=['job'=>$id];
        if($severity!=='') {
            $source.=' AND e.severity=:severity';
            $params['severity']=$severity;
        }
        $page=KeysetPage::fetch(
            $this->db,'e.id,e.severity,e.code,e.context,e.created_at',
            $source,$params,'e.id',self::EVENT_SORTS[$sort],strtoupper($direction),
            self::EVENT_PAGE_SIZE,$before,$after,$sort==='id'
        );
        $events=$page['rows'];
        foreach($events as &$event) {
            $decoded=json_decode((string)($event['context']??'{}'),true);
            $event['fields']=is_array($decoded)?$decoded:[];
            if(!$event['fields']) {
                $event['fields']=match((string)$event['code']) {
                    'WORKER_STALLED'=>[
                        'Explanation'=>'The job heartbeat exceeded the recovery timeout.'.
                            ' This does not establish whether SHA-256, storage I/O or the worker process caused the delay.',
                        'Last phase'=>(string)($job['phase']??'Unknown'),
                        'Last known progress'=>(int)($job['progress_current']??0).
                            ' / '.(string)($job['progress_total']??'?').' files',
                        'Next step'=>'Check worker and PHP service logs; retry only after confirming no worker is still operating.'
                    ],
                    'WORKER_CLAIMED'=>[
                        'Explanation'=>'The background worker accepted this job.',
                        'Note'=>'Older job events did not record process identity or per-file progress.'
                    ],
                    'VERIFICATION_FAILED'=>[
                        'Explanation'=>'The verification worker reported a failure.',
                        'Next step'=>'Inspect the error code and worker log for its underlying exception.'
                    ],
                    default=>[]
                };
            }
        }
        unset($event);
        return ['job'=>$job,'events'=>$events,
            'previous'=>$page['previous'],'next'=>$page['next'],
            'event_total'=>$page['total']];
    }

}
