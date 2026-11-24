<?php
declare(strict_types=1);
namespace App\Module\Duplicates;
use App\Core\Module\ModuleInterface;
final class DuplicatesModule implements ModuleInterface
{
    public function id(): string { return 'duplicates'; }
    public function navigation(): array {
        return [['route'=>'app_duplicates','label'=>'Duplicates','section'=>'find','order'=>10]];
    }
}
