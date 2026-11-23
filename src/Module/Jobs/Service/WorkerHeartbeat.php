<?php
declare(strict_types=1);

namespace App\Module\Jobs\Service;

use Doctrine\DBAL\Connection;

/**
 * Reports CLI worker availability without relying on systemd, DSM APIs,
 * Windows services, cron or a persistent process supervisor.
 */
final class WorkerHeartbeat
{
    private readonly string $id;
    private bool $registered=false;

    public function __construct(private readonly Connection $db)
    {
        $this->id=bin2hex(random_bytes(16));
    }

    public function touch(): void
    {
        $now=gmdate('Y-m-d H:i:s');
        if($this->registered) {
            $this->db->executeStatement(
                'UPDATE worker_heartbeats SET updated_at=? WHERE id=?',[$now,$this->id]
            );
            return;
        }
        $this->db->insert('worker_heartbeats',[
            'id'=>$this->id,
            'host_name'=>mb_substr((string)(gethostname()?:'host'),0,191),
            'process_id'=>(int)getmypid(),
            'os_family'=>PHP_OS_FAMILY,
            'updated_at'=>$now
        ]);
        $this->registered=true;
    }

    public function stop(): void
    {
        if(!$this->registered) return;
        $this->db->delete('worker_heartbeats',['id'=>$this->id]);
        $this->registered=false;
    }

    /** @return array{active:int,last_heartbeat:string|null} */
    public function report(): array
    {
        $cutoff=gmdate('Y-m-d H:i:s',time()-30);
        $active=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM worker_heartbeats WHERE updated_at>=?',[$cutoff]
        );
        $last=$this->db->fetchOne('SELECT MAX(updated_at) FROM worker_heartbeats');
        return ['active'=>$active,'last_heartbeat'=>$last===false||$last===null?null:(string)$last];
    }
}
