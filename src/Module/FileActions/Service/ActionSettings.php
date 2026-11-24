<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;

/**
 * File Exists decisions are scoped to a scan profile, not a global setting.
 * Every operation reads the same persisted profile policy. Previous global
 * settings are imported once by migration and no longer consulted.
 */
final class ActionSettings
{
    public const FAIL='fail';
    public const NUMBER='number';
    public const OVERWRITE='overwrite';

    public function __construct(private readonly Connection $db) {}

    public static function validate(string $value):string
    {
        if(!in_array($value,[self::FAIL,self::NUMBER,self::OVERWRITE],true))
            throw new \DomainException('Choose Fail, Append Number or Overwrite.');
        return $value;
    }

    public function policy(int $profileId):string
    {
        if($profileId<1) throw new \DomainException('Choose a scan profile.');
        $value=$this->db->fetchOne(
            'SELECT file_exists_policy FROM scan_profiles WHERE id=?',[$profileId]
        );
        if($value===false) throw new \DomainException('The scan profile no longer exists.');
        return self::validate((string)$value);
    }
}
