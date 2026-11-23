<?php
declare(strict_types=1);

// Run on a developer machine: php tests/setup-database.php
// No live database credentials or private installation data are needed.
require dirname(__DIR__).'/vendor/autoload.php';

use App\Core\Setup\DatabaseDiagnostic;
use App\Core\Setup\HostEnvironment;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;

function verify(bool $condition, string $description): void
{
    if (!$condition) throw new RuntimeException($description);
}
$packageRoot='/var/packages/dup/target/app';
verify(HostEnvironment::label($packageRoot, 'Linux') === 'Synology DSM', 'Synology detection');
verify(HostEnvironment::mysqlPort($packageRoot, 'Linux') === 3307, 'Synology MariaDB 10 port');
verify(HostEnvironment::label('/srv/dup', 'Linux') === 'Linux', 'Generic Linux');
verify(HostEnvironment::mysqlPort('/srv/dup', 'Linux') === 3306, 'Generic MySQL port');
verify(HostEnvironment::label('C:/dup', 'Windows') === 'Windows', 'Windows detection');

$dsn='mysql://fake_user:fake_password@localhost/dup?charset=utf8mb4&unix_socket=%2Frun%2Fmysqld%2Fmysqld10.sock';
$params=(new DsnParser(['mysql'=>'pdo_mysql']))->parse($dsn);
verify(($params['unix_socket']??null)==='/run/mysqld/mysqld10.sock', 'Socket DSN parsing');
verify(($params['driver']??null)==='pdo_mysql', 'Socket driver parsing');

foreach ([1045, 1049, 2002, 1044] as $code) {
    $error=new PDOException('Sensitive credentials must not appear in error messages');
    $error->errorInfo=['HY000',$code,'secret driver detail'];
    $message=DatabaseDiagnostic::explain($error,true,'TCP 127.0.0.1:3307');
    verify(str_contains($message,(string)$code), 'Error code '.$code);
    verify(!str_contains($message,'Sensitive') && !str_contains($message,'secret'),
        'Sensitive driver text was disclosed');
}
if (in_array('mysql', PDO::getAvailableDrivers(), true)) {
    try {
        $db=DriverManager::getConnection([
            'driver'=>'pdo_mysql','host'=>'127.0.0.1','port'=>1,
            'dbname'=>'dup_inaccessible_test','user'=>'fake_user',
            'password'=>'fake_password','charset'=>'utf8mb4'
        ]);
        $db->executeQuery('SELECT 1');
        throw new RuntimeException('Port 1 unexpectedly accepted a MySQL connection.');
    } catch (\Doctrine\DBAL\Exception $ex) {
        verify(in_array(DatabaseDiagnostic::driverCode($ex),[2002,2003,2005],true),
            'PDO/DBAL error-code extraction');
    }
}
echo "PASS host platform defaults, Synology socket DSN, credential-safe DB diagnostics\n";
