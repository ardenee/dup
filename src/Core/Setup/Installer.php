<?php
declare(strict_types=1);

namespace App\Core\Setup;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;
use App\Kernel;

/**
 * One-time, OS-neutral installer. The web front controller never receives
 * OS-specific or DSM-specific knowledge.
 *
 * Existing databases, accounts, and configuration cannot be overwritten.
 */
final class Installer
{
    public function __construct(private readonly string $root) {}

    public function storage(): string { return $this->root.DIRECTORY_SEPARATOR.'var'; }
    public function completePath(): string { return $this->storage().DIRECTORY_SEPARATOR.'setup.complete'; }
    private function envLink(): string { return $this->root.DIRECTORY_SEPARATOR.'.env.local'; }

    public function installed(): bool { return is_file($this->completePath()); }

    public function hasPrivateConfig(): bool
    {
        return file_exists($this->envLink());
    }

    public function prepareStorage(): void
    {
        $folder=$this->storage();
        if(!is_dir($folder) && !mkdir($folder,0700,true) && !is_dir($folder))
            throw new \RuntimeException('The private runtime directory cannot be created.');
        $real=realpath($folder);
        $public=realpath($this->root.DIRECTORY_SEPARATOR.'public');
        if($real===false || $public===false || !is_writable($folder) ||
            $real===$public || str_starts_with(str_replace('\\','/',$real).'/',
                rtrim(str_replace('\\','/',$public),'/').'/')) {
            throw new \RuntimeException('The private runtime directory is not writable or is web-accessible.');
        }
    }

    /** @return array<string,mixed> */
    public function requirements(): array
    {
        $required=['ctype','iconv','json','mbstring','openssl','pdo','xml','intl'];
        $missing=array_values(array_filter($required,
            static fn(string $ext):bool => !extension_loaded($ext)));
        $drivers=class_exists(\PDO::class)?\PDO::getAvailableDrivers():[];
        return [
            'php'=>PHP_VERSION,
            'os'=>PHP_OS_FAMILY,
            'platform'=>HostEnvironment::label($this->root),
            'synology'=>HostEnvironment::isSynology($this->root),
            'mysql_port'=>HostEnvironment::mysqlPort($this->root),
            'synology_socket'=>HostEnvironment::localMariaDbSocket($this->root)!==null,
            'missing'=>$missing,
            'mysql'=>in_array('mysql',$drivers,true),
            'sqlite'=>in_array('sqlite',$drivers,true),
            'private_storage'=>is_dir($this->storage()) && is_writable($this->storage())
        ];
    }

    /** @param array<string,string> $input */
    public function install(array $input): void
    {
        $this->prepareStorage();
        $lock=@fopen($this->storage().DIRECTORY_SEPARATOR.'setup.lock','c');
        if($lock===false || !flock($lock,LOCK_EX|LOCK_NB))
            throw new \DomainException('Another setup is in progress.');
        $wroteConfig=false;
        $connection=null;
        try {
            if($this->installed()) throw new \DomainException('DUP is already installed.');
            if($this->hasPrivateConfig())
                throw new \DomainException('Existing private configuration found. Setup will not overwrite it.');

            $required=$this->requirements();
            if($required['missing'])
                throw new \DomainException('Missing required PHP extensions: '.implode(', ',$required['missing']));
            $driver=(string)($input['driver']??'mysql');
            if(!in_array($driver,['mysql','sqlite'],true))
                throw new \DomainException('Choose MariaDB/MySQL or local SQLite.');
            $username=trim((string)($input['admin_user']??''));
            $password=(string)($input['admin_password']??'');
            $confirmation=(string)($input['confirm_password']??'');
            if(!preg_match('/^[A-Za-z0-9_.-]{3,64}$/D',$username))
                throw new \DomainException('Administrator username must be 3–64 letters, numbers, dots, dashes or underscores.');
            if(strlen($password)<12 || strlen($password)>72 || !hash_equals($password,$confirmation))
                throw new \DomainException('Administrator password must be 12–72 bytes and match confirmation.');

            if($driver==='mysql') {
                if(!$required['mysql']) throw new \DomainException('Enable the PHP pdo_mysql extension.');
                $host=trim((string)($input['db_host']??''));
                $port=filter_var($input['db_port']??(string)HostEnvironment::mysqlPort($this->root),FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1,'max_range'=>65535]]);
                $name=trim((string)($input['db_name']??''));
                $dbUser=trim((string)($input['db_user']??''));
                $dbPass=(string)($input['db_password']??'');
                if(!preg_match('/^[a-zA-Z0-9._:-]{1,255}$/D',$host) || $port===false ||
                    !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$name) ||
                    $dbUser==='' || strlen($dbUser)>128)
                    throw new \DomainException('Database host, name, port or username is invalid.');
                // Use TCP when a host/port is provided: PDO treats "localhost"
                // as an implicit socket connection regardless of the port.
                $tcpHost=$host==='localhost'?'127.0.0.1':$host;
                $options=[
                    'driver'=>'pdo_mysql','host'=>$tcpHost,'port'=>(int)$port,
                    'dbname'=>$name,'user'=>$dbUser,'password'=>$dbPass,'charset'=>'utf8mb4'
                ];
                $uriHost=str_contains($tcpHost,':')?'['.$tcpHost.']':$tcpHost;
                $url='mysql://'.rawurlencode($dbUser).':'.rawurlencode($dbPass).'@'.
                    $uriHost.':'.$port.'/'.rawurlencode($name).'?charset=utf8mb4';
            } else {
                if(!$required['sqlite']) throw new \DomainException('Enable the PHP pdo_sqlite extension.');
                $options=['driver'=>'pdo_sqlite','path'=>$this->storage().DIRECTORY_SEPARATOR.'dup.sqlite'];
                $url='sqlite:///%kernel.project_dir%/var/dup.sqlite';
            }
            $isSynology=HostEnvironment::isSynology($this->root);
            $transport=$driver==='mysql'?'TCP '.$tcpHost.':'.$port:'local SQLite file';
            $candidates=[['options'=>$options,'url'=>$url,'transport'=>$transport]];
            if($driver==='mysql' && $isSynology &&
                in_array($tcpHost,['127.0.0.1','::1'],true)) {
                $socket=HostEnvironment::localMariaDbSocket($this->root);
                if($socket!==null) {
                    // Synology phpMyAdmin commonly uses MariaDB 10's local
                    // socket even when the TCP listener is disabled.
                    $candidates[]=[
                        'options'=>[
                            'driver'=>'pdo_mysql','unix_socket'=>$socket,
                            'dbname'=>$name,'user'=>$dbUser,'password'=>$dbPass,
                            'charset'=>'utf8mb4'
                        ],
                        'url'=>'mysql://'.rawurlencode($dbUser).':'.
                            rawurlencode($dbPass).'@localhost/'.rawurlencode($name).
                            '?charset=utf8mb4&unix_socket='.rawurlencode($socket),
                        'transport'=>'Synology MariaDB 10 local socket'
                    ];
                }
            }
            $lastConnectionError=null;
            $creationError=null;
            foreach($candidates as $candidate) {
                $attemptConnection=null;
                $missingDatabase=false;
                try {
                    $attemptConnection=DriverManager::getConnection($candidate['options']);
                    try {
                        $attemptConnection->executeQuery('SELECT 1')->fetchOne();
                    } catch(\Throwable $ex) {
                        if($driver!=='mysql' ||
                           DatabaseDiagnostic::driverCode($ex)!==1049) throw $ex;
                        // The requested schema is missing. Authenticate against
                        // the server without selecting a schema and CREATE it.
                        // Never reset or overwrite an existing database.
                        $missingDatabase=true;
                        try {
                            DatabaseProvisioner::create($candidate['options'],$name);
                        } catch(\Throwable $creationFailure) {
                            // Another setup may have created the schema first;
                            // reconnect and inspect it before making any writes.
                            if(DatabaseDiagnostic::driverCode($creationFailure)!==1007) {
                                $creationError=DatabaseDiagnostic::explainCreation($creationFailure);
                                DatabaseDiagnostic::logFailure(
                                    $creationFailure,$candidate['transport'].' (create database)');
                                throw $creationFailure;
                            }
                        }
                        $attemptConnection->close();
                        $attemptConnection=DriverManager::getConnection($candidate['options']);
                        $attemptConnection->executeQuery('SELECT 1')->fetchOne();
                    }
                    $connection=$attemptConnection;
                    $url=$candidate['url'];
                    break;
                } catch(\Throwable $ex) {
                    $lastConnectionError=$ex;
                    $transport=$candidate['transport'];
                    if(!$missingDatabase) DatabaseDiagnostic::logFailure($ex,$transport);
                    if($attemptConnection instanceof Connection) $attemptConnection->close();
                    // Try a different transport if TCP authentication or
                    // connectivity fails, or if creating the schema fails here.
                    if(!$missingDatabase && !in_array(DatabaseDiagnostic::driverCode($ex),
                        [null,2002,2003,2005,1044,1045,1698],true)) break;
                }
            }
            if(!($connection instanceof Connection)) {
                throw new \DomainException($creationError ??
                    DatabaseDiagnostic::explain($lastConnectionError,$isSynology,$transport),
                    0,$lastConnectionError);
            }
            try {
                $schemaManager=$connection->createSchemaManager();
                $existingTables=$schemaManager->listTableNames();
                $existingViews=$schemaManager->listViews();
            } catch(\Throwable $ex) {
                DatabaseDiagnostic::logFailure($ex,'schema inspection');
                throw new \DomainException('Connected to the database, but could not inspect its tables. '.
                    'Check the selected database and user privileges'.
                    (($code=DatabaseDiagnostic::driverCode($ex))===null?'': ' (MariaDB error '.$code.')').
                    '.',0,$ex);
            }
            if($existingTables!==[] || $existingViews!==[])
                throw new \DomainException('The selected database contains tables or views. '.
                    'Choose a new database name. DUP never overwrites existing data.');
            $config=[
                'APP_ENV'=>'prod',
                'APP_DEBUG'=>'0',
                'APP_SECRET'=>bin2hex(random_bytes(32)),
                'DATABASE_URL'=>$url
            ];
            $this->writePrivateEnv($config);
            $wroteConfig=true;

            // Boot Symfony after writing a valid private configuration. Reuse its
            // existing Doctrine migrations rather than maintaining separate SQL.
            foreach($config as $key=>$value) {
                $_SERVER[$key]=$value;
                $_ENV[$key]=$value;
                putenv($key.'='.$value);
            }
            (new Dotenv())->bootEnv($this->root.DIRECTORY_SEPARATOR.'.env');
            $kernel=new Kernel('prod',false);
            $application=new Application($kernel);
            $application->setAutoExit(false);
            $output=new BufferedOutput();
            $rc=$application->run(new ArrayInput([
                'command'=>'doctrine:migrations:migrate',
                '--no-interaction'=>true,'--env'=>'prod'
            ]),$output);
            if($rc!==0) throw new \RuntimeException('The initial database migration did not complete.');
            if(!$connection->createSchemaManager()->tablesExist(['users']))
                throw new \RuntimeException('The database schema was not created.');
            if((int)$connection->fetchOne('SELECT COUNT(*) FROM users')!==0)
                throw new \RuntimeException('Administrator records already exist; setup cannot continue.');
            $connection->insert('users',[
                'username'=>$username,
                'password_hash'=>password_hash($password,PASSWORD_DEFAULT),
                'roles'=>'["ROLE_ADMIN"]',
                'created_at'=>gmdate('Y-m-d H:i:s')
            ]);
            $marker=json_encode(['installed_at'=>gmdate('c'),'schema'=>'0.7'],
                JSON_THROW_ON_ERROR)."\n";
            if(file_put_contents($this->completePath(),$marker,LOCK_EX)!==strlen($marker))
                throw new \RuntimeException('Unable to finalize the private setup marker.');
            chmod($this->completePath(),0600);
        } catch(\Throwable $ex) {
            if($wroteConfig && !$this->installed()) {
                $this->deleteNewPrivateEnv();
            }
            throw $ex;
        } finally {
            if($connection instanceof Connection) $connection->close();
            flock($lock,LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,string> $values */
    private function writePrivateEnv(array $values): void
    {
        $link=$this->envLink();
        $path=$link;
        if(is_link($link)) {
            $target=readlink($link);
            if($target===false) throw new \RuntimeException('The private config link cannot be read.');
            $path=($target[0]==='/' || preg_match('~^[A-Za-z]:[\\\\/]~',$target))?
                $target:dirname($link).DIRECTORY_SEPARATOR.$target;
        }
        if(is_file($path)) throw new \DomainException('An existing configuration cannot be overwritten.');
        if(!is_dir(dirname($path)) || !is_writable(dirname($path)))
            throw new \RuntimeException('The private configuration directory is not writable.');
        $contents="# Generated by DUP setup. Keep outside the web root.\n";
        foreach($values as $key=>$value) {
            // JSON strings use double-quoted dotenv values with escaped backslashes.
            // Encode dollar signs to avoid variable interpolation.
            $quoted=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
            // Symfony Dotenv expands $NAME in double quotes. Escape dollars
            // AFTER JSON encoding so the backslash itself is not doubled.
            $quoted=str_replace('$','\\$',$quoted);
            $contents.=$key.'='.$quoted."\n";
        }
        $tmp=tempnam(dirname($path),'.dup-setup-');
        if($tmp===false) throw new \RuntimeException('Unable to create the temporary private config.');
        try {
            if(file_put_contents($tmp,$contents,LOCK_EX)!==strlen($contents))
                throw new \RuntimeException('Unable to write the private configuration.');
            chmod($tmp,0600);
            if(!rename($tmp,$path))
                throw new \RuntimeException('Unable to activate the private configuration.');
        } finally {
            if(is_file($tmp)) @unlink($tmp);
        }
    }

    private function deleteNewPrivateEnv(): void
    {
        $path=$this->envLink();
        if(is_link($path)) {
            $target=readlink($path);
            if($target===false) return;
            $path=($target[0]==='/' || preg_match('~^[A-Za-z]:[\\\\/]~',$target))?
                $target:dirname($path).DIRECTORY_SEPARATOR.$target;
        }
        @unlink($path);
    }
}
