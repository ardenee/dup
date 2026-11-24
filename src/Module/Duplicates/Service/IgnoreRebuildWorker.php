<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\ScanCancelled;
use App\Module\Profiles\Service\IgnoreRuleService;
use Doctrine\DBAL\Connection;

final class IgnoreRebuildWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly DuplicateRebuilder $rebuilder,
        private readonly IgnoreRuleService $rules
    ) {}

    public function run(array $job): void
    {
        $id=(int)$job['id'];
        $runId=(int)$job['run_id'];
        $profileId=(int)$job['profile_id'];
        $token=(string)$job['worker_token'];
        try {
            $run=$this->db->fetchAssociative(
                'SELECT state,settings_json,profile_id,verification_method FROM scan_runs WHERE id=?',[$runId]
            );
            if(!$run || $run['state']!=='completed' || (int)$run['profile_id']!==$profileId)
                throw new \RuntimeException('Recalculation target is not a successful scan.');
            $data=json_decode((string)$run['settings_json'],true,512,JSON_THROW_ON_ERROR);
            $mode=(string)($run['verification_method']?:($data['hash_mode']??''));
            $names=$this->rules->effective($profileId);
            $this->db->transactional(function(Connection $db) use ($id,$runId,$token,$mode,$names):void {
                $this->jobs->assertRunning($id,$token);
                $this->jobs->progress($id,$token,'updating_ignored_folders',0,null);
                $changed=$this->rebuilder->updateIgnoredDirectories(
                    $runId,$names,fn(int $done) => $this->jobs->assertRunning($id,$token)
                );
                $this->jobs->progress($id,$token,'rebuilding_groups',0,null);
                $groups=$this->rebuilder->rebuild(
                    $runId,$mode,fn(int $done) => $this->jobs->assertRunning($id,$token)
                );
                $this->jobs->completeRebuild($id,$token,$groups,$changed);
            });
        } catch(ScanCancelled $ex) {
            $this->jobs->stop($id,$token,'cancelled','RECALC_CANCELLED');
        } catch(LostLease $ex) {
            throw $ex;
        } catch(\Throwable $ex) {
            $this->jobs->stop($id,$token,'failed','RECALC_ERROR',[
                'type'=>get_class($ex),'message'=>mb_substr($ex->getMessage(),0,250)
            ]);
            throw $ex;
        }
    }
}
