<?php
declare(strict_types=1);
namespace App\Core\Module;
interface ModuleInterface
{
    public function id(): string;
    /** @return array<array{route:string,label:string,section?:string,order?:int}> */
    public function navigation(): array;
}
