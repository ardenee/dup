<?php
declare(strict_types=1);
namespace App\Module\Profiles;
use App\Core\Module\ModuleInterface;
final class ProfilesModule implements ModuleInterface
{
    public function id(): string { return 'profiles'; }
    public function navigation(): array { return [['route'=>'app_profiles','label'=>'Profiles']]; }
}
