<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use App\Module\Accounts\Service\AccountManager;
use App\Module\Accounts\Security\DbalUserProvider;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
function verifyAccount(bool $ok,string $message):void {
    if(!$ok) throw new RuntimeException($message);
}
$db=DriverManager::getConnection(['driver'=>'pdo_sqlite','memory'=>true]);
$db->executeStatement('PRAGMA foreign_keys=ON');
$db->executeStatement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT,username TEXT UNIQUE,password_hash TEXT,roles TEXT,created_at TEXT,disabled_at TEXT DEFAULT NULL)');
$db->executeStatement('CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT,actor_id INT,action TEXT,entity_type TEXT,entity_id TEXT,correlation_id TEXT,outcome TEXT,context TEXT,created_at TEXT,FOREIGN KEY(actor_id) REFERENCES users(id))');
$db->executeStatement('CREATE TABLE file_action_batches(id INTEGER PRIMARY KEY,actor_id INT,FOREIGN KEY(actor_id) REFERENCES users(id))');
$db->insert('users',['username'=>'admin','password_hash'=>password_hash('long-secret-1234',PASSWORD_DEFAULT),'roles'=>'["ROLE_ADMIN"]','created_at'=>gmdate('Y-m-d H:i:s')]);
$users=new AccountManager($db);
$provider=new DbalUserProvider($db);
verifyAccount($users->activeAdminCount()===1,'Initial admin not counted');
$id=$users->save(null,'read.only','ROLE_USER','a-password-1234',1);
verifyAccount($id===2,'User creation failed');
verifyAccount(password_verify('a-password-1234',(string)$users->find($id)['password_hash']),'Password hashing failed');
verifyAccount($provider->loadUserByIdentifier('read.only')->getRoles()===['ROLE_USER'],'User role not read-only');
try {$users->enabled(1,false,1);throw new RuntimeException('Allowed administrator self-disable');}
catch(DomainException){}
try {$users->save(1,'admin','ROLE_USER','',1);throw new RuntimeException('Allowed admin self-demotion');}
catch(DomainException){}
try {$users->save(null,'Bad User','ROLE_USER','a-password-1234',1);throw new RuntimeException('Accepted invalid name');}
catch(DomainException){}
try {$users->save(null,'visitor','ROLE_USER','short',1);throw new RuntimeException('Accepted short password');}
catch(DomainException){}
$users->save($id,'reader','ROLE_USER','',1);
verifyAccount($provider->loadUserByIdentifier('reader')->getUserIdentifier()==='reader','User edit failed');
$users->enabled($id,false,1);
verifyAccount((string)$users->find($id)['disabled_at']!=='','Disable did not persist');
try {$provider->loadUserByIdentifier('reader');throw new RuntimeException('Disabled account could log in');}
catch(UserNotFoundException){}
$users->enabled($id,true,1);
verifyAccount($provider->loadUserByIdentifier('reader')->getUserIdentifier()==='reader','Enable failed');
$admin2=$users->save(null,'second-admin','ROLE_ADMIN','a-password-1234',1);
$users->save($admin2,'second-admin','ROLE_USER','',1);
verifyAccount($users->activeAdminCount()===1,'Admin role change count failed');
try{$users->save(1,'renamed','ROLE_ADMIN','',1);throw new RuntimeException('Original admin renamed');}
catch(DomainException){}
verifyAccount((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='ACCOUNT_DISABLED'")===1,'Account audit missing');
verifyAccount(count($users->list())===3,'Account listing failed');
try {$users->delete(1,1);throw new RuntimeException('Allowed account self-deletion');}
catch(DomainException) {}
$db->insert('file_action_batches',['id'=>1,'actor_id'=>$id]);
$db->insert('audit_events',[
    'actor_id'=>$id,'action'=>'ACCOUNT_TEST','entity_type'=>'account','entity_id'=>(string)$id,
    'correlation_id'=>'test','outcome'=>'completed','context'=>'{}',
    'created_at'=>gmdate('Y-m-d H:i:s')
]);
$users->delete($id,1);
verifyAccount(!$users->find($id),'Deleted user login remains in the database.');
verifyAccount($db->fetchOne('SELECT actor_id FROM file_action_batches WHERE id=1')===null,
    'Account deletion left an action foreign key.');
verifyAccount((int)$db->fetchOne('SELECT COUNT(*) FROM audit_events WHERE action=\'ACCOUNT_TEST\'')===1,
    'Account removal erased an audit record.');
try {$provider->loadUserByIdentifier('reader');throw new RuntimeException('Deleted account can log in');}
catch(UserNotFoundException) {}
verifyAccount((int)$db->fetchOne("SELECT COUNT(*) FROM audit_events WHERE action='ACCOUNT_DELETED'")===1,
    'Account deletion was not audited.');
verifyAccount(count($users->list())===2,'Deleted account still appears in list.');
echo "PASS account CRUD including permanent deletion, self-protection, preserved audit and detached foreign keys\n";
