<?php
declare(strict_types=1);
namespace App\Module\Reports\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Database-only scan retention. Never touches indexed filesystem paths.
 * Historical runs can be removed without changing the current profile result.
 * Clearing the current completed run archives older successful snapshots, so
 * previous results are never promoted back into live searches.
 */
final class ScanHistoryCleanup
{
    public function __construct(private readonly Connection $db) {}

    public function inspect(int $runId): array|false
    {
        $run=$this->db->fetchAssociative(
            'SELECT r.id,r.profile_id,r.state,r.started_at,r.finished_at,'.
            'p.name AS profile_name '.
            'FROM scan_runs r JOIN scan_profiles p ON p.id=r.profile_id '.
            'WHERE r.id=?',[$runId]
        );
        if (!$run) return false;
        $profileId=(int)$run['profile_id'];
        $latest=(int)$this->db->fetchOne(
            "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? ".
            "AND state='completed'",[$profileId]
        );
        $run['is_current']=$run['state']==='completed' && (int)$run['id']===$latest;
        $run['files']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_entries WHERE run_id=?',[$runId]
        );
        $run['directories']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM directories WHERE run_id=?',[$runId]
        );
        $run['groups']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM duplicate_groups WHERE run_id=?',[$runId]
        );
        $run['jobs']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM jobs WHERE run_id=?',[$runId]
        );
        // Action records may be needed years later for safe restore. Their
        // file-entry foreign key must never be silently removed or cascaded.
        $run['action_refs']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items i '.
            'JOIN file_entries f ON f.id=i.file_entry_id WHERE f.run_id=?',[$runId]
        );
        $run['survivor_refs']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items i '.
            'JOIN file_entries f ON f.id=i.survivor_file_entry_id WHERE f.run_id=?',[$runId]
        );
        $run['active_jobs']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM jobs WHERE active_profile_id=?',[$profileId]
        );
        $run['unfinished_jobs']=(int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE run_id=? ".
            "AND state NOT IN ('completed','failed','cancelled')",[$runId]
        );
        $reasons=[];
        if (!in_array($run['state'],['completed','archived','failed','cancelled'],true))
            $reasons[]='This scan has not reached a terminal state.';
        if ($run['active_jobs']>0 || $run['unfinished_jobs']>0)
            $reasons[]='Wait until all jobs for the profile have finished.';
        if ($run['action_refs']>0 || $run['survivor_refs']>0)
            $reasons[]='File-action or recovery records reference this scan. They must be preserved for audit and restore.';
        $run['blocked_reason']=implode(' ',$reasons);
        $run['can_remove']=$reasons===[];
        return $run;
    }

    /**
     * A single transaction protects the watermark and run cleanup together.
     * The confirmation is rechecked against the current DB state.
     */
    public function remove(int $runId,int $actorId,string $confirmation): array
    {
        return $this->db->transactional(function(Connection $db) use (
            $runId,$actorId,$confirmation
        ):array {
            $preview=$this->inspect($runId);
            if (!$preview) throw new \DomainException('Scan run no longer exists.');
            $profile=(int)$preview['profile_id'];
            if ($db->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                $db->fetchOne('SELECT id FROM scan_profiles WHERE id=? FOR UPDATE',[$profile]);
                $db->fetchOne('SELECT id FROM scan_runs WHERE id=? FOR UPDATE',[$runId]);
            }
            // Repeat all checks after obtaining the row locks.
            $preview=$this->inspect($runId);
            if (!$preview || !$preview['can_remove'])
                throw new \DomainException($preview['blocked_reason']??'Scan run is unavailable.');
            $expected=$preview['is_current']?'CLEAR':'DELETE';
            if ($confirmation!==$expected)
                throw new \DomainException('Type '.$expected.' to confirm the scan cleanup.');

            if ($preview['is_current']) {
                $db->executeStatement(
                    "UPDATE scan_runs SET state='archived' WHERE profile_id=? ".
                    "AND state='completed' AND id<?",[$profile,$runId]
                );
            }
            // Keep completed job records and their event log. Only detach the
            // foreign key, not the audit details stored in job_events.
            $db->executeStatement(
                'UPDATE jobs SET run_id=NULL WHERE run_id=?',[$runId]
            );
            // The group-members FK cascades from duplicate_groups. Explicit
            // ordering ensures no remaining child references before file rows.
            $db->executeStatement('DELETE FROM duplicate_groups WHERE run_id=?',[$runId]);
            $db->executeStatement('DELETE FROM file_entries WHERE run_id=?',[$runId]);
            $db->executeStatement('DELETE FROM directories WHERE run_id=?',[$runId]);
            $deleted=$db->executeStatement('DELETE FROM scan_runs WHERE id=?',[$runId]);
            if ($deleted!==1) throw new \LogicException('Scan cleanup did not remove exactly one run.');
            $db->insert('audit_events',[
                'actor_id'=>$actorId,'action'=>'SCAN_HISTORY_REMOVED',
                'entity_type'=>'scan_run','entity_id'=>(string)$runId,
                'correlation_id'=>bin2hex(random_bytes(16)),
                'outcome'=>'completed','created_at'=>gmdate('Y-m-d H:i:s'),
                'context'=>json_encode([
                    'profile_id'=>$profile,'current_results_cleared'=>$preview['is_current'],
                    'files'=>$preview['files'],'directories'=>$preview['directories'],
                    'groups'=>$preview['groups'],'jobs_retained'=>$preview['jobs']
                ],JSON_THROW_ON_ERROR)
            ]);
            return $preview;
        });
    }
}
