<?php
declare(strict_types=1);

namespace App\Core\Config;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

/**
 * Expand only DUP's well-defined project path placeholder in SQLite URLs.
 *
 * Symfony's general "resolve" env processor also treats percent-encoded URL
 * credentials (e.g. %40 for @) as dependency-container parameter references.
 * That breaks valid MariaDB/MySQL passwords. Database credentials must be
 * delivered untouched to Doctrine's URL parser.
 */
final class DatabaseUrlEnvProcessor implements EnvVarProcessorInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir
    ) {}

    public function getEnv(string $prefix,string $name,\Closure $getEnv): string
    {
        $raw=$getEnv($name);
        if(!is_string($raw) || $raw==='') {
            throw new \RuntimeException('DATABASE_URL must be a nonempty connection string.');
        }

        // For SQLite only, replace the exact app-owned variable. Do not
        // interpolate arbitrary %...% content or touch external DB credentials.
        if(str_starts_with($raw,'sqlite:')) {
            return str_replace('%kernel.project_dir%',$this->projectDir,$raw);
        }

        return $raw;
    }

    public static function getProvidedTypes(): array
    {
        return ['dup_database_url'=>'string'];
    }
}
