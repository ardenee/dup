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
        $sections=['overview'=>0,'find'=>10,'scan'=>20,'actions'=>30,'settings'=>40];
        usort($links,static function(array $a,array $b) use ($sections):int {
            $one=$sections[$a['section']??'']??90;
            $two=$sections[$b['section']??'']??90;
            return ($one<=>$two) ?: (($a['order']??50)<=>($b['order']??50))
                ?: strcmp((string)$a['label'],(string)$b['label']);
        });
        return $links;
    }
    public function ids(): array {return array_keys($this->modules);}
}
