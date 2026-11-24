<?php
declare(strict_types=1);
namespace App\Module\Profiles;
use App\Core\Module\ModuleInterface;
final class ProfilesModule implements ModuleInterface
{
    public function id(): string { return 'profiles'; }
    public function navigation(): array { return [
            ['route'=>'app_profiles','label'=>'Scan profiles','section'=>'scan','order'=>10],
            ['route'=>'app_ignore_folders','label'=>'Ignored folders','section'=>'settings','order'=>10]
        ]; }
}
