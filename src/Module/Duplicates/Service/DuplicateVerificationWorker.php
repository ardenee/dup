<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\ScanCancelled;
use App\Module\FileActions\Service\SafeFileSystem;
use App\Module\Scans\Service\FileHasher;
use Doctrine\DBAL\Connection;

/** Stages hashes before publishing so failures leave the prior scan intact. */
final class DuplicateVerificationWorker
{
    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly SafeFileSystem $fs,
        private readonly DuplicateRebuilder $rebuilder
    ) {}

    public function run(array $job):void
    {
        if($job['kind']!=='duplicate-verify') throw new \LogicException('Invalid job kind.');
        $id=(int)$job['id'];$runId=(int)$job['run_id'];
        $profileId=(int)$job['profile_id'];$token=(string)$job['worker_token'];
        try {
            $run=$this->db->fetchAssociative(
                "SELECT id,profile_id,state FROM scan_runs WHERE id=?",[$runId]
            );
            if(!$run || $run['state']!=='completed' ||
               (int)$run['profile_id']!==$profileId ||
               (int)$this->db->fetchOne(
                   "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=? AND state='completed'",
                   [$profileId]
               )!==$runId)
                throw new \DomainException('The selected scan is no longer current.');
            $total=(int)$this->db->fetchOne(
                'SELECT COUNT(DISTINCT m.file_entry_id) FROM duplicate_group_members m '.
                'JOIN duplicate_groups g ON g.id=m.group_id '.
                'WHERE g.run_id=? AND g.rules_version<>1',[$runId]
            );
            if($total<2)throw new \DomainException('There are no candidate duplicate files to verify.');
            $this->jobs->progress($id,$token,'hashing_duplicates',0,$total);
            $cursor=$this->db->executeQuery(
                'SELECT DISTINCT f.id,f.filename,f.size_bytes,f.mtime_epoch,d.path AS directory '.
                'FROM duplicate_group_members m JOIN duplicate_groups g ON g.id=m.group_id '.
                'JOIN file_entries f ON f.id=m.file_entry_id '.
                'JOIN directories d ON d.id=f.directory_id '.
                'WHERE g.run_id=? AND g.rules_version<>1 AND f.error_code IS NULL '.
                'AND d.is_ignored=0 ORDER BY f.id',[$runId]
            );
            $done=0;
            while(($row=$cursor->fetchAssociative())!==false) {
                $this->jobs->assertRunning($id,$token);
                $path=$this->fs->checkedPath((string)$row['directory'],(string)$row['filename']);
                $size=(int)$row['size_bytes'];$mtime=(int)$row['mtime_epoch'];
                $digest=FileHasher::digest($path,'sha256',$size,$mtime,
                    fn()=>$this->jobs->assertRunning($id,$token)
                );
                $this->db->insert('duplicate_verification_hashes',[
                    'job_id'=>$id,'file_entry_id'=>(int)$row['id'],'sha256'=>$digest
                ]);
                $done++;
                $this->jobs->progress($id,$token,'hashing_duplicates',$done,$total);
            }
            if($done!==$total)
                throw new \DomainException('Candidate list changed during verification.');
            // Apply all results and rebuild groups as a single database update.
            $this->db->transactional(function(Connection $db) use ($id,$runId,$token,$total):void {
                $this->jobs->assertRunning($id,$token);
                if((int)$db->fetchOne(
                    "SELECT COALESCE(MAX(id),0) FROM scan_runs WHERE profile_id=".
                    "(SELECT profile_id FROM scan_runs WHERE id=?) AND state='completed'",[$runId]
                )!==$runId)
                    throw new \DomainException('A newer scan was completed during verification.');
                $this->jobs->progress($id,$token,'publishing_verified_results',$total,$total);
                $rows=$db->executeQuery(
                    'SELECT file_entry_id,sha256 FROM duplicate_verification_hashes '.
                    'WHERE job_id=? ORDER BY file_entry_id',[$id]
                );
                $count=0;
                while(($row=$rows->fetchAssociative())!==false) {
                    $this->jobs->assertRunning($id,$token);
                    $db->executeStatement(
                        "UPDATE file_entries SET hash_method='sha256',hash_value=? WHERE id=? AND run_id=?",
                        [$row['sha256'],(int)$row['file_entry_id'],$runId]
                    );
                    $count++;
                }
                if($count!==$total) throw new \RuntimeException('Published hash count mismatch.');
                $groups=$this->rebuilder->rebuild($runId,'sha256',
                    fn(int $progress)=>$this->jobs->assertRunning($id,$token)
                );
                $now=gmdate('Y-m-d H:i:s');
                $db->executeStatement(
                    'UPDATE scan_runs SET verification_method=?,verified_at=?,duplicate_groups=? WHERE id=?',
                    ['sha256',$now,$groups,$runId]
                );
                $db->executeStatement(
                    "UPDATE jobs SET state='completed',phase='completed',progress_current=?,".
                    "progress_total=?,finished_at=?,heartbeat_at=?,active_profile_id=NULL ".
                    "WHERE id=? AND worker_token=? AND state='running'",
                    [$total,$total,$now,$now,$id,$token]
                );
                $this->jobs->event($id,'info','DUPLICATES_VERIFIED',[
                    'candidate_files'=>$count,'verified_groups'=>$groups,
                    'method'=>'sha256','full_rescan'=>false
                ]);
                $db->delete('duplicate_verification_hashes',['job_id'=>$id]);
            });
        } catch(ScanCancelled $ex) {
            $this->jobs->stop($id,$token,'cancelled','VERIFICATION_CANCELLED');
        } catch(LostLease $ex) {
            throw $ex;
        } catch(\Throwable $ex) {
            $this->jobs->stop($id,$token,'failed','VERIFICATION_FAILED',[
                'reason'=>mb_substr($ex->getMessage(),0,200)
            ]);
            throw $ex;
        } finally {
            // Staged evidence is no longer needed on any outcome. Do not touch
            // the published scan entries outside the commit transaction.
            $this->db->delete('duplicate_verification_hashes',['job_id'=>$id]);
        }
    }
}
