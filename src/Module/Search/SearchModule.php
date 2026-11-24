<?php
declare(strict_types=1);
namespace App\Module\Search;

use App\Core\Module\ModuleInterface;

final class SearchModule implements ModuleInterface
{
    public function id(): string {return 'search';}
    public function navigation(): array
    {
        return [['route'=>'app_search','label'=>'Find files','section'=>'find','order'=>20]];
    }
}
