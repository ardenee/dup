<?php
declare(strict_types=1);
namespace App\Core\Diagnostics;

final class CodedInvalidArgumentFailure extends \InvalidArgumentException implements DiagnosticFailure
{
    public function __construct(
        private readonly string $diagnosticCode,
        string $message,
        ?\Throwable $previous=null
    ) {
        parent::__construct($message,0,$previous);
    }

    public function errorCode():string
    {
        return $this->diagnosticCode;
    }
}
