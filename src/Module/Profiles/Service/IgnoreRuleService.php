<?php
declare(strict_types=1);
namespace App\Module\Profiles\Service;

use App\Module\Jobs\Service\JobStore;
use Doctrine\DBAL\Connection;

final class IgnoreRuleService
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs
    ) {}

    /** @return list<string> */
    public function rules(): array
    {
        return array_values($this->db->fetchFirstColumn(
            'SELECT folder_name FROM profile_ignore_rules ' .
            'WHERE profile_id IS NULL AND enabled=1 ORDER BY folder_name'
        ));
    }

    /** @return list<string> */
    public function validate(string $text): array
    {
        if(strlen($text)>24000)
            throw new \DomainException('Too many ignored-folder names.');
        $names=[];
        foreach(preg_split('/[\r\n,]+/u',$text) ?: [] as $value) {
            $name=trim($value);
            if($name==='') continue;
            if(mb_strlen($name)>255 || $name==='.' || $name==='..' ||
                strpbrk($name,'/\\')!==false || str_contains($name,"\0")) {
                throw new \DomainException('Ignore rules must contain folder names, not paths.');
            }
            $names[mb_strtolower($name)]=$name;
            if(count($names)>100) throw new \DomainException('Limit ignore rules to 100 folder names.');
        }
        return array_values($names);
    }

    /** Recalculation uses only stored metadata; no server files are read. */
    public function save(string $text,?int $actorId=null): int
    {
        $names=$this->validate($text);
        return $this->db->transactional(function(Connection $db) use ($names,$actorId):int {
            $active=(int)$db->fetchOne('SELECT COUNT(*) FROM jobs WHERE active_profile_id IS NOT NULL');
            if($active>0) {
                throw new \DomainException(
                    'Wait for active scans or recalculations to finish before changing ignored folders.'
                );
            }
            $db->executeStatement('DELETE FROM profile_ignore_rules WHERE profile_id IS NULL');
            foreach($names as $name)
                $db->insert('profile_ignore_rules',[
                    'profile_id'=>null,'folder_name'=>$name,'enabled'=>1
                ]);

            // Only the latest COMPLETE scan gets its derived matches refreshed.
            // Historical runs, file metadata and hashes remain untouched.
            $runs=$db->fetchAllAssociative(
                "SELECT r.id,r.profile_id FROM scan_runs r WHERE r.state='completed' " .
                "AND r.id=(SELECT MAX(id) FROM scan_runs WHERE profile_id=r.profile_id AND state='completed')"
            );
            $queued=0;
            foreach($runs as $run) {
                $db->insert('jobs',[
                    'kind'=>'rebuild-duplicates','profile_id'=>(int)$run['profile_id'],
                    'active_profile_id'=>(int)$run['profile_id'],'run_id'=>(int)$run['id'],
                    'state'=>'queued','phase'=>'waiting','progress_current'=>'0',
                    'created_at'=>gmdate('Y-m-d H:i:s'),'attempts'=>0
                ]);
                $id=(int)$db->lastInsertId();
                $this->jobs->event($id,'info','IGNORE_RECALC_QUEUED',[
                    'run_id'=>(int)$run['id'],'ignore_rule_count'=>count($names)
                ]);
                $queued++;
            }
            $db->insert('audit_events',[
                'actor_id'=>$actorId,'action'=>'IGNORE_RULES_CHANGED',
                'entity_type'=>'global_ignore_rules','entity_id'=>null,
                'correlation_id'=>bin2hex(random_bytes(16)),'outcome'=>'queued',
                'context'=>json_encode([
                    'folder_names'=>$names,'recalculation_jobs'=>$queued
                ],JSON_THROW_ON_ERROR),
                'created_at'=>gmdate('Y-m-d H:i:s')
            ]);
            return $queued;
        });
    }

    /** Merge global ignore rules with profile-specific ones for a given run. */
    public function effective(int $profileId): array
    {
        $rows=$this->db->fetchFirstColumn(
            'SELECT folder_name FROM profile_ignore_rules WHERE enabled=1 AND ' .
            '(profile_id IS NULL OR profile_id=?)',[$profileId]
        );
        $names=[];
        foreach($rows as $name) $names[mb_strtolower((string)$name)]=(string)$name;
        $names['.dup-quarantine']='.dup-quarantine'; // Reserved internal safety exclusion.
        return array_values($names);
    }
}
