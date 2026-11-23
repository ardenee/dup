<?php
declare(strict_types=1);
namespace App\Module\FileActions;

use App\Core\Module\ModuleInterface;

final class FileActionsModule implements ModuleInterface
{
    public function id(): string {return 'file-actions';}
    public function navigation(): array
    {
        return [['route'=>'app_actions','label'=>'File actions']];
    }
}
