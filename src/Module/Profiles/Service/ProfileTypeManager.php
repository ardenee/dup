<?php
declare(strict_types=1);
namespace App\Module\Profiles\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Profile file-type presets are configuration, not scan history. */
final class ProfileTypeManager
{
    public function __construct(private readonly Connection $db) {}

    public function all(): array
    {
        return $this->db->fetchAllAssociative(
            'SELECT t.id,t.name,t.extensions,COUNT(p.id) AS used_by '.
            'FROM profile_types t LEFT JOIN scan_profiles p ON p.profile_type_id=t.id '.
            'GROUP BY t.id,t.name,t.extensions ORDER BY t.name,t.id'
        );
    }

    public function find(int $id): array|false
    {
        return $this->db->fetchAssociative(
            'SELECT id,name,extensions FROM profile_types WHERE id=?',[$id]
        );
    }

    public function normalize(string $raw): string
    {
        if(strlen($raw)>3000) throw new \DomainException('Extension list is too long.');
        $tokens=[];
        foreach(explode(',',$raw) as $part) {
            $part=strtolower(ltrim(trim($part),'.'));
            if($part==='') continue;
            if($part==='*') {
                if(count(explode(',',$raw))!==1)
                    throw new \DomainException('Use * alone for all file types.');
                return '';
            }
            if(!preg_match('/^[a-z0-9][a-z0-9_+-]{0,19}$/D',$part))
                throw new \DomainException('Extensions must be comma-separated values, such as mp3,m4b,flac.');
            $tokens[$part]=$part;
        }
        return implode(',',array_values($tokens)); // Empty means all file types.
    }

    public function save(?int $id,string $name,string $extensions): int
    {
        $name=trim($name);
        if($name==='' || mb_strlen($name)>100 || str_contains($name,"\0"))
            throw new \DomainException('Preset name must be 1–100 characters.');
        $extensions=$this->normalize($extensions);
        try {
            return $this->db->transactional(function(Connection $db) use (
                $id,$name,$extensions
            ):int {
                if($id===null) {
                    $db->insert('profile_types',['name'=>$name,'extensions'=>$extensions]);
                    return (int)$db->lastInsertId();
                }
                if(!$this->find($id)) throw new \DomainException('Preset no longer exists.');
                $db->update('profile_types',['name'=>$name,'extensions'=>$extensions],['id'=>$id]);
                return $id;
            });
        } catch(UniqueConstraintViolationException $ex) {
            throw new \DomainException('Another preset already has this name.',0,$ex);
        }
    }

    public function remove(int $id): void
    {
        $this->db->transactional(function(Connection $db) use ($id):void {
            if(!$this->find($id)) throw new \DomainException('Preset no longer exists.');
            if((int)$db->fetchOne(
                'SELECT COUNT(*) FROM scan_profiles WHERE profile_type_id=?',[$id]
            )>0) throw new \DomainException(
                'This preset is assigned to profiles. Change those profiles before deleting it.'
            );
            $db->delete('profile_types',['id'=>$id]);
        });
    }
}
