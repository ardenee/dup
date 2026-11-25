<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

/** Distinct job-lease and persistence failures for every source location. */
final class JobFailureCode
{
    private const CATALOG=[
        'DUP-JOB-LEASE-101'=>'Worker token is missing or differs from the claimed job token',
        'DUP-JOB-LEASE-102'=>'Job is no longer running: finished or recovered',
        'DUP-JOB-LEASE-103'=>'Duplicate rebuild lost its worker lease while committing',
        'DUP-JOB-LEASE-999'=>'Legacy uncategorized job-lease error (migration fallback only)',
        'DUP-RENAME-LEASE-101'=>'Rename worker lost its lease during commit',
        'DUP-BULK-LEASE-101'=>'Bulk file-operation worker lost its lease',
        'DUP-FILE-ACTION-LEASE-101'=>'File-action worker lost its lease during commit',
        'DUP-DELETE-LEASE-101'=>'Deletion worker lost its lease',
        'DUP-DELETE-201'=>'Every selected file failed during permanent deletion; inspect DELETE_ITEM_FAILED events for filesystem errors',
        'DUP-FS-105'=>'The operating system rejected a file deletion; inspect worker identity, containing folder permissions, DSM ACL, and the original filesystem error',
        'DUP-MOVE-LEASE-101'=>'Move worker lost its lease',
        'DUP-LINK-LEASE-101'=>'Link worker lost its lease',
        'DUP-JOB-HEARTBEAT-101'=>'Job ownership is valid but a non-no-op heartbeat write was not persisted',
        'DUP-JOB-PROGRESS-101'=>'Scan job ownership is valid but requested progress was not persisted',
        'DUP-JOB-PROGRESS-102'=>'SHA-256 job ownership is valid but requested progress was not persisted',
    ];

    public static function catalog():array
    {
        return self::CATALOG;
    }

    public static function explain(string $code):?string
    {
        return self::CATALOG[$code]??null;
    }
}
