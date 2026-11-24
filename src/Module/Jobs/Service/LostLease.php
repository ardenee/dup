<?php
declare(strict_types=1);
namespace App\Module\Jobs\Service;

use App\Core\Diagnostics\DiagnosticFailure;

/** A verified ownership/state mismatch, not a zero-change SQL UPDATE. */
final class LostLease extends \RuntimeException implements DiagnosticFailure
{
    public function __construct(
        string $message,
        private readonly string $diagnosticCode='DUP-JOB-LEASE-999'
    ) {
        parent::__construct($message);
    }

    public function errorCode():string
    {
        return $this->diagnosticCode;
    }
}
