<?php
declare(strict_types=1);
namespace App\Module\Scans\Service;

use App\Module\Jobs\Service\JobStore;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\ScanCancelled;
use App\Module\Duplicates\Service\DuplicateRebuilder;
use App\Module\Profiles\Service\DirectoryBrowser;
use Doctrine\DBAL\Connection;

/**
 * A scan never modifies source files and never deletes the last successful run.
 * Only PHP CLI workers invoke this service, not web requests.
 */
final class ScanEngine
{
    private int $lastHeartbeat=0;
    private array $stats=[];

    public function __construct(
        private readonly Connection $db,
        private readonly JobStore $jobs,
        private readonly DuplicateRebuilder $rebuilder,
        private readonly DirectoryBrowser $browser
    ) {}

    /** @param array<string,mixed> $job */
    public function run(array $job): void
    {
        $id=(int)$job['id'];
        $runId=(int)$job['run_id'];
        $token=(string)$job['worker_token'];
        $this->stats=['files_seen'=>0,'files_indexed'=>0,'bytes_indexed'=>0,
            'duplicate_groups'=>0,'error_count'=>0,'ignored_indexed'=>0,
            'symlinks_skipped'=>0];
        try {
            $settingsRaw=$this->db->fetchOne('SELECT settings_json FROM scan_runs WHERE id=?',[$runId]);
            $settings=json_decode((string)$settingsRaw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($settings)) throw new \RuntimeException('Invalid scan settings snapshot.');
            $root=(string)($settings['source_root']??'');
            if($root==='' || $this->browser->canonicalDirectory($root)!==$root)
                throw new \RuntimeException('The scan root is no longer allowed or accessible.');
            $mode=(string)($settings['hash_mode']??'');
            if(!in_array($mode,['size','sha256'],true))
                throw new \RuntimeException('Invalid hash strategy in scan snapshot.');
            $extensions=$this->extensionSet((string)($settings['extensions']??''));
            $ignore=array_fill_keys((array)($settings['ignored_folders']??[]),true);
            $minimum=(int)($settings['minimum_bytes']??0);

            $this->jobs->event($id,'info','INDEXING_STARTED');
            $this->jobs->progress($id,$token,'enumerating',0,null);
            $this->lastHeartbeat=time();
            $this->enumerate($id,$runId,$token,$root,$extensions,$ignore,$minimum);
            $this->jobs->event($id,'info','INDEXING_FINISHED',[
                'files_indexed'=>$this->stats['files_indexed'],
                'files_seen'=>$this->stats['files_seen'],
                'ignored_indexed'=>$this->stats['ignored_indexed'],
                'symlinks_skipped'=>$this->stats['symlinks_skipped']
            ]);
            $this->jobs->progress($id,$token,'finding_candidates',0,null);
            if($mode==='sha256')
                $this->hashCandidateFiles($id,$runId,$token);
            $this->jobs->progress($id,$token,'grouping',0,null);
            $this->stats['duplicate_groups']=$this->db->transactional(
                fn() => $this->rebuilder->rebuild($runId,$mode,
                    fn(int $done) => $this->heartbeat($id,$token,'grouping',$done,null))
            );
            $this->jobs->progress($id,$token,'grouping',
                $this->stats['duplicate_groups'],$this->stats['duplicate_groups']);
            $this->jobs->event($id,'info','GROUPING_FINISHED',[
                'groups'=>$this->stats['duplicate_groups'],'verified'=>$mode==='sha256'
            ]);
            $this->jobs->complete($id,$token,$this->stats);
        } catch(ScanCancelled $ex) {
            $this->jobs->stop($id,$token,'cancelled','SCAN_CANCELLED');
        } catch(LostLease $ex) {
            // Another worker/recovery operation owns the job; never overwrite it.
            throw $ex;
        } catch(\Throwable $ex) {
            $this->jobs->stop($id,$token,'failed','SCAN_ERROR',[
                'type'=>get_class($ex),'message'=>mb_substr($ex->getMessage(),0,300)
            ]);
            throw $ex;
        }
    }

    /** @return array<string,bool> */
    private function extensionSet(string $extensions): array
    {
        $set=[];
        foreach(explode(',',$extensions) as $ext) {
            $ext=strtolower(ltrim(trim($ext),'.'));
            if($ext!=='') $set[$ext]=true;
        }
        return $set;
    }

    private function enumerate(
        int $jobId,int $runId,string $token,string $root,array $extensions,array $ignored,int $minimum
    ): void {
        // Explicit stack avoids symlink traversal and unbounded recursion.
        $pending=[['path'=>$root,'parent'=>null,'ignored'=>false]];
        $fileBatch=[];
        while($pending) {
            $this->heartbeat($jobId,$token,'enumerating',$this->stats['files_seen'],null);
            $dir=array_pop($pending);
            $path=(string)$dir['path'];
            if(is_link($path))
                throw new \RuntimeException('A directory became a symbolic link during the scan.');
            try {
                $this->db->executeStatement(
                    'INSERT INTO directories (run_id,parent_id,path,path_hash,basename,is_ignored) ' .
                    'VALUES (?,?,?,?,?,?)',
                    [$runId,$dir['parent'],$path,hash('sha256',$path),
                     mb_substr(basename($path),0,255),$dir['ignored']?1:0]
                );
                $dirId=(int)$this->db->lastInsertId();
                $iterator=new \DirectoryIterator($path);
                foreach($iterator as $entry) {
                    if($entry->isDot()) continue;
                    $this->heartbeat($jobId,$token,'enumerating',$this->stats['files_seen'],null);
                    $name=$entry->getFilename();
                    if($entry->isLink()) {
                        $this->stats['symlinks_skipped']++;
                        continue;
                    }
                    if($entry->isDir()) {
                        $child=$entry->getPathname();
                        $pending[]=[
                            'path'=>$child,'parent'=>$dirId,
                            'ignored'=>($dir['ignored'] || isset($ignored[strtolower($name)]))
                        ];
                        continue;
                    }
                    if(!$entry->isFile()) continue;
                    $this->stats['files_seen']++;
                    if($extensions) {
                        $extension=strtolower((string)pathinfo($name,PATHINFO_EXTENSION));
                        if(!isset($extensions[$extension])) continue;
                    }
                    if(mb_strlen($name)>255)
                        throw new \RuntimeException('Filename longer than 255 characters.');
                    $size=$entry->getSize();
                    if($size<$minimum) continue;
                    $mtime=$entry->getMTime();
                    $fullPath=$entry->getPathname();
                    $fileBatch[]=[$runId,$dirId,$name,hash('sha256',$fullPath),$size,$mtime];
                    if(count($fileBatch)>=100) $this->insertFileBatch($fileBatch);
                    $this->stats['files_indexed']++;
                    if($dir['ignored']) $this->stats['ignored_indexed']++;
                    $this->stats['bytes_indexed']+=$size;
                }
            } catch(ScanCancelled|LostLease $ex) {
                throw $ex;
            } catch(\Throwable $ex) {
                throw new \RuntimeException('Could not index directory: '.$path,0,$ex);
            }
        }
        $this->insertFileBatch($fileBatch);
        $this->jobs->progress($jobId,$token,'enumerating',
            $this->stats['files_seen'],$this->stats['files_seen']);
    }

    /** @param array<int,array<int,int|string>> $batch */
    private function insertFileBatch(array &$batch): void
    {
        if(!$batch) return;
        // One SQL statement per 100 rows: avoids per-file autocommits on MariaDB.
        // 600 bind parameters also remain below the older SQLite 999 limit.
        $values=implode(',',array_fill(0,count($batch),'(?,?,?,?,?,?,0)'));
        $args=[];
        foreach($batch as $file) foreach($file as $value) $args[]=$value;
        $this->db->executeStatement(
            'INSERT INTO file_entries (run_id,directory_id,filename,path_hash,' .
            'size_bytes,mtime_epoch,is_symlink) VALUES '.$values,$args
        );
        $batch=[];
    }

    private function hashCandidateFiles(int $jobId,int $runId,string $token): void
    {
        $sizes=$this->db->executeQuery(
            'SELECT size_bytes FROM file_entries WHERE run_id=? ' .
            'GROUP BY size_bytes HAVING COUNT(*)>1 ORDER BY size_bytes',[$runId]
        );
        $sizeList=[];
        while(($row=$sizes->fetchAssociative())!==false)
            $sizeList[]=(string)$row['size_bytes'];
        $total=0;
        foreach($sizeList as $size)
            $total+=(int)$this->db->fetchOne(
                'SELECT COUNT(*) FROM file_entries WHERE run_id=? AND size_bytes=?',[$runId,$size]
            );
        $done=0;
        $this->jobs->progress($jobId,$token,'hashing',0,$total);
        $this->lastHeartbeat=time();
        foreach($sizeList as $size) {
            $candidates=$this->db->executeQuery(
                'SELECT f.id,f.filename,f.size_bytes,f.mtime_epoch,d.path AS directory ' .
                'FROM file_entries f JOIN directories d ON d.id=f.directory_id ' .
                'WHERE f.run_id=? AND f.size_bytes=? ORDER BY f.id',[$runId,$size]
            );
            while(($file=$candidates->fetchAssociative())!==false) {
                $this->heartbeat($jobId,$token,'hashing',$done,$total);
                $path=rtrim((string)$file['directory'],'/\\') . DIRECTORY_SEPARATOR . $file['filename'];
                $digest=$this->hashFile($path,(int)$file['size_bytes'],(int)$file['mtime_epoch'],
                    $jobId,$token,$done,$total);
                $this->db->executeStatement(
                    "UPDATE file_entries SET hash_method='sha256',hash_value=? WHERE id=?",
                    [$digest,(int)$file['id']]
                );
                $done++;
            }
        }
        $this->jobs->progress($jobId,$token,'hashing',$done,$total);
        $this->jobs->event($jobId,'info','HASHING_FINISHED',['hashed_files'=>$done]);
    }

    private function hashFile(string $path,int $size,int $mtime,int $jobId,string $token,int $done,int $total): string
    {
        clearstatcache(true,$path);
        if(is_link($path) || !is_file($path) || filesize($path)!==$size || filemtime($path)!==$mtime)
            throw new \RuntimeException('A candidate file changed or became inaccessible: '.$path);
        $stream=@fopen($path,'rb');
        if($stream===false) throw new \RuntimeException('Unable to read candidate file: '.$path);
        try {
            $context=hash_init('sha256');
            $read=0;
            while(!feof($stream)) {
                $chunk=fread($stream,1024*1024);
                if($chunk===false) throw new \RuntimeException('Read error for: '.$path);
                if($chunk==='') {
                    if(!feof($stream)) throw new \RuntimeException('Unexpected empty read: '.$path);
                    break;
                }
                hash_update($context,$chunk);
                $read+=strlen($chunk);
                $this->heartbeat($jobId,$token,'hashing',$done,$total);
            }
        } finally {
            fclose($stream);
        }
        clearstatcache(true,$path);
        if($read!==$size || filesize($path)!==$size || filemtime($path)!==$mtime)
            throw new \RuntimeException('File changed while hashing: '.$path);
        return hash_final($context);
    }

    private function heartbeat(int $id,string $token,string $phase,int $current,?int $total): void
    {
        $now=time();
        if($now-$this->lastHeartbeat>=2) {
            $this->jobs->progress($id,$token,$phase,$current,$total);
            $this->lastHeartbeat=$now;
        }
    }
}
