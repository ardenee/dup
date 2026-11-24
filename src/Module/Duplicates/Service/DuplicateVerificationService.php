<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Optional second stage. Hash only indexed candidate-group members from the
 * latest completed run, never all the original scan's files.
 */
final class DuplicateVerificationService
{
    public function __construct(private readonly Connection $db) {}

    public function eligibility(int $profileId):array
    {
        $run=$this->db->fetchAssociative(
            'SELECT r.id,r.state,r.verification_method,r.verified_at,p.name AS profile_name '.
            'FROM scan_profiles p JOIN scan_runs r ON r.id='.
            "(SELECT MAX(id) FROM scan_runs WHERE profile_id=p.id AND state='completed') ".
            'WHERE p.id=?',[$profileId]
        );
        if(!$run) return ['run_id'=>0,'files'=>0,'groups'=>0,'can_verify'=>false];
        $groups=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM duplicate_groups WHERE run_id=? AND rules_version<>1',
            [(int)$run['id']]
        );
        $files=$groups>0?(int)$this->db->fetchOne(
            'SELECT COUNT(DISTINCT m.file_entry_id) FROM duplicate_group_members m '.
            'JOIN duplicate_groups g ON g.id=m.group_id '.
            'WHERE g.run_id=? AND g.rules_version<>1',
            [(int)$run['id']]
        ):0;
        return [
            'run_id'=>(int)$run['id'],'groups'=>$groups,'files'=>$files,
            'can_verify'=>$groups>0 && $files>1,
            'profile_name'=>$run['profile_name'],
            'verified_at'=>$run['verified_at']
        ];
    }

    public function queue(int $profileId,int $actorId):int
    {
        return $this->db->transactional(function(Connection $db) use($profileId,$actorId):int {
            $info=$this->eligibility($profileId);
            if(!$info['can_verify'])
                throw new \DomainException('No unverified duplicate candidates are available for this profile.');
            if($db->fetchOne('SELECT id FROM jobs WHERE active_profile_id=?',[$profileId]))
                throw new \DomainException('Another scan or file action is active for this profile.');
            try {
                $db->insert('jobs',[
                    'kind'=>'duplicate-verify','profile_id'=>$profileId,
                    'active_profile_id'=>$profileId,'run_id'=>$info['run_id'],
                    'state'=>'queued','phase'=>'waiting','progress_current'=>0,
                    'progress_total'=>$info['files'],
                    'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
                ]);
            } catch(UniqueConstraintViolationException $ex) {
                throw new \DomainException('Another job is already active for this profile.',0,$ex);
            }
            $jobId=(int)$db->lastInsertId();
            $db->insert('audit_events',[
                'actor_id'=>$actorId,'action'=>'DUPLICATES_VERIFICATION_QUEUED',
                'entity_type'=>'scan_run','entity_id'=>(string)$info['run_id'],
                'correlation_id'=>bin2hex(random_bytes(16)),
                'outcome'=>'queued','created_at'=>gmdate('Y-m-d H:i:s'),
                'context'=>json_encode([
                    'job_id'=>$jobId,'files'=>$info['files'],'algorithm'=>'sha256'
                ],JSON_THROW_ON_ERROR)
            ]);
            return $jobId;
        });
    }
}
