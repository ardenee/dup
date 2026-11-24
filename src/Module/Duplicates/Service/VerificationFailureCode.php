<?php
declare(strict_types=1);
namespace App\Module\Duplicates\Service;

use App\Core\Diagnostics\DiagnosticFailure;
use App\Module\Jobs\Service\LostLease;
use App\Module\Jobs\Service\ScanCancelled;
use Doctrine\DBAL\Exception as DatabaseException;

/**
 * Stable diagnostic catalog.
 *
 * Every code identifies a component + operation + failure condition.
 * A code is reusable ONLY when exactly the same condition recurs.
 * A trace ID is unique for each failed attempt. Never use the PID or
 * an exception message to generate a new, unstable error code.
 *
 * Five-digit verification codes: SSSCC = site number + cause family.
 * Named source-level failures have distinct three-digit HASH/FS/VERIFY codes.
 */
final class VerificationFailureCode
{
    private const SITES=[
        'preflight_event'          => [101,'Recording the preflight event'],
        'preflight_lookup'         => [102,'Reading the selected scan'],
        'preflight_latest'         => [103,'Checking the latest completed scan'],
        'candidate_count'          => [104,'Counting SHA-256 candidate files'],
        'initial_progress'         => [105,'Persisting verification start'],
        'verification_started_event'=>[106,'Recording verification start'],
        'candidate_lease'          => [107,'Checking worker lease before candidate search'],
        'candidate_progress'       => [108,'Updating candidate lookup progress'],
        'candidate_event'          => [109,'Recording candidate lookup event'],
        'finding_candidate_files'  => [110,'Querying the candidate file page'],
        'candidate_page_event'     => [111,'Recording candidate page completion'],
        'file_lease'               => [112,'Checking worker lease before file access'],
        'file_opening_event'       => [113,'Recording file opening event'],
        'checking_file_access'     => [114,'Validating source file path'],
        'file_initial_progress'    => [115,'Updating first file progress'],
        'file_started_event'       => [116,'Recording first file-start event'],
        'reading_file'             => [117,'Reading source bytes for SHA-256'],
        'staging_hash'             => [118,'Inserting temporary verified hash'],
        'file_completion_progress' => [119,'Updating file completion progress'],
        'file_completion_event'    => [120,'Recording file completion event'],
        'publishing_progress'      => [121,'Updating result publishing progress'],
        'publishing_event'         => [122,'Recording result publishing event'],
        'publishing'               => [123,'Publishing hashes and rebuilding duplicate groups'],
        'cleanup'                  => [124,'Removing temporary verification hashes'],
        'unknown'                  => [999,'Unknown verification operation']
    ];

    // Classified by exception type; never rely on English error messages.
    private const CAUSES=[
        'database'  => [1,'Database operation failed'],
        'lease'     => [2,'Worker lease was lost'],
        'io'        => [3,'Filesystem I/O failed'],
        'domain'    => [4,'Invalid scan or filesystem state'],
        'runtime'   => [5,'Runtime operation failed'],
        'type'      => [6,'PHP type mismatch'],
        'logic'     => [7,'Internal logic error'],
        'php'       => [8,'PHP engine error'],
        'cancelled' => [9,'Operation was cancelled'],
        'other'     => [99,'Unclassified exception']
    ];

    /** Each explicit throw site has its own permanent code. */
    private const EXPLICIT=[
        'DUP-VERIFY-301'=>'Verification job has the wrong kind',
        'DUP-VERIFY-302'=>'Selected scan record does not exist',
        'DUP-VERIFY-303'=>'Selected scan is not completed',
        'DUP-VERIFY-304'=>'Selected scan belongs to another profile',
        'DUP-VERIFY-305'=>'A newer completed scan has replaced the selected scan',
        'DUP-VERIFY-306'=>'Not enough accessible candidate files remain',
        'DUP-VERIFY-307'=>'Candidate count or byte total changed while verifying',
        'DUP-VERIFY-308'=>'A newer scan completed while publishing hashes',
        'DUP-VERIFY-309'=>'The number of published hashes does not match candidate count',
        'DUP-FS-101'=>'Recorded filename contains invalid path characters',
        'DUP-FS-102'=>'Recorded source directory is inaccessible or has changed',
        'DUP-FS-103'=>'Recorded source path is now a symbolic link',
        'DUP-FS-104'=>'Recorded source file is missing',
        'DUP-HASH-101'=>'Hashing strategy is invalid',
        'DUP-HASH-102'=>'Candidate is a symbolic link before hashing',
        'DUP-HASH-103'=>'Candidate file is missing before hashing',
        'DUP-HASH-104'=>'Candidate is not readable before hashing',
        'DUP-HASH-105'=>'Candidate size changed before hashing',
        'DUP-HASH-106'=>'Candidate modification time changed before hashing',
        'DUP-HASH-107'=>'Cannot open candidate stream for hashing',
        'DUP-HASH-108'=>'Cannot seek candidate stream',
        'DUP-HASH-109'=>'Cannot read candidate stream',
        'DUP-HASH-110'=>'Candidate length changed while reading',
        'DUP-HASH-111'=>'Candidate size changed after hashing',
        'DUP-HASH-112'=>'Candidate modification time changed after hashing',
        'DUP-HASH-113'=>'Candidate became a symbolic link while hashing',
        'DUP-HASH-114'=>'Incomplete CRC32 sample read',
        'DUP-HASH-115'=>'Invalid duplicate grouping strategy',
        'DUP-WORKER-001'=>'Worker exited with no independently recorded root error',
        'DUP-WORKER-002'=>'PHP fatal error at worker shutdown',
        'DUP-WORKER-003'=>'Exception escaped to the outer worker loop',
        'DUP-WORKER-004'=>'Worker exited because the diagnostic log could not be written'
    ];

    public static function identify(string $stage,?\Throwable $error=null):array
    {
        [$site,$label]=self::SITES[$stage]??self::SITES['unknown'];
        $cause=self::cause($error);
        [$family,$description]=self::CAUSES[$cause];
        return [
            'error_code'=>sprintf('DUP-VERIFY-%03d%02d',$site,$family),
            'operation'=>$label,
            'failure_condition'=>$description,
            'stage'=>$stage
        ];
    }

    private static function cause(?\Throwable $error):string
    {
        if($error===null)return 'other';
        if($error instanceof DatabaseException || $error instanceof \PDOException)
            return 'database';
        if($error instanceof LostLease)return 'lease';
        if($error instanceof ScanCancelled)return 'cancelled';
        if($error instanceof \TypeError || $error instanceof \ValueError)return 'type';
        if($error instanceof \DomainException || $error instanceof \InvalidArgumentException)
            return 'domain';
        if($error instanceof \LogicException)return 'logic';
        if($error instanceof \Error)return 'php';
        if($error instanceof \RuntimeException)return 'runtime';
        return 'other';
    }

    public static function explain(string $errorCode):?string
    {
        if(isset(self::EXPLICIT[$errorCode]))return self::EXPLICIT[$errorCode];
        if(preg_match('/^DUP-VERIFY-(\d{3})(\d{2})$/D',$errorCode,$parts)) {
            $site=(int)$parts[1];$cause=(int)$parts[2];
            foreach(self::SITES as [$id,$operation]) {
                if($id!==$site)continue;
                foreach(self::CAUSES as [$family,$description]) {
                    if($family===$cause)return $operation.' — '.$description;
                }
            }
        }
        return null;
    }

    /** Full registry used for duplicate-code checks and operator documentation. */
    public static function catalog():array
    {
        $result=self::EXPLICIT;
        foreach(self::SITES as [$site,$operation]) {
            foreach(self::CAUSES as [$family,$description]) {
                $code=sprintf('DUP-VERIFY-%03d%02d',$site,$family);
                if(isset($result[$code]))
                    throw new \LogicException('Duplicate diagnostic code: '.$code);
                $result[$code]=$operation.' — '.$description;
            }
        }
        ksort($result);
        return $result;
    }

    public static function trace(int $jobId):string
    {
        return 'V'.$jobId.'-'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));
    }

    public static function detail(string $stage,\Throwable $error,string $trace):array
    {
        $classified=$error instanceof DiagnosticFailure?
            [
                'error_code'=>$error->errorCode(),
                'operation'=>self::explain($error->errorCode())??'Coded source failure',
                'failure_condition'=>'Explicitly classified at throw site',
                'stage'=>$stage
            ]:self::identify($stage,$error);
        return $classified+[
            'trace_id'=>$trace,
            'exception_type'=>get_class($error),
            'exception_message'=>$error->getMessage(),
            'source_file'=>$error->getFile(),
            'source_line'=>$error->getLine(),
            'exception_code'=>$error->getCode()
        ];
    }
}
