<?php
declare(strict_types=1);
namespace App\Module\System;
use App\Core\Module\ModuleInterface;
final class SystemModule implements ModuleInterface
{
    public function id(): string { return 'system'; }
    public function navigation(): array { return [['route'=>'app_system','label'=>'System']]; }
}
