<?php
declare(strict_types=1);
namespace App\Module\Renaming;

use App\Core\Module\ModuleInterface;

final class RenamingModule implements ModuleInterface
{
    public function id(): string {return 'renaming';}
    public function navigation(): array
    {
        return [['route'=>'app_rename','label'=>'Rename files']];
    }
}
