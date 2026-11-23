<?php
declare(strict_types=1);

namespace App\Module\Reports\Repository;

use Doctrine\DBAL\Connection;

/**
 * Read-only reporting over immutable scan runs. Never opens server
 * filesystem paths and never aggregates all historical snapshots as if they
 * were current storage.
 */
final class ReportRepository
{
    private const PAGE_RUNS=25;
    private const PAGE_STATES=50;

    public function __construct(private readonly Connection $db) {}

    public function profiles(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id,name FROM scan_profiles ORDER BY name,id'
        );
    }

    /** Latest successful snapshot per profile only, including run grouping state. */
    public function snapshots(?int $profileId=null): array
    {
        $sql="SELECT p.id AS profile_id,p.name AS profile_name,r.id AS run_id," .
            "r.finished_at,r.files_seen,r.files_indexed,r.bytes_indexed," .
            "r.duplicate_groups,r.error_count," .
            "COALESCE((SELECT SUM(g.reclaimable_bytes) FROM duplicate_groups g " .
            "WHERE g.run_id=r.id AND g.rules_version=1),0) AS verified_bytes," .
            "COALESCE((SELECT SUM(g.reclaimable_bytes) FROM duplicate_groups g " .
            "WHERE g.run_id=r.id AND g.rules_version=0),0) AS candidate_bytes," .
            "COALESCE((SELECT COUNT(*) FROM duplicate_groups g " .
            "WHERE g.run_id=r.id AND g.rules_version=1),0) AS verified_groups," .
            "COALESCE((SELECT COUNT(*) FROM duplicate_groups g " .
            "WHERE g.run_id=r.id AND g.rules_version=0),0) AS candidate_groups " .
            "FROM scan_profiles p LEFT JOIN scan_runs r ON r.id=(" .
            "SELECT MAX(latest.id) FROM scan_runs latest " .
            "WHERE latest.profile_id=p.id AND latest.state='completed') ";
        $args=[];
        if($profileId!==null) {
            $sql.='WHERE p.id=? ';
            $args[]=$profileId;
        }
        return $this->db->fetchAllAssociative($sql.'ORDER BY p.name,p.id',$args);
    }

    /** @return array<string,int> */
    public function totals(array $snapshots): array
    {
        $totals=[
            'profiles'=>count($snapshots),'completed_profiles'=>0,
            'files_indexed'=>0,'bytes_indexed'=>0,'duplicate_groups'=>0,
            'verified_groups'=>0,'candidate_groups'=>0,
            'verified_bytes'=>0,'candidate_bytes'=>0,'scan_errors'=>0
        ];
        foreach($snapshots as $row) {
            if($row['run_id']===null) continue;
            $totals['completed_profiles']++;
            foreach([
                'files_indexed','bytes_indexed','duplicate_groups',
                'verified_groups','candidate_groups','verified_bytes','candidate_bytes'
            ] as $name) $totals[$name]+=(int)$row[$name];
            $totals['scan_errors']+=(int)$row['error_count'];
        }
        return $totals;
    }

    /** Newer/older keyset navigation with no unbounded SQL OFFSET. */
    public function history(?int $profileId,?int $before,?int $after): array
    {
        if($before!==null && $after!==null)
            throw new \DomainException('Choose only one history cursor.');
        $base=' WHERE 1=1';
        $args=[];
        if($profileId!==null) {
            $base.=' AND r.profile_id=?';
            $args[]=$profileId;
        }
        $where=$base;
        $params=$args;
        if($after!==null) {
            $where.=' AND r.id>?';
            $params[]=$after;
            $sort='ASC';
        } else {
            if($before!==null) {
                $where.=' AND r.id<?';
                $params[]=$before;
            }
            $sort='DESC';
        }
        $rows=$this->db->fetchAllAssociative(
            'SELECT r.id,r.profile_id,p.name AS profile_name,r.state,r.files_seen,' .
            'r.files_indexed,r.bytes_indexed,r.duplicate_groups,r.error_count,' .
            'r.started_at,r.finished_at,j.id AS job_id,j.error_code AS job_error ' .
            'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id ' .
            "LEFT JOIN jobs j ON j.run_id=r.id AND j.kind='scan' " .
            $where.' ORDER BY r.id '.$sort.' LIMIT '.(self::PAGE_RUNS+1),
            $params
        );
        if(count($rows)>self::PAGE_RUNS) $rows=array_slice($rows,0,self::PAGE_RUNS);
        if($after!==null) $rows=array_reverse($rows);
        if(!$rows) return ['rows'=>[],'next'=>null,'previous'=>null];

        $first=(int)$rows[0]['id'];
        $last=(int)$rows[count($rows)-1]['id'];
        $previous=(bool)$this->db->fetchOne(
            'SELECT 1 FROM scan_runs r'.$base.' AND r.id>? LIMIT 1',
            [...$args,$first]
        );
        $next=(bool)$this->db->fetchOne(
            'SELECT 1 FROM scan_runs r'.$base.' AND r.id<? LIMIT 1',
            [...$args,$last]
        );
        return [
            'rows'=>$rows,
            'next'=>$next?$last:null,
            'previous'=>$previous?$first:null
        ];
    }

    /** @return array<string,mixed>|false */
    public function run(int $runId): array|false
    {
        $run=$this->db->fetchAssociative(
            'SELECT r.*,p.name AS profile_name,p.source_root,p.move_root,' .
            'j.id AS job_id,j.state AS job_state,j.error_code AS job_error ' .
            'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id ' .
            "LEFT JOIN jobs j ON j.run_id=r.id AND j.kind='scan' " .
            'WHERE r.id=?',[$runId]
        );
        if(!$run) return false;

        $fileSummary=$this->db->fetchAssociative(
            'SELECT COUNT(*) AS stored_files,' .
            'COALESCE(SUM(CASE WHEN d.is_ignored=1 THEN 1 ELSE 0 END),0) AS ignored_files,' .
            "COALESCE(SUM(CASE WHEN f.error_code='QUARANTINED' THEN 1 ELSE 0 END),0) AS quarantined_files," .
            "COALESCE(SUM(CASE WHEN f.error_code IS NOT NULL AND f.error_code<>'QUARANTINED' THEN 1 ELSE 0 END),0) AS flagged_files," .
            'COALESCE(SUM(CASE WHEN f.hash_method=\'sha256\' AND f.hash_value IS NOT NULL THEN 1 ELSE 0 END),0) AS hashed_files ' .
            'FROM file_entries f JOIN directories d ON d.id=f.directory_id WHERE f.run_id=?',
            [$runId]
        );
        $groupSummary=$this->db->fetchAssociative(
            'SELECT COUNT(*) AS groups,' .
            'COALESCE(SUM(CASE WHEN rules_version=1 THEN 1 ELSE 0 END),0) AS verified_groups,' .
            'COALESCE(SUM(CASE WHEN rules_version=0 THEN 1 ELSE 0 END),0) AS candidate_groups,' .
            'COALESCE(SUM(CASE WHEN rules_version=1 THEN reclaimable_bytes ELSE 0 END),0) AS verified_bytes,' .
            'COALESCE(SUM(CASE WHEN rules_version=0 THEN reclaimable_bytes ELSE 0 END),0) AS candidate_bytes ' .
            'FROM duplicate_groups WHERE run_id=?',[$runId]
        );
        $run['files']=$fileSummary;
        $run['groups']=$groupSummary;
        return $run;
    }

    public function flaggedFiles(int $runId,?int $after): array
    {
        $sql='SELECT f.id,f.filename,d.path AS directory,f.error_code,f.size_bytes,' .
            'f.mtime_epoch,d.is_ignored FROM file_entries f ' .
            'JOIN directories d ON d.id=f.directory_id ' .
            'WHERE f.run_id=? AND f.error_code IS NOT NULL';
        $params=[$runId];
        if($after!==null) {
            $sql.=' AND f.id>?';
            $params[]=$after;
        }
        $rows=$this->db->fetchAllAssociative(
            $sql.' ORDER BY f.id ASC LIMIT '.(self::PAGE_STATES+1),$params
        );
        $next=null;
        if(count($rows)>self::PAGE_STATES) {
            $rows=array_slice($rows,0,self::PAGE_STATES);
            $next=(int)$rows[count($rows)-1]['id'];
        }
        return ['rows'=>$rows,'next'=>$next];
    }

    public function warnings(int $runId): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT e.severity,e.code,e.context,e.created_at,j.id AS job_id ' .
            'FROM job_events e JOIN jobs j ON j.id=e.job_id ' .
            "WHERE j.run_id=? AND e.severity IN ('warning','error') " .
            'ORDER BY e.id DESC LIMIT 40',[$runId]
        );
    }

    /**
     * Stream a constrained report kind, never interpolate user SQL.
     * File rows include ignored entries as requested by the retention policy.
     * Duplicate rows represent currently stored groups for this run.
     */
    public function csv(int $runId,string $kind): array
    {
        return match($kind) {
            'files' => [
                ['run_id','file_id','directory','filename','size_bytes','mtime_epoch',
                 'ignored_directory','hash_method','sha256','file_status'],
                $this->db->executeQuery(
                    'SELECT f.run_id,f.id AS file_id,d.path AS directory,f.filename,' .
                    'f.size_bytes,f.mtime_epoch,d.is_ignored AS ignored_directory,' .
                    'f.hash_method,f.hash_value AS sha256,f.error_code AS file_status ' .
                    'FROM file_entries f JOIN directories d ON d.id=f.directory_id ' .
                    'WHERE f.run_id=? ORDER BY f.id',[$runId]
                )
            ],
            'duplicates' => [
                ['run_id','group_id','verified_sha256','file_count','size_bytes',
                 'potential_reclaimable_bytes','file_id','directory','filename','sha256'],
                $this->db->executeQuery(
                    'SELECT g.run_id,g.id AS group_id,g.rules_version AS verified_sha256,' .
                    'g.file_count,g.size_bytes,g.reclaimable_bytes AS potential_reclaimable_bytes,' .
                    'f.id AS file_id,d.path AS directory,f.filename,f.hash_value AS sha256 ' .
                    'FROM duplicate_groups g ' .
                    'JOIN duplicate_group_members m ON m.group_id=g.id ' .
                    'JOIN file_entries f ON f.id=m.file_entry_id ' .
                    'JOIN directories d ON d.id=f.directory_id ' .
                    'WHERE g.run_id=? ORDER BY g.id,m.id',[$runId]
                )
            ],
            'states' => [
                ['run_id','file_id','directory','filename','file_status','size_bytes','ignored_directory'],
                $this->db->executeQuery(
                    'SELECT f.run_id,f.id AS file_id,d.path AS directory,f.filename,' .
                    'f.error_code AS file_status,f.size_bytes,' .
                    'd.is_ignored AS ignored_directory FROM file_entries f ' .
                    'JOIN directories d ON d.id=f.directory_id ' .
                    'WHERE f.run_id=? AND f.error_code IS NOT NULL ORDER BY f.id',[$runId]
                )
            ],
            'events' => [
                ['run_id','job_id','event_id','created_at_utc','severity','code','context_json'],
                $this->db->executeQuery(
                    'SELECT j.run_id,j.id AS job_id,e.id AS event_id,' .
                    'e.created_at AS created_at_utc,e.severity,e.code,' .
                    'e.context AS context_json FROM job_events e ' .
                    'JOIN jobs j ON j.id=e.job_id WHERE j.run_id=? ' .
                    'ORDER BY e.id',[$runId]
                )
            ],
            default => throw new \DomainException('Unsupported CSV report format.')
        };
    }

    public function recentFailures(): array
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-86400);
        $row=$this->db->fetchAssociative(
            "SELECT COALESCE(SUM(CASE WHEN state='failed' THEN 1 ELSE 0 END),0) AS failed_jobs," .
            "COALESCE(SUM(CASE WHEN state='queued' THEN 1 ELSE 0 END),0) AS queued_jobs," .
            "COALESCE(SUM(CASE WHEN state='running' THEN 1 ELSE 0 END),0) AS running_jobs " .
            'FROM jobs WHERE created_at>=?',[$cutoff]
        );
        return $row ?: ['failed_jobs'=>0,'queued_jobs'=>0,'running_jobs'=>0];
    }
}
