<?php
declare(strict_types=1);
namespace App\Module\Accounts;
use App\Core\Module\ModuleInterface;
final class AccountManagementModule implements ModuleInterface
{
    public function id():string {return 'accounts';}
    public function navigation():array {return [['route'=>'app_accounts','label'=>'User accounts','section'=>'settings','order'=>40]];}
}
