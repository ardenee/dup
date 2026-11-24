<?php
declare(strict_types=1);
namespace App\Module\Search\Repository;

use Doctrine\DBAL\Connection;
use App\Core\Table\KeysetPage;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;

/**
 * Cross-scan, read-only history of individual file actions. Unlike the
 * current-state "Moved" search, records remain visible after a later scan
 * or an action's reversal, including unsuccessful and cancelled attempts.
 */
final class ActionHistoryRepository
{
    private const PAGE=50;
    public const SORTS=[
        'id'=>'i.id','profile'=>'p.name','type'=>'b.action_kind',
        'state'=>'i.state','requested'=>"COALESCE(b.requested_at,'')",
        'path'=>'i.source_path'
    ];

    public function __construct(private readonly Connection $db) {}

    public function find(
        string $term,?int $profile,bool $caseSensitive,?int $after=null,?int $before=null,
        string $sort='id',string $direction='desc',string $actionKind=''
    ):array {
        if($after!==null && $before!==null)
            throw new \InvalidArgumentException('Choose only one history cursor.');
        if(!isset(self::SORTS[$sort]) || !in_array($direction,['asc','desc'],true))
            throw new \InvalidArgumentException('Invalid action search sorting.');
        $from=' FROM file_action_items i JOIN file_action_batches b ON b.id=i.batch_id '.
            'JOIN scan_profiles p ON p.id=b.profile_id '.
            'JOIN file_entries f ON f.id=i.file_entry_id '.
            'LEFT JOIN scan_runs r ON r.id=f.run_id '.
            "WHERE b.action_kind IN ('move','link','quarantine','bulk_link','bulk_quarantine','delete')";
        $params=[];
        if($profile!==null) {
            $from.=' AND b.profile_id=:profile';
            $params['profile']=$profile;
        }
        if($term!=='') {
            if($caseSensitive) {
                if($this->db->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
                    $from.=' AND (INSTR(BINARY i.source_path,BINARY :term)>0 '.
                        'OR INSTR(BINARY i.target_path,BINARY :term)>0 '.
                        'OR INSTR(BINARY i.link_target_path,BINARY :term)>0)';
                } else {
                    $from.=' AND (instr(i.source_path,:term)>0 '.
                        'OR instr(i.target_path,:term)>0 '.
                        'OR instr(i.link_target_path,:term)>0)';
                }
                $params['term']=$term;
            } else {
                $pattern=str_replace(['!','%','_'],['!!','!%','!_'],mb_strtolower($term));
                $from.=" AND (LOWER(i.source_path) LIKE :term ESCAPE '!'".
                    " OR LOWER(i.target_path) LIKE :term ESCAPE '!'".
                    " OR LOWER(i.link_target_path) LIKE :term ESCAPE '!')";
                $params['term']='%'.$pattern.'%';
            }
        }
        if($actionKind!=='') {
            if(!in_array($actionKind,['move','link','quarantine',
                'bulk_link','bulk_quarantine','delete'],true))
                throw new \InvalidArgumentException('Invalid action history filter.');
            $from.=' AND b.action_kind=:kind';
            $params['kind']=$actionKind;
        }
        $result=KeysetPage::fetch(
            $this->db,
            'i.id AS id,i.batch_id,i.file_entry_id,i.state AS item_state,'.
            'i.source_path,i.target_path,i.link_target_path,i.expected_size,'.
            'i.result_code,b.action_kind,b.state AS batch_state,'.
            'b.requested_at,b.completed_at,b.error_code AS batch_error,'.
            'r.id AS run_id,r.finished_at AS scan_finished_at,'.
            'p.name AS profile_name,p.id AS profile_id',
            $from,$params,'i.id',self::SORTS[$sort],strtoupper($direction),
            self::PAGE,$after,$before,$sort==='id'
        );
        return [
            'rows'=>$result['rows'],'total'=>$result['total'],
            'next'=>$result['next'],'previous'=>$result['previous']
        ];
    }
}
