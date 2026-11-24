<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Retire historic action details only when no operation may need recovery.
 * Keep permanent audit_events and job history; never touch the filesystem.
 */
final class FileActionHistoryCleanup
{
    public const DAYS=[30,90,180,365];
    private const RECOVERABLE=['quarantine','link','move','bulk_link','bulk_quarantine'];

    public function __construct(private readonly Connection $db) {}

    public function inspect(int $id):array|false
    {
        $batch=$this->db->fetchAssociative(
            'SELECT id,action_kind,state,completed_at,profile_id FROM file_action_batches '.
            'WHERE id=?',[$id]
        );
        if(!$batch) return false;
        $batch['items']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items WHERE batch_id=?',[$id]
        );
        $batch['unresolved']=(int)$this->db->fetchOne(
            'SELECT COUNT(*) FROM file_action_items WHERE batch_id=? AND state<>?',
            [$id,$batch['action_kind']==='rename'?'renamed':'restored']
        );
        $batch['active_jobs']=(int)$this->db->fetchOne(
            "SELECT COUNT(*) FROM jobs WHERE file_action_batch_id=? ".
            "AND state NOT IN ('completed','failed','cancelled')",[$id]
        );
        $kind=(string)$batch['action_kind'];
        $terminal=($kind==='rename' && $batch['state']==='completed') ||
            (in_array($kind,self::RECOVERABLE,true) && $batch['state']==='restored');
        $batch['can_remove']=$terminal && $batch['items']>0 &&
            $batch['unresolved']===0 && $batch['active_jobs']===0;
        $batch['blocked_reason']=$batch['can_remove']?'':(
            'An action remains active, failed, only partially restored, or may still have '.
            'recovery files. It must be resolved before its history can be removed.'
        );
        return $batch;
    }

    public function remove(int $id,int $actorId,string $confirm):void
    {
        if($confirm!=='DELETE') throw new \DomainException('Type DELETE to confirm.');
        $this->db->transactional(function(Connection $db) use ($id,$actorId):void {
            if($db->getDatabasePlatform() instanceof AbstractMySQLPlatform)
                $db->fetchOne('SELECT id FROM file_action_batches WHERE id=? FOR UPDATE',[$id]);
            $batch=$this->inspect($id);
            if(!$batch || !$batch['can_remove'])
                throw new \DomainException($batch['blocked_reason']??'Action batch not found.');
            // Existing action job events and global audit remain, but no longer link
            // to the removed per-file history record.
            $db->executeStatement(
                "UPDATE jobs SET file_action_batch_id=NULL,history_prunable_at=? ".
                "WHERE file_action_batch_id=? AND state IN ('completed','failed','cancelled')",
                [gmdate('Y-m-d H:i:s'),$id]
            );
            $this->record($actorId,'FILE_ACTION_HISTORY_REMOVED',[
                'batch_id'=>$id,'action_kind'=>$batch['action_kind'],
                'items_removed'=>$batch['items']
            ]);
            $changed=$db->executeStatement('DELETE FROM file_action_batches WHERE id=?',[$id]);
            if($changed!==1) throw new \DomainException('Action history changed during cleanup.');
        });
    }

    private function eligible(int $days,?int $profileId):array
    {
        if(!in_array($days,self::DAYS,true))
            throw new \DomainException('Choose a supported retention period.');
        $sql=" FROM file_action_batches b WHERE b.completed_at<? ".
            "AND ((b.action_kind='rename' AND b.state='completed') OR ".
            "(b.action_kind IN ('quarantine','link','move','bulk_link','bulk_quarantine') ".
            "AND b.state='restored')) ".
            'AND EXISTS (SELECT 1 FROM file_action_items i WHERE i.batch_id=b.id) '.
            'AND NOT EXISTS (SELECT 1 FROM file_action_items i WHERE i.batch_id=b.id '.
            "AND i.state<>CASE WHEN b.action_kind='rename' THEN 'renamed' ELSE 'restored' END) ".
            'AND NOT EXISTS (SELECT 1 FROM jobs j WHERE j.file_action_batch_id=b.id '.
            "AND j.state NOT IN ('completed','failed','cancelled')) ";
        $params=[gmdate('Y-m-d H:i:s',time()-$days*86400)];
        if($profileId!==null) {
            $sql.='AND b.profile_id=? ';
            $params[]=$profileId;
        }
        return [$sql,$params];
    }

    public function estimate(int $days,?int $profileId=null):int
    {
        [$sql,$args]=$this->eligible($days,$profileId);
        return (int)$this->db->fetchOne('SELECT COUNT(*)'.$sql,$args);
    }

    /** Explicit 50-row chunks; manual recovery or unresolved rows never deleted. */
    public function prune(int $days,?int $profileId,int $actorId,string $confirmation):int
    {
        if($confirmation!=='PRUNE ACTIONS')
            throw new \DomainException('Type PRUNE ACTIONS to confirm.');
        [$sql,$args]=$this->eligible($days,$profileId);
        $ids=$this->db->fetchFirstColumn(
            'SELECT b.id'.$sql.'ORDER BY b.id LIMIT 50',$args
        );
        $count=0;
        foreach($ids as $id) {
            try {
                $this->remove((int)$id,$actorId,'DELETE');
                $count++;
            } catch(\DomainException) {
                // A changed record remains protected.
            }
        }
        return $count;
    }

    private function record(int $actor,string $event,array $context):void
    {
        $this->db->insert('audit_events',[
            'actor_id'=>$actor,'action'=>$event,'entity_type'=>'file_action_history',
            'entity_id'=>(string)$context['batch_id'],
            'correlation_id'=>bin2hex(random_bytes(16)),
            'outcome'=>'completed','created_at'=>gmdate('Y-m-d H:i:s'),
            'context'=>json_encode($context,JSON_THROW_ON_ERROR)
        ]);
    }
}
