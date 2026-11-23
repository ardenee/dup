<?php
declare(strict_types=1);
namespace App\Module\Profiles\Repository;

use Doctrine\DBAL\Connection;
final class ProfileRepository
{
    public function __construct(private readonly Connection $db) {}
    public function types(): array
    {
        return $this->db->fetchAllAssociative('SELECT id,name,extensions FROM profile_types ORDER BY name');
    }
    public function profiles(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT p.id,p.name,p.source_root,p.move_root,p.hash_mode,p.minimum_bytes,p.enabled, ' .
            't.name AS type_name,r.files_indexed,r.duplicate_groups,r.finished_at, ' .
            'j.state AS active_state,j.phase AS active_phase FROM scan_profiles p ' .
            'INNER JOIN profile_types t ON t.id=p.profile_type_id ' .
            'LEFT JOIN scan_runs r ON r.id=(SELECT MAX(id) FROM scan_runs ' .
            "WHERE profile_id=p.id AND state='completed') " .
            'LEFT JOIN jobs j ON j.active_profile_id=p.id ORDER BY p.id'
        );
    }
    public function create(string $name,string $source,string $move,int $type,int $minimum,string $hashMode): void
    {
        $this->db->insert('scan_profiles',[
            'name'=>$name,'source_root'=>$source,'move_root'=>$move,
            'profile_type_id'=>$type,'minimum_bytes'=>$minimum,'hash_mode'=>$hashMode,
            'enabled'=>1,'created_at'=>gmdate('Y-m-d H:i:s'),
            'updated_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }
}
