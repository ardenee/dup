<?php
declare(strict_types=1);

namespace App\Module\Jobs\Repository;

use Doctrine\DBAL\Connection;

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
    public function page(?int $profile,?string $state,?int $before,?int $after):array
    {
        if($before!==null && $after!==null)
            throw new \DomainException('Only one job paging cursor may be used.');
        $where=' FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id '.
            'LEFT JOIN scan_runs r ON r.id=j.run_id WHERE 1=1';
        $base=[];
        if($profile!==null) {
            $where.=' AND j.profile_id=:profile';
            $base['profile']=$profile;
        }
        if($state!==null && $state!=='') {
            $where.=' AND j.state=:state';
            $base['state']=$state;
        }
        $total=(int)$this->db->fetchOne('SELECT COUNT(*)'.$where,$base);
        $params=$base;
        $pageWhere=$where;
        if($before!==null) {
            $pageWhere.=' AND j.id<:cursor';
            $params['cursor']=$before;
            $order=' ORDER BY j.id DESC';
        } elseif($after!==null) {
            $pageWhere.=' AND j.id>:cursor';
            $params['cursor']=$after;
            $order=' ORDER BY j.id ASC';
        } else $order=' ORDER BY j.id DESC';

        $rows=$this->db->fetchAllAssociative(
            'SELECT j.id,j.kind,j.phase,j.state,j.progress_current,j.progress_total,'.
            'j.created_at,j.started_at,j.finished_at,j.heartbeat_at,j.error_code,'.
            'j.history_prunable_at,'.
            'j.run_id,j.file_action_batch_id,j.profile_id,p.name AS profile_name,'.
            'r.state AS run_state '.
            $pageWhere.$order.' LIMIT '.(self::PAGE_SIZE+1),$params
        );
        if(count($rows)>self::PAGE_SIZE) array_pop($rows);
        if($after!==null) $rows=array_reverse($rows);

        $first=$rows?(int)$rows[0]['id']:null;
        $last=$rows?(int)$rows[count($rows)-1]['id']:null;
        $previous=$first!==null && $this->db->fetchOne(
            'SELECT 1'.$where.' AND j.id>:cursor LIMIT 1',
            array_merge($base,['cursor'=>$first])
        ) ? $first : null;
        $next=$last!==null && $this->db->fetchOne(
            'SELECT 1'.$where.' AND j.id<:cursor LIMIT 1',
            array_merge($base,['cursor'=>$last])
        ) ? $last : null;
        return compact('rows','total','previous','next');
    }

    /**
     * @return array{job:array<string,mixed>,events:list<array<string,mixed>>,
     * previous:?int,next:?int}|false
     */
    public function detail(int $id,?int $before=null,?int $after=null):array|false
    {
        if($before!==null && $after!==null)
            throw new \DomainException('Only one event paging cursor may be used.');
        $job=$this->db->fetchAssociative(
            'SELECT j.*,p.name AS profile_name,r.state AS run_state '.
            'FROM jobs j LEFT JOIN scan_profiles p ON p.id=j.profile_id '.
            'LEFT JOIN scan_runs r ON r.id=j.run_id WHERE j.id=?',[$id]
        );
        if(!$job) return false;
        $query='SELECT id,severity,code,context,created_at FROM job_events WHERE job_id=?';
        $params=[$id];
        if($before!==null) {
            $query.=' AND id<?';
            $params[]=$before;
            $order=' DESC';
        } elseif($after!==null) {
            $query.=' AND id>?';
            $params[]=$after;
            $order=' ASC';
        } else $order=' DESC';
        $events=$this->db->fetchAllAssociative($query.' ORDER BY id'.$order.
            ' LIMIT '.(self::EVENT_PAGE_SIZE+1),$params);
        if(count($events)>self::EVENT_PAGE_SIZE) array_pop($events);
        if($after!==null) $events=array_reverse($events);
        foreach($events as &$event) {
            $decoded=json_decode((string)($event['context']??'{}'),true);
            $event['fields']=is_array($decoded)?$decoded:[];
        }
        unset($event);
        $first=$events?(int)$events[0]['id']:null;
        $last=$events?(int)$events[count($events)-1]['id']:null;
        $previous=$first!==null && $this->db->fetchOne(
            'SELECT 1 FROM job_events WHERE job_id=? AND id>? LIMIT 1',
            [$id,$first]
        )?$first:null;
        $next=$last!==null && $this->db->fetchOne(
            'SELECT 1 FROM job_events WHERE job_id=? AND id<? LIMIT 1',
            [$id,$last]
        )?$last:null;
        return ['job'=>$job,'events'=>$events,'previous'=>$previous,'next'=>$next];
    }
}
