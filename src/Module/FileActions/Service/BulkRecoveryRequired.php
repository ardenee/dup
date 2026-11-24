<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

/** Stop the bulk queue immediately if the filesystem cannot be rolled back. */
final class BulkRecoveryRequired extends \RuntimeException {}
