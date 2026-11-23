<?php
declare(strict_types=1);
namespace App\Core\Twig;
use App\Core\Module\ModuleRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
final class NavigationExtension extends AbstractExtension
{
    public function __construct(private readonly ModuleRegistry $registry) {}
    public function getFunctions(): array
    {
        return [new TwigFunction('dup_navigation', [$this->registry,'navigation'])];
    }
}
