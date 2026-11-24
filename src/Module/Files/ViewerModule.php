<?php
declare(strict_types=1);
namespace App\Module\Files;
use App\Core\Module\ModuleInterface;
final class ViewerModule implements ModuleInterface
{
    public function id():string {return 'viewer';}
    public function navigation():array {return [['route'=>'app_viewer_settings','label'=>'File preview','section'=>'settings','order'=>30]];}
}
