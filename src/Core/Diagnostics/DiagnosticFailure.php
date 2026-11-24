<?php
declare(strict_types=1);
namespace App\Core\Diagnostics;

/** Stable searchable cause code, separate from an occurrence trace identifier. */
interface DiagnosticFailure
{
    public function errorCode():string;
}
