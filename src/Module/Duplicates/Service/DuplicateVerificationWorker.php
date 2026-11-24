<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\JobDebugLog;
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
        private readonly DuplicateRebuilder $rebuilder,
        private readonly ?JobDebugLog $debug=null
    ) {}

    public function run(array $job):void
    {
        if($job['kind']!=='duplicate-verify') throw new \LogicException('Invalid job kind.');
        $id=(int)$job['id'];$runId=(int)$job['run_id'];
        $profileId=(int)$job['profile_id'];$token=(string)$job['worker_token'];
        $step='preflight';$currentPath=null;$fileIndex=0;$done=0;
        $total=0;$bytesTotal=0;$bytesCompleted=0;$fileBytesRead=0;
        $started=microtime(true);
        $debug=$this->debug??new JobDebugLog();
        $debug->write($id,'info','verification.worker.start',[
            'profile_id'=>$profileId,'run_id'=>$runId,
            'php_version'=>PHP_VERSION,'php_sapi'=>PHP_SAPI,
            'memory_limit'=>ini_get('memory_limit'),'file'=>'DuplicateVerificationWorker',
            'note'=>'File-backed logs work even while the SQL connection is blocked.'
        ]);
        try {
            $debug->write($id,'debug','preflight.db.start',['query'=>'scan_runs.current']);
            $this->jobs->event($id,'info','VERIFICATION_PREFLIGHT',[
                'description'=>'Checking current scan and enumerating candidate files.',
                'profile_id'=>$profileId,'run_id'=>$runId
            ]);
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
            $debug->write($id,'debug','preflight.db.end',[
                'elapsed_ms'=>round((microtime(true)-$started)*1000,2),
                'run_state'=>$run['state']??'absent'
            ]);
            $debug->write($id,'debug','candidate.count.db.start',[
                'query'=>'distinct candidate files/count and total bytes',
                'run_id'=>$runId
            ]);
            $countStart=microtime(true);
            $stats=$this->db->fetchAssociative(
                'SELECT COUNT(*) AS file_count,COALESCE(SUM(c.size_bytes),0) AS total_bytes '.
                'FROM (SELECT DISTINCT f.id,f.size_bytes '.
                'FROM duplicate_group_members m '.
                'JOIN duplicate_groups g ON g.id=m.group_id '.
                'JOIN file_entries f ON f.id=m.file_entry_id '.
                'JOIN directories d ON d.id=f.directory_id '.
                'WHERE g.run_id=? AND g.rules_version<>1 AND f.error_code IS NULL '.
                'AND d.is_ignored=0) c',[$runId]
            );
            $debug->write($id,'debug','candidate.count.db.end',[
                'elapsed_ms'=>round((microtime(true)-$countStart)*1000,2),
                'row_count'=>$stats['file_count']??null,
                'total_bytes'=>$stats['total_bytes']??null
            ]);
            $total=(int)($stats['file_count']??0);
            $bytesTotal=(int)($stats['total_bytes']??0);
            if($total<2)
                throw new \DomainException('There are no accessible candidate duplicate files to verify.');
            $debug->write($id,'debug','progress.initial.db.start',[
                'files_total'=>$total,'bytes_total'=>$bytesTotal
            ]);
            $startProgress=microtime(true);
            $this->jobs->verificationProgress(
                $id,$token,0,$total,0,$bytesTotal,'Preparing SHA-256 verification'
            );
            $debug->write($id,'debug','progress.initial.db.end',[
                'elapsed_ms'=>round((microtime(true)-$startProgress)*1000,2)
            ]);
            $this->jobs->event($id,'info','VERIFICATION_STARTED',[
                'description'=>'Hashing only the previously detected candidate duplicate files.',
                'profile_id'=>$profileId,'run_id'=>$runId,
                'candidate_files'=>$total,'candidate_bytes'=>$bytesTotal,
                'algorithm'=>'sha256'
            ]);
            $debug->write($id,'info','verification.candidates.ready',[
                'files'=>$total,'bytes'=>$bytesTotal,
                'elapsed_ms'=>round((microtime(true)-$started)*1000,2)
            ]);
            // Bound memory use and finish each DB result before writing hash
            // progress/events to the same connection. Keyset paging also
            // avoids holding a live MySQL result cursor during I/O.
            $lastFileId=0;
            do {
                $debug->write($id,'debug','candidate.page.begin',[
                    'after_file_id'=>$lastFileId,'files_complete'=>$done
                ]);
                $this->jobs->assertRunning($id,$token);
                $step='finding_candidate_files';
                $debug->write($id,'debug','candidate.progress.db.start',[
                    'after_file_id'=>$lastFileId
                ]);
                $this->jobs->verificationProgress(
                    $id,$token,$done,$total,$bytesCompleted,$bytesTotal,
                    'Finding files to hash (after file #'.$lastFileId.')',
                    'locating_verification_candidates'
                );
                $debug->write($id,'debug','candidate.progress.db.end');
                $this->jobs->event($id,'info','VERIFICATION_CANDIDATE_LOOKUP',[
                    'files_completed'=>$done,'candidate_files'=>$total,
                    'last_indexed_file_id'=>$lastFileId
                ]);
                $lookupStarted=microtime(true);
                $debug->write($id,'debug','candidate.page.sql.start',[
                    'query'=>'distinct candidate files, keyset 64',
                    'run_id'=>$runId,'after_file_id'=>$lastFileId
                ]);
                $page=$this->db->fetchAllAssociative(
                    'SELECT DISTINCT f.id,f.filename,f.size_bytes,f.mtime_epoch,d.path AS directory '.
                    'FROM duplicate_group_members m JOIN duplicate_groups g ON g.id=m.group_id '.
                    'JOIN file_entries f ON f.id=m.file_entry_id '.
                    'JOIN directories d ON d.id=f.directory_id '.
                    'WHERE g.run_id=? AND g.rules_version<>1 AND f.error_code IS NULL '.
                    'AND d.is_ignored=0 AND f.id>? ORDER BY f.id LIMIT 64',
                    [$runId,$lastFileId]
                );
                $debug->write($id,'debug','candidate.page.sql.end',[
                    'elapsed_ms'=>round((microtime(true)-$lookupStarted)*1000,2),
                    'row_count'=>count($page),'last_file_id'=>$lastFileId
                ]);
                $this->jobs->event($id,'info','VERIFICATION_CANDIDATE_PAGE_READY',[
                    'count'=>count($page),
                    'elapsed_seconds'=>round(microtime(true)-$lookupStarted,3),
                    'files_completed'=>$done
                ]);
                $step='reading';
                foreach($page as $row) {
                    $lastFileId=(int)$row['id'];

                $this->jobs->assertRunning($id,$token);
                $fileIndex=$done+1;
                $currentPath=rtrim((string)$row['directory'],'/\\').DIRECTORY_SEPARATOR.(string)$row['filename'];
                $step='checking_file_access';
                $debug->write($id,'debug','file.path.check.start',[
                    'file_index'=>$fileIndex,'path'=>$currentPath
                ]);
                $this->jobs->event($id,'info','VERIFICATION_FILE_OPENING',[
                    'file_index'=>$fileIndex,'total_files'=>$total,
                    'path'=>$currentPath,
                    'description'=>'Checking source file permissions and verifying the saved path.'
                ]);
                $currentPath=$this->fs->checkedPath((string)$row['directory'],(string)$row['filename']);
                $debug->write($id,'debug','file.path.check.end',[
                    'file_index'=>$fileIndex,'path'=>$currentPath
                ]);
                $step='reading_file';
                $size=(int)$row['size_bytes'];
                $mtime=(int)$row['mtime_epoch'];
                $fileBytesRead=0;
                $fileStarted=microtime(true);
                $lastWriteAt=$fileStarted;
                $lastWrittenBytes=0;
                $lastEventAt=$fileStarted;
                $lastLogAt=$fileStarted;
                $lastLogBytes=0;
                $detail='File '.$fileIndex.'/'.$total.': '.(string)$row['filename'];
                $this->jobs->verificationProgress(
                    $id,$token,$done,$total,$bytesCompleted,$bytesTotal,$detail
                );
                $this->jobs->event($id,'info','VERIFICATION_FILE_STARTED',[
                    'file_index'=>$fileIndex,'total_files'=>$total,
                    'path'=>$currentPath,'file_bytes'=>$size,
                    'bytes_completed_before_file'=>$bytesCompleted
                ]);
                $debug->write($id,'info','file.hash.start',[
                    'file_index'=>$fileIndex,'path'=>$currentPath,
                    'size_bytes'=>$size,'mtime_epoch'=>$mtime
                ]);
                $digest=FileHasher::digest(
                    $currentPath,'sha256',$size,$mtime,
                    fn()=>$this->jobs->assertRunning($id,$token),
                    function(int $bytesRead) use (
                        $id,$token,$done,$total,$bytesCompleted,$bytesTotal,
                        $size,$fileIndex,$detail,$fileStarted,
                        &$fileBytesRead,&$lastWriteAt,&$lastWrittenBytes,&$lastEventAt,
                        &$lastLogAt,&$lastLogBytes,$debug,$currentPath
                    ):void {
                        $fileBytesRead=$bytesRead;
                        $now=microtime(true);
                        if($now-$lastLogAt>=5 || $bytesRead-$lastLogBytes>=8*1024*1024
                           || $bytesRead===$size) {
                            $debug->write($id,'debug','file.hash.bytes',[
                                'file_index'=>$fileIndex,'file_path'=>$currentPath,
                                'bytes_read'=>$bytesRead,'file_size'=>$size,
                                'overall_bytes_read'=>$bytesCompleted+$bytesRead,
                                'elapsed_seconds'=>round($now-$fileStarted,3)
                            ]);
                            $lastLogAt=$now;
                            $lastLogBytes=$bytesRead;
                        }
                        if($now-$lastWriteAt>=2.0 || $bytesRead-$lastWrittenBytes>=16*1024*1024
                           || $bytesRead===$size) {
                            $this->jobs->verificationProgress(
                                $id,$token,$done,$total,$bytesCompleted+$bytesRead,
                                $bytesTotal,$detail
                            );
                            $lastWriteAt=$now;
                            $lastWrittenBytes=$bytesRead;
                        }
                        if($now-$lastEventAt>=30) {
                            $seconds=max(0.001,$now-$fileStarted);
                            $this->jobs->event($id,'info','VERIFICATION_FILE_PROGRESS',[
                                'file_index'=>$fileIndex,'total_files'=>$total,
                                'file_bytes_read'=>$bytesRead,'file_bytes'=>$size,
                                'overall_bytes_read'=>$bytesCompleted+$bytesRead,
                                'overall_bytes'=>$bytesTotal,
                                'elapsed_seconds'=>round($seconds,1),
                                'read_mib_per_second'=>round($bytesRead/$seconds/1048576,2)
                            ]);
                            $lastEventAt=$now;
                        }
                    }
                );
                $debug->write($id,'info','file.hash.complete',[
                    'file_index'=>$fileIndex,'path'=>$currentPath,
                    'elapsed_ms'=>round((microtime(true)-$fileStarted)*1000,2),
                    'bytes_read'=>$fileBytesRead,'size_bytes'=>$size
                ]);
                $debug->write($id,'debug','hash.stage.db.start',[
                    'file_index'=>$fileIndex
                ]);
                $this->db->insert('duplicate_verification_hashes',[
                    'job_id'=>$id,'file_entry_id'=>(int)$row['id'],'sha256'=>$digest
                ]);
                $debug->write($id,'debug','hash.stage.db.end',[
                    'file_index'=>$fileIndex
                ]);
                $done++;
                $bytesCompleted+=$size;
                $seconds=max(0.001,microtime(true)-$fileStarted);
                $this->jobs->verificationProgress(
                    $id,$token,$done,$total,$bytesCompleted,$bytesTotal,
                    'Verified '.$done.'/'.$total.' files'
                );
                $this->jobs->event($id,'info','VERIFICATION_FILE_COMPLETED',[
                    'file_index'=>$fileIndex,'total_files'=>$total,
                    'path'=>$currentPath,'file_bytes'=>$size,
                    'elapsed_seconds'=>round($seconds,2),
                    'read_mib_per_second'=>round($size/$seconds/1048576,2),
                    'overall_bytes_read'=>$bytesCompleted,'overall_bytes'=>$bytesTotal
                ]);
                $currentPath=null;
                }
            } while(count($page)===64);
            if($done!==$total || $bytesCompleted!==$bytesTotal)
                throw new \DomainException('Candidate list changed during verification.');
            $step='publishing';
            $debug->write($id,'info','publish.start',[
                'files_verified'=>$done,'bytes_verified'=>$bytesCompleted,
                'elapsed_seconds'=>round(microtime(true)-$started,3)
            ]);
            $this->jobs->verificationProgress(
                $id,$token,$total,$total,$bytesTotal,$bytesTotal,
                'Publishing verified hashes and rebuilding duplicate groups',
                'publishing_verified_results'
            );
            $this->jobs->event($id,'info','VERIFICATION_PUBLISH_STARTED',[
                'verified_files'=>$total,'verified_bytes'=>$bytesTotal,
                'hashing_elapsed_seconds'=>round(microtime(true)-$started,2),
                'description'=>'Publishing hashes and rebuilding duplicate groups atomically.'
            ]);
            // Apply all results and rebuild groups as a single database update.
            $this->db->transactional(function(Connection $db) use ($id,$runId,$token,$total,$bytesTotal,$started):void {
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
                    "progress_total=?,progress_bytes_current=?,progress_bytes_total=?,".
                    "progress_detail=?,last_progress_at=?,".
                    "finished_at=?,heartbeat_at=?,active_profile_id=NULL ".
                    "WHERE id=? AND worker_token=? AND state='running'",
                    [$total,$total,$bytesTotal,$bytesTotal,
                        'SHA-256 verification completed',$now,$now,$now,$id,$token]
                );
                $this->jobs->event($id,'info','DUPLICATES_VERIFIED',[
                    'candidate_files'=>$count,'candidate_bytes'=>$bytesTotal,
                    'verified_groups'=>$groups,'elapsed_seconds'=>round(microtime(true)-$started,2),
                    'method'=>'sha256','full_rescan'=>false
                ]);
                $db->delete('duplicate_verification_hashes',['job_id'=>$id]);
            });
            $debug->write($id,'info','verification.completed',[
                'files'=>$done,'bytes'=>$bytesCompleted,
                'duration_seconds'=>round(microtime(true)-$started,3)
            ]);
        } catch(ScanCancelled $ex) {
            $debug->write($id,'warning','verification.cancelled',[
                'step'=>$step,'message'=>$ex->getMessage()
            ]);
            $this->jobs->stop($id,$token,'cancelled','VERIFICATION_CANCELLED');
        } catch(LostLease $ex) {
            $debug->write($id,'error','verification.lease_lost',[
                'step'=>$step,'message'=>$ex->getMessage()
            ]);
            throw $ex;
        } catch(\Throwable $ex) {
            $debug->write($id,'error','verification.exception',[
                'step'=>$step,'exception'=>get_class($ex),
                'error'=>$ex->getMessage(),'file'=>$ex->getFile(),
                'line'=>$ex->getLine(),'trace'=>$ex->getTraceAsString(),
                'current_file'=>$currentPath,'file_index'=>$fileIndex,
                'bytes_read'=>$fileBytesRead,'files_completed'=>$done
            ]);
            $this->jobs->stop($id,$token,'failed','VERIFICATION_FAILED',[
                'reason'=>mb_substr($ex->getMessage(),0,2000),
                'exception_type'=>get_class($ex),
                'stage'=>$step,
                'file_index'=>$fileIndex,'total_files'=>$total,
                'file_path'=>$currentPath,
                'file_bytes_read'=>$fileBytesRead,
                'files_completed'=>$done,
                'bytes_completed'=>$bytesCompleted+$fileBytesRead,
                'bytes_total'=>$bytesTotal,
                'elapsed_seconds'=>round(microtime(true)-$started,2),
                'worker_host'=>(string)(gethostname()?:'unknown'),
                'worker_pid'=>getmypid()
            ]);
            throw $ex;
        } finally {
            $debug->write($id,'debug','verification.cleanup.start',[
                'step'=>$step,'files_completed'=>$done
            ]);
            // Staged evidence is no longer needed on any outcome. Do not touch
            // the published scan entries outside the commit transaction.
            $this->db->delete('duplicate_verification_hashes',['job_id'=>$id]);
            $debug->write($id,'debug','verification.cleanup.end');
        }
    }
}
