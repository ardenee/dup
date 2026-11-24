<?php
declare(strict_types=1);
namespace App\Module\Accounts\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/** Audited account management. Disabling preserves every historic foreign key. */
final class AccountManager
{
    public function __construct(private readonly Connection $db) {}

    public function list():array
    {
        return $this->db->fetchAllAssociative(
            'SELECT id,username,roles,created_at,disabled_at FROM users ORDER BY id'
        );
    }

    public function find(int $id):array|false
    {
        return $this->db->fetchAssociative(
            'SELECT id,username,password_hash,roles,disabled_at FROM users WHERE id=?',[$id]
        );
    }

    public function activeAdminCount():int
    {
        $rows=$this->db->fetchFirstColumn('SELECT roles FROM users WHERE disabled_at IS NULL');
        $total=0;
        foreach($rows as $json) {
            $roles=json_decode((string)$json,true);
            if(is_array($roles) && in_array('ROLE_ADMIN',$roles,true)) ++$total;
        }
        return $total;
    }

    private function validateName(string $name):string
    {
        $name=trim($name);
        if(!preg_match('/^[a-z0-9._-]{3,64}$/D',$name))
            throw new \DomainException('Username must be 3–64 lowercase letters, digits, dots, underscores or hyphens.');
        return $name;
    }

    private function hashPassword(string $password):string
    {
        $len=strlen($password);
        if($len<12 || $len>72)
            throw new \DomainException('Password must contain 12–72 bytes.');
        return password_hash($password,PASSWORD_DEFAULT);
    }

    public function save(?int $id,string $name,string $role,string $password,int $actorId):int
    {
        $name=$this->validateName($name);
        if(!in_array($role,['ROLE_USER','ROLE_ADMIN'],true))
            throw new \DomainException('Choose a valid account level.');
        $newHash=$password!==''?$this->hashPassword($password):null;
        if($id===null && $newHash===null)
            throw new \DomainException('A password is required when creating an account.');
        try {
            return $this->db->transactional(function(Connection $db) use (
                $id,$name,$role,$newHash,$actorId
            ):int {
                $now=gmdate('Y-m-d H:i:s');
                if($id===null) {
                    $db->insert('users',[
                        'username'=>$name,'password_hash'=>$newHash,
                        'roles'=>json_encode([$role],JSON_THROW_ON_ERROR),'created_at'=>$now
                    ]);
                    $id=(int)$db->lastInsertId();
                    $this->audit($actorId,$id,'ACCOUNT_CREATED',['role'=>$role]);
                    return $id;
                }
                $old=$this->find($id);
                if(!$old) throw new \DomainException('Account no longer exists.');
                $oldRoles=json_decode((string)$old['roles'],true)?:[];
                $oldAdmin=in_array('ROLE_ADMIN',$oldRoles,true);
                if((string)$old['username']==='admin' &&
                   ($name!=='admin' || $role!=='ROLE_ADMIN'))
                    throw new \DomainException('The original admin account cannot be renamed or demoted.');
                if($id===$actorId && $role!=='ROLE_ADMIN')
                    throw new \DomainException('You cannot demote your own account.');
                if($oldAdmin && $role!=='ROLE_ADMIN' && $old['disabled_at']===null &&
                   $this->activeAdminCount()<=1)
                    throw new \DomainException('At least one active administrator must remain.');
                $update=[
                    'username'=>$name,'roles'=>json_encode([$role],JSON_THROW_ON_ERROR)
                ];
                if($newHash!==null) $update['password_hash']=$newHash;
                $db->update('users',$update,['id'=>$id]);
                $this->audit($actorId,$id,'ACCOUNT_UPDATED',[
                    'role'=>$role,'password_changed'=>$newHash!==null,
                    'username_changed'=>$name!==$old['username']
                ]);
                return $id;
            });
        } catch(UniqueConstraintViolationException $ex) {
            throw new \DomainException('That username is already in use.',0,$ex);
        }
    }

    /** Permanently delete a login, retaining detached action/audit events. */
    public function delete(int $id,int $actorId):void
    {
        $this->db->transactional(function(Connection $db) use ($id,$actorId):void {
            $account=$this->find($id);
            if(!$account) throw new \DomainException('Account no longer exists.');
            if($id===$actorId)
                throw new \DomainException('You cannot delete the account currently signed in.');
            $roles=json_decode((string)$account['roles'],true)?:[];
            if(in_array('ROLE_ADMIN',$roles,true) &&
               $account['disabled_at']===null && $this->activeAdminCount()<=1)
                throw new \DomainException('At least one active administrator must remain.');
            // Preserve the audit/event rows but remove references to the deleted
            // login; foreign keys still protect against orphaned database records.
            $db->executeStatement('UPDATE file_action_batches SET actor_id=NULL WHERE actor_id=?',[$id]);
            $db->executeStatement('UPDATE audit_events SET actor_id=NULL WHERE actor_id=?',[$id]);
            $deleted=$db->delete('users',['id'=>$id]);
            if($deleted!==1) throw new \DomainException('The account was not removed.');
            $this->audit($actorId,$id,'ACCOUNT_DELETED',[
                'username'=>$account['username'],
                'access_removed'=>true
            ]);
        });
    }

    public function enabled(int $id,bool $enable,int $actorId):void
    {
        $this->db->transactional(function(Connection $db) use ($id,$enable,$actorId):void {
            $record=$this->find($id);
            if(!$record) throw new \DomainException('Account no longer exists.');
            if($id===$actorId && !$enable)
                throw new \DomainException('You cannot disable your own account.');
            if($record['username']==='admin' && !$enable)
                throw new \DomainException('The original administrator cannot be disabled.');
            $roles=json_decode((string)$record['roles'],true)?:[];
            if(!$enable && $record['disabled_at']===null &&
               in_array('ROLE_ADMIN',$roles,true) &&
               $this->activeAdminCount()<=1)
                throw new \DomainException('At least one active administrator must remain.');
            if(($record['disabled_at']===null)===$enable) return;
            $db->update('users',[
                'disabled_at'=>$enable?null:gmdate('Y-m-d H:i:s')
            ],['id'=>$id]);
            $this->audit($actorId,$id,$enable?'ACCOUNT_ENABLED':'ACCOUNT_DISABLED',[]);
        });
    }

    private function audit(int $actorId,int $target,string $action,array $context):void
    {
        $this->db->insert('audit_events',[
            'actor_id'=>$actorId,'action'=>$action,
            'entity_type'=>'account','entity_id'=>(string)$target,
            'correlation_id'=>bin2hex(random_bytes(16)),'outcome'=>'completed',
            'context'=>json_encode($context,JSON_THROW_ON_ERROR),
            'created_at'=>gmdate('Y-m-d H:i:s')
        ]);
    }
}
