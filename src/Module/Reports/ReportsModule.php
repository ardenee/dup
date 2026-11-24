<?php
declare(strict_types=1);

namespace App\Module\Reports;

use App\Core\Module\ModuleInterface;

final class ReportsModule implements ModuleInterface
{
    public function id(): string {return 'reports';}
    public function navigation(): array
    {
        return [['route'=>'app_reports','label'=>'Scan history & reports','section'=>'scan','order'=>30]];
    }
}
