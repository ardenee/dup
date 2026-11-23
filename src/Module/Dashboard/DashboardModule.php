<?php
declare(strict_types=1);
namespace App\Module\Dashboard;
use App\Core\Module\ModuleInterface;
final class DashboardModule implements ModuleInterface
{
    public function id(): string { return 'dashboard'; }
    public function navigation(): array { return [['route'=>'app_dashboard','label'=>'Dashboard']]; }
}
