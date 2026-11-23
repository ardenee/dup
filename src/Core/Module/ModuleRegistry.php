<?php
declare(strict_types=1);
namespace App\Core\Module;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
final class ModuleRegistry
{
    /** @var array<string,ModuleInterface> */
    private array $modules = [];
    public function __construct(#[AutowireIterator('dup.module')] iterable $modules)
    {
        foreach($modules as $module) {
            if(isset($this->modules[$module->id()]))
                throw new \LogicException('Duplicate DUP module: '.$module->id());
            $this->modules[$module->id()]=$module;
        }
    }
    /** @return array<array{route:string,label:string}> */
    public function navigation(): array
    {
        $links=[];
        foreach($this->modules as $module)
            foreach($module->navigation() as $item) $links[]=$item;
        return $links;
    }
    public function ids(): array {return array_keys($this->modules);}
}
