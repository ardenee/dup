<?php
declare(strict_types=1);
namespace App\Module\Jobs;
use App\Core\Module\ModuleInterface;
final class JobsModule implements ModuleInterface
{
    public function id(): string { return 'jobs'; }
    public function navigation(): array { return [['route'=>'app_jobs','label'=>'Jobs']]; }
}
