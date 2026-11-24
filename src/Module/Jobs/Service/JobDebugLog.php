<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

/**
 * Persistent, database-independent diagnostic logger for CLI jobs.
 *
 * Enabled by default for diagnostics (especially SHA-256 verification).
 * Messages are JSONL in a private path, bounded per job with 2 rotations.
 * Logging errors may never crash or conceal the actual worker exception.
 */
final class JobDebugLog
{
    private const MAX_BYTES=2*1024*1024;
    private const BACKUPS=2;
    private const MAX_DISPLAY=250;
    private readonly string $directory;
    private ?string $lastWriteError=null;
    private static ?string $reportedFailure=null;
    private static int $lastReportedAt=0;

    // A non-default directory is used by isolated diagnostic tests only;
    // web endpoints never accept filesystem paths from user input.
    public function __construct(?string $directory=null)
    {
        $this->directory=$directory??dirname(__DIR__,4).'/var/log/dup-jobs';
    }

    public function lastWriteError():?string
    {
        return $this->lastWriteError;
    }

    private function reportFailure(int $id,string $event,string $why):void
    {
        $this->lastWriteError=$why;
        // Failures MUST be observable independently of this log file.
        // On DSM PHP stderr is captured by worker-output.log.
        $message='DUP DEBUG LOG WRITE FAILED job='.$id.
            ' event='.substr($event,0,64).' reason='.substr($why,0,250);
        if(self::$reportedFailure!==$message || time()-self::$lastReportedAt>=30) {
            error_log($message);
            self::$reportedFailure=$message;
            self::$lastReportedAt=time();
        }
    }

    /**
     * Web-side read/write self-test, not a worker liveness test. It writes to
     * the exact private log destination used by the CLI worker and then reads
     * the record back, rather than trusting is_writable().
     */
    public function probe():array
    {
        $marker=bin2hex(random_bytes(8));
        $written=$this->write(0,'info','diagnostics.web_write_probe',[
            'source'=>'web','probe_marker'=>$marker,'php_version'=>PHP_VERSION
        ]);
        $read=$written?$this->read(0):['available'=>false,'rows'=>[]];
        $found=false;
        foreach($read['rows']??[] as $record) {
            if(($record['event']??null)==='diagnostics.web_write_probe' &&
                ($record['data']['probe_marker']??null)===$marker) {
                $found=true;
                break;
            }
        }
        return [
            'success'=>$written && $found,
            'written'=>$written,'read_back'=>$found,
            'error'=>$this->lastWriteError??(
                $written && !$found?'The probe was written but could not be read back.':null
            ),
            'path'=>$this->path(0),
            'note'=>'This tests Web Station PHP access, not the background CLI worker. '.
                'The worker must also emit worker.boot in this log.'
        ];
    }

    /**
     * Read-only health check for the private debug directory.
     * Reports meaningful permission failures without requiring the database
     * or modifying files from the web-facing process.
     */
    public function status():array
    {
        $dir=$this->directory;
        $file=$this->path(0);
        $exists=is_dir($dir);
        $readable=$exists && is_readable($dir) &&
            (PHP_OS_FAMILY==='Windows' || is_executable($dir));
        $writable=$exists && is_writable($dir);
        $logExists=is_file($file);
        $logReadable=$logExists && is_readable($file);
        $user='unknown';
        if(function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info=@posix_getpwuid(posix_geteuid());
            if(is_array($info) && isset($info['name']))$user=(string)$info['name'];
        }
        return [
            'directory'=>$dir,'directory_exists'=>$exists,
            'directory_readable'=>$readable,
            'directory_writable'=>$writable,
            'worker_log_exists'=>$logExists,'worker_log_readable'=>$logReadable,
            'worker_log_updated'=>$logReadable ?
                (($mtime=@filemtime($file))?gmdate('Y-m-d H:i:s',$mtime):null):null,
            'free_bytes'=>$exists?(@disk_free_space($dir)?:null):null,
            'last_write_error'=>$this->lastWriteError,
            'web_user'=>$user,'open_basedir'=>(string)(ini_get('open_basedir')?:'Not restricted'),
            'status'=>!$exists?'not_created':
                (!$readable?'permission_denied':
                (!$logExists?'awaiting_worker_log':
                (!$logReadable?'log_permission_denied':
                (!$writable?'read_only':'ready')))),
            'help'=>!$exists?
                'No worker has created this directory yet. If the package service is running, check the DSM supervisor log.':
                (!$readable?
                'The web PHP account cannot traverse the private log directory. Check the http group and directory permissions.':
                (!$logExists?
                'No worker lifecycle log has been written. Existing jobs from before instrumentation cannot acquire new logs.':
                (!$logReadable?
                'The worker log exists, but Web Station cannot read it. Verify group read access for the http group.':
                ($writable ?
                'Private debug storage is readable and the directory appears writable. Run the actual write/read test to confirm.' :
                'The web PHP account can read the diagnostic files but cannot write new records here. Run the write/read test to confirm.'))))
        ];
    }

    /** No caller-supplied log path is ever accepted. */
    public function path(int $id):string
    {
        if($id<0)throw new \InvalidArgumentException('Invalid job ID.');
        return $this->directory.'/'.($id===0?'worker':'job-'.$id).'.jsonl';
    }

    public function write(int $jobId,string $level,string $event,array $data=[]):bool
    {
        $this->lastWriteError=null;
        try {
            if($jobId<0)throw new \InvalidArgumentException('Negative diagnostic job id.');
            if(!in_array($level,['debug','info','warning','error'],true))$level='info';
            if(!is_dir($this->directory)) {
                if(!@mkdir($this->directory,0750,true) && !is_dir($this->directory))
                    throw new \RuntimeException('Could not create private diagnostic directory: '.$this->directory);
            }
            $path=$this->path($jobId);
            $stamp=\DateTimeImmutable::createFromFormat('U.u',sprintf('%.6F',microtime(true)))
                ?:new \DateTimeImmutable('now',new \DateTimeZone('UTC'));
            $record=[
                'utc'=>$stamp->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                'level'=>$level,'event'=>mb_substr($event,0,96),
                'job_id'=>$jobId,'worker_pid'=>getmypid(),
                'host'=>gethostname()?:'unknown',
                'memory_mib'=>round(memory_get_usage(true)/1048576,1),
                'data'=>$this->redact($data)
            ];
            $line=json_encode($record,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE)."\n";
            if(strlen($line)>32768)
                $line=json_encode(array_replace($record,['data'=>['note'=>'Diagnostic entry too large; omitted']]),
                    JSON_THROW_ON_ERROR)."\n";

            $handle=@fopen($path,'ab');
            if(!is_resource($handle))
                throw new \RuntimeException('Could not open diagnostic log for append: '.$path);
            try {
                if(!flock($handle,LOCK_EX|LOCK_NB))
                    throw new \RuntimeException('Diagnostic log lock is busy: '.$path);
                try {
                    $size=(@fstat($handle))['size']??0;
                    if($size+strlen($line)>self::MAX_BYTES) {
                        flock($handle,LOCK_UN);
                        fclose($handle);
                        $handle=null;
                        for($i=self::BACKUPS;$i>=1;$i--) {
                            $source=$i===1?$path:$path.'.'.($i-1);
                            $target=$path.'.'.$i;
                            if(is_file($source))@rename($source,$target);
                        }
                        $handle=@fopen($path,'ab');
                        if(!is_resource($handle))
                            throw new \RuntimeException('Could not open rotated diagnostic log: '.$path);
                        if(!flock($handle,LOCK_EX|LOCK_NB))
                            throw new \RuntimeException('Rotated diagnostic log lock is busy: '.$path);
                    }
                    $length=strlen($line);
                    $written=0;
                    while($written<$length) {
                        $count=fwrite($handle,substr($line,$written));
                        if($count===false || $count===0)
                            throw new \RuntimeException('Short write to diagnostic log: '.$path);
                        $written+=$count;
                    }
                    if(!fflush($handle))
                        throw new \RuntimeException('Failed to flush diagnostic log: '.$path);
                } finally {
                    if(is_resource($handle))flock($handle,LOCK_UN);
                }
            } finally {
                if(is_resource($handle))fclose($handle);
            }
            @chmod($path,0640);
            // On DSM the package user belongs to 'http'; this grants the
            // administrator-only PHP view read access without exposing the
            // private log over the web server's document root.
            if(PHP_OS_FAMILY==='Linux' && function_exists('posix_getgrnam')) {
                $group=@posix_getgrnam('http');
                if(is_array($group) && isset($group['gid']))@chgrp($path,(int)$group['gid']);
            }
            return true;
        } catch(\Throwable $failure) {
            $this->reportFailure($jobId,$event,$failure->getMessage());
            return false;
        }
    }

    private function redact(array $data):array
    {
        $out=[];
        foreach($data as $key=>$value) {
            $name=(string)$key;
            if(preg_match('/password|secret|credential|token|authorization|cookie|database_url/i',$name)) {
                $out[$name]='[REDACTED]';
            } elseif(is_array($value)) {
                $out[$name]=$this->redact($value);
            } elseif(is_string($value)) {
                $out[$name]=mb_substr($value,0,4096);
            } elseif(is_scalar($value)||$value===null) {
                $out[$name]=$value;
            } else {
                $out[$name]=get_debug_type($value);
            }
        }
        return $out;
    }

    /** Recent per-job logs without querying the database. */
    public function recentJobs(int $limit=15):array
    {
        $limit=max(1,min(40,$limit));
        if(!is_dir($this->directory) || !is_readable($this->directory))return [];
        $found=[];
        try {
            foreach(new \DirectoryIterator($this->directory) as $entry) {
                if(!$entry->isFile() ||
                    !preg_match('/^job-(\\d+)\\.jsonl$/D',$entry->getFilename(),$matches))
                    continue;
                $id=(int)$matches[1];
                if($id<1)continue;
                $found[]=[
                    'id'=>$id,
                    'updated_at'=>gmdate('Y-m-d H:i:s',$entry->getMTime()),
                    'mtime'=>$entry->getMTime(),
                    'bytes'=>$entry->getSize()
                ];
            }
        } catch(\Throwable $ex) {
            $this->reportFailure(0,'list.recent',$ex->getMessage());
            return [];
        }
        usort($found,static fn(array $a,array $b)=>
            $b['mtime']<=>$a['mtime'] ?: $b['id']<=>$a['id']);
        return array_slice($found,0,$limit);
    }

    /** Export the last three rotated files, bounded to six MiB. */
    public function export(int $jobId):?string
    {
        $path=$this->path($jobId);
        $text='';
        for($i=self::BACKUPS;$i>=0;$i--) {
            $file=$i===0?$path:$path.'.'.$i;
            if(is_file($file) && is_readable($file)) {
                $chunk=@file_get_contents($file);
                if(is_string($chunk))$text.=$chunk;
            }
        }
        return $text===''?null:$text;
    }

    /** Remove all rotated logs when an administrator deletes job history. */
    public function purge(int $jobId):void
    {
        if($jobId<=0)return;
        try {
            $base=$this->path($jobId);
            foreach([$base,$base.'.1',$base.'.2'] as $file) {
                if(is_file($file))@unlink($file);
            }
        } catch(\Throwable) {}
    }

    /** Latest bounded records; no SQL or network calls. */
    public function read(int $jobId,int $max=self::MAX_DISPLAY):array
    {
        $max=max(1,min(self::MAX_DISPLAY,$max));
        $path=$this->path($jobId);
        if(!is_file($path) || !is_readable($path))
            return ['available'=>false,'path'=>$path,'rows'=>[],
                'message'=>is_file($path)?
                    'The debug log exists but this PHP account cannot read it. On DSM, verify the private debug directory and files grant read access to the http group.':
                    'No diagnostic log for this job yet. Logs begin when a new job runs with detailed logging installed.'];
        $handle=@fopen($path,'rb');
        if($handle===false)
            return ['available'=>false,'path'=>$path,'rows'=>[],
                'message'=>'Could not open this log from the Web Station PHP account.'];
        try {
            $stat=fstat($handle);
            $size=(int)($stat['size']??0);
            // Bounded read from the end, preserving an appended line.
            if($size>self::MAX_BYTES)fseek($handle,$size-self::MAX_BYTES);
            $text=(string)stream_get_contents($handle,self::MAX_BYTES);
        } finally {fclose($handle);}
        $lines=explode("\n",$text);
        $rows=[];
        foreach(array_slice($lines,-$max-2) as $line) {
            $row=json_decode($line,true);
            if(is_array($row) && isset($row['utc'],$row['event'],$row['level']))
                $rows[]=$row;
        }
        return ['available'=>true,'path'=>$path,'rows'=>array_slice($rows,-$max),
            'last_updated'=>($mtime=@filemtime($path))?gmdate('Y-m-d H:i:s',$mtime):null,
            'bytes'=>$size,'truncated'=>$size>self::MAX_BYTES ||
                count($lines)>$max+2,'message'=>''];
    }
}
