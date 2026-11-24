<?php
declare(strict_types=1);
namespace App\Module\Profiles\Repository;

use Doctrine\DBAL\Connection;

/** Profile configuration is mutable; completed scan snapshots are not. */
final class ProfileRepository
{
    public function __construct(private readonly Connection $db) {}

    public function types(): array
    {
        return $this->db->fetchAllAssociative('SELECT id,name,extensions FROM profile_types ORDER BY name');
    }

    public function find(int $id): array|false
    {
        return $this->db->fetchAssociative('SELECT * FROM scan_profiles WHERE id=?',[$id]);
    }

    public function profiles(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT p.id,p.name,p.source_root,p.move_root,p.hash_mode,p.minimum_bytes,p.enabled,p.file_exists_policy, '.
            't.name AS type_name,r.files_indexed,r.duplicate_groups,r.finished_at, '.
            "(SELECT COUNT(*) FROM scan_runs s2 WHERE s2.profile_id=p.id AND ".
            "s2.state IN ('completed','archived')) AS completed_scans, ".
            "(SELECT COUNT(*) FROM scan_runs s WHERE s.profile_id=p.id AND s.state IN ('completed','archived')) AS retained_runs,".
            "(SELECT MAX(s.finished_at) FROM scan_runs s WHERE s.profile_id=p.id AND s.state IN ('completed','archived')) AS last_saved_at,".
            'j.id AS active_job_id,j.kind AS active_kind,j.heartbeat_at AS active_heartbeat, '.
            'j.state AS active_state,j.phase AS active_phase, '.
            's.interval_minutes AS schedule_interval,s.next_run_at AS schedule_next, '.
            's.last_error AS schedule_error,s.last_attempt_at AS schedule_attempt '.
            'FROM scan_profiles p '.
            'INNER JOIN profile_types t ON t.id=p.profile_type_id '.
            'LEFT JOIN scan_runs r ON r.id=(SELECT MAX(id) FROM scan_runs '.
            "WHERE profile_id=p.id AND state='completed') ".
            'LEFT JOIN jobs j ON j.active_profile_id=p.id '.
            'LEFT JOIN scan_schedules s ON s.profile_id=p.id ORDER BY p.id'
        );
    }

    public function create(string $name,string $source,string $move,int $type,int $minimum,string $hashMode,string $fileExists='fail'): void
    {
        \App\Module\FileActions\Service\ActionSettings::validate($fileExists);
        $this->db->insert('scan_profiles',[
            'name'=>$name,'source_root'=>$source,'move_root'=>$move,
            'profile_type_id'=>$type,'minimum_bytes'=>$minimum,'hash_mode'=>$hashMode,
            'file_exists_policy'=>$fileExists,
            'enabled'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),
            'updated_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }

    public function update(int $id,string $name,string $source,string $move,int $type,int $minimum,string $hashMode,string $fileExists='fail'): void
    {
        \App\Module\FileActions\Service\ActionSettings::validate($fileExists);
        $this->db->transactional(function() use ($id,$name,$source,$move,$type,$minimum,$hashMode,$fileExists):void {
            $this->requireIdle($id);
            $updated=$this->db->update('scan_profiles',[
                'name'=>$name,'source_root'=>$source,'move_root'=>$move,
                'profile_type_id'=>$type,'minimum_bytes'=>$minimum,'hash_mode'=>$hashMode,
                'file_exists_policy'=>$fileExists,
                'updated_at'=>gmdate('Y-m-d H:i:s')
            ],['id'=>$id]);
            // MySQL can return zero for an update that does not change values.
            if($updated>1) throw new \LogicException('Unexpected profile update count.');
        });
    }

    public function setEnabled(int $id,bool $enabled): void
    {
        $this->db->transactional(function() use ($id,$enabled):void {
            $this->requireIdle($id);
            $updated=$this->db->update('scan_profiles',[
                'enabled'=>$enabled?1:0,'updated_at'=>gmdate('Y-m-d H:i:s')
            ],['id'=>$id]);
            // MySQL can return zero for an update that does not change values.
            if($updated>1) throw new \LogicException('Unexpected profile update count.');
        });
    }

    private function requireIdle(int $id): void
    {
        if(!$this->find($id)) throw new \DomainException('Profile no longer exists.');
        if((int)$this->db->fetchOne('SELECT COUNT(*) FROM jobs WHERE active_profile_id=?',[$id])>0)
            throw new \DomainException('Wait for the active profile job to finish.');
    }
}
