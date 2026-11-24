<?php
declare(strict_types=1);
namespace App\Module\FileActions\Service;

use Doctrine\DBAL\Connection;

/**
 * Global destination conflict preference: fail, number or explicitly replace.
 * An approved overwrite records the existing target and temporarily stages it
 * during the file operation for rollback if a later step fails.
 */
final class ActionSettings
{
    public const FAIL='fail';
    public const NUMBER='number';
    public const OVERWRITE='overwrite';

    public function __construct(private readonly Connection $db) {}

    public function policy():string
    {
        $stored=$this->db->fetchOne(
            'SELECT setting_value FROM action_settings WHERE setting_key=?',
            ['destination_exists']
        );
        return in_array($stored,[self::FAIL,self::NUMBER,self::OVERWRITE],true)
            ?(string)$stored:self::FAIL;
    }

    public function save(string $value,int $actorId):void
    {
        if(!in_array($value,[self::FAIL,self::NUMBER,self::OVERWRITE],true))
            throw new \DomainException('Choose Fail, Append Number or Overwrite.');
        $this->db->transactional(function(Connection $db) use($value,$actorId):void {
            $current=$db->fetchOne(
                'SELECT setting_key FROM action_settings WHERE setting_key=?',
                ['destination_exists']
            );
            $now=gmdate('Y-m-d H:i:s');
            if($current===false) $db->insert('action_settings',[
                'setting_key'=>'destination_exists','setting_value'=>$value,'updated_at'=>$now
            ]);
            else $db->update('action_settings',[
                'setting_value'=>$value,'updated_at'=>$now
            ],['setting_key'=>'destination_exists']);
            $db->insert('audit_events',[
                'actor_id'=>$actorId,'action'=>'FILE_EXISTS_POLICY_CHANGED',
                'entity_type'=>'settings','entity_id'=>'destination_exists',
                'correlation_id'=>bin2hex(random_bytes(16)),
                'outcome'=>'completed','created_at'=>$now,
                'context'=>json_encode(['policy'=>$value],JSON_THROW_ON_ERROR)
            ]);
        });
    }
}
