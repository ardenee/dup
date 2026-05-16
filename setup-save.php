<?php
ini_set("log_errors", "1");
ini_set("error_log", __DIR__ . "/logs/php_errors.log");
//ini_set("display_errors", "1");
ini_set("display_errors", "0");

function SetupFail($message, $exception = null)
{
    $log = date("Y-m-d H:i:s") . " | SETUP_FAIL | " . $message . "\n";

    if($exception)
        $log .= print_r($exception, true) . "\n";

    if(!is_dir(__DIR__ . "/logs"))
        @mkdir(__DIR__ . "/logs", 0777, true);

    file_put_contents(__DIR__ . "/logs/setup_error.txt", $log, FILE_APPEND);
    file_put_contents(__DIR__ . "/logs/last_error.txt", $log);

    ShowSetupResultPage(
        "DUP setup failed",
        array(
            "Setup could not complete.",
            "The details below were also written to logs/setup_error.txt and logs/last_error.txt."
        ),
        array(
            "Error details" => $log
        ),
        false
    );
}

function ShowSetupResultPage($title, $messages, $details = array(), $success = false)
{
    $alertClass = $success ? "alert-success" : "alert-error";

    echo "<!DOCTYPE HTML>\n";
    echo "<HTML>\n";
    echo "<HEAD>\n";
    echo "<TITLE>dup - Setup</TITLE>\n";
    echo "<META CHARSET=\"utf-8\">\n";
    echo "<LINK REL=\"shortcut icon\" HREF=\"images/dup.png\">\n";
    echo "<LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/bootstrap.css\">\n";
    echo "<LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/jquery-ui-1.8.24.custom.css\">\n";
    echo "<LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/style.css\">\n";
    echo "<LINK REL=\"stylesheet\" TYPE=\"text/css\" HREF=\"files/imgs.css\">\n";
    echo "</HEAD>\n";
    echo "<BODY>\n";
    echo "<HEADER><DIV class=\"navbar\"><DIV class=\"navbar-inner\"><DIV class=\"container\"><A class=\"brand\" href=\"index.php\">DUP</A></DIV></DIV></DIV></HEADER>\n";
    echo "<div id=\"contentWrapper\"><div id=\"content\">\n";
    echo "<h1 class=\"title\">" . htmlspecialchars($title, ENT_QUOTES, "UTF-8") . "</h1>\n";
    echo "<div id=\"config\"><div id=\"config-content\"><div id=\"config-components\">\n";
    echo "<div class=\"alert " . $alertClass . "\">\n";

    foreach($messages as $message)
        echo "<p>" . htmlspecialchars($message, ENT_QUOTES, "UTF-8") . "</p>\n";

    if(count($details) > 0)
    {
        echo "<table class=\"table table-striped table-bordered\"><tbody>\n";

        foreach($details as $key => $value)
        {
            echo "<tr><td><b>" . htmlspecialchars($key, ENT_QUOTES, "UTF-8") . "</b></td><td>" . nl2br(htmlspecialchars($value, ENT_QUOTES, "UTF-8")) . "</td></tr>\n";
        }

        echo "</tbody></table>\n";
    }

    echo "</div>\n";
    echo "<p><a class=\"btn\" href=\"index.php\">Continue to DUP</a> <a class=\"btn\" href=\"setup.php\">Back to Setup</a></p>\n";
    echo "</div></div></div></div></div>\n";
    echo "<FOOTER><DIV class=\"container footer\"><B>dup Setup</B></DIV></FOOTER>\n";
    echo "</BODY></HTML>\n";
    exit;
}

function ValidateSetupEnvironment()
{
    $errors = array();

    if(version_compare(PHP_VERSION, "8.2.0", "<"))
        $errors[] = "PHP 8.2 or higher is required. Current PHP version: " . PHP_VERSION;

    if(!extension_loaded("pdo"))
        $errors[] = "PHP extension PDO is not loaded.";

    if(!extension_loaded("pdo_mysql"))
        $errors[] = "PHP extension pdo_mysql is not loaded. Enable pdo_mysql in Web Station > Script Language Settings > PHP profile used by DUP.";

    if(!is_dir(__DIR__ . "/logs"))
    {
        if(!@mkdir(__DIR__ . "/logs", 0777, true))
            $errors[] = "Could not create logs folder: " . __DIR__ . "/logs";
    }

    if(is_dir(__DIR__ . "/logs") && !is_writable(__DIR__ . "/logs"))
        $errors[] = "Logs folder is not writable: " . __DIR__ . "/logs";

    if(!is_writable(__DIR__ . "/db.php"))
        $errors[] = "db.php is not writable. Setup needs to save database connection settings to: " . __DIR__ . "/db.php";

    if(!is_readable(__DIR__ . "/files/dup.sql"))
        $errors[] = "SQL setup file is missing or not readable: " . __DIR__ . "/files/dup.sql";

    if(count($errors) > 0)
        SetupFail("Setup environment check failed:\n\n" . implode("\n", $errors));
}

function WriteDbConfig($password, $dbDir, $dbFile, $dbHost, $dbPort)
{
    $dbPath = __DIR__ . "/db.php";
    $dbf = file_get_contents($dbPath);

    if($dbf === false)
        SetupFail("Could not read db.php");

    $dbf = preg_replace('/\$dbpass\s*=\s*"[^"]*";/', '$dbpass   = "' . addcslashes($password, "\\\"") . '";', $dbf);
    $dbf = preg_replace('/\$dbdir\s*=\s*"[^"]*";/', '$dbdir    = "' . addcslashes($dbDir, "\\\"") . '";', $dbf);
    $dbf = preg_replace('/\$dbfile\s*=\s*[01];/', '$dbfile   = ' . intval($dbFile) . ';', $dbf);
    $dbf = preg_replace('/\$dbhost\s*=\s*"[^"]*";/', '$dbhost   = "' . addcslashes($dbHost, "\\\"") . '";', $dbf);

    if(strpos($dbf, '$dbport') === false)
        $dbf = preg_replace('/(\$dbhost\s*=\s*"[^"]*";)/', "$1\n\$dbport   = \"" . addcslashes($dbPort, "\\\"") . "\";", $dbf);
    else
        $dbf = preg_replace('/\$dbport\s*=\s*"[^"]*";/', '$dbport   = "' . addcslashes($dbPort, "\\\"") . '";', $dbf);

    if(file_put_contents($dbPath, $dbf) === false)
        SetupFail("Could not write db.php");
}

function SplitSqlStatements($sql)
{
    $statements = array();
    $current = "";
    $len = strlen($sql);
    $inSingle = false;
    $inDouble = false;

    for($i = 0; $i < $len; $i++)
    {
        $ch = $sql[$i];
        $prev = ($i > 0) ? $sql[$i - 1] : "";

        if($ch == "'" && !$inDouble && $prev != "\\")
            $inSingle = !$inSingle;

        if($ch == '"' && !$inSingle && $prev != "\\")
            $inDouble = !$inDouble;

        if($ch == ";" && !$inSingle && !$inDouble)
        {
            $stmt = trim($current);
            if(strlen($stmt) > 0)
                $statements[] = $stmt;

            $current = "";
        }
        else
            $current .= $ch;
    }

    $stmt = trim($current);
    if(strlen($stmt) > 0)
        $statements[] = $stmt;

    return $statements;
}

function RunSqlFile($pdo, $file)
{
    $sql = file_get_contents($file);

    if($sql === false)
        SetupFail("Could not read SQL file: " . $file);

    foreach(SplitSqlStatements($sql) as $statement)
    {
        $statement = trim($statement);

        if($statement == "")
            continue;

        try {
            $pdo->exec($statement);
        }
        catch(PDOException $ex) {
            SetupFail("SQL failed:\n" . $statement, $ex);
        }
    }
}
function ValidateDatabaseImport($pdo)
{
    $requiredTables = array(
        "accounts",
        "files",
        "logs",
        "msg",
        "profiles",
        "profiletypes",
        "settings",
        "status"
    );

    $errors = array();

    foreach($requiredTables as $table)
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) AS c FROM `" . $table . "`;");
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        }
        catch(PDOException $ex) {
            $errors[] = "Missing or unreadable table `" . $table . "`: " . $ex->getMessage();
        }
    }

    $seedChecks = array(
        "accounts" => "SELECT COUNT(*) AS c FROM accounts WHERE name='admin';",
        "settings" => "SELECT COUNT(*) AS c FROM settings;",
        "profiletypes" => "SELECT COUNT(*) AS c FROM profiletypes;"
    );

    foreach($seedChecks as $name => $query)
    {
        try {
            $stmt = $pdo->query($query);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();

            if(!$row || intval($row["c"]) < 1)
                $errors[] = "Required seed data missing from `" . $name . "`.";
        }
        catch(PDOException $ex) {
            $errors[] = "Could not validate seed data for `" . $name . "`: " . $ex->getMessage();
        }
    }

    if(count($errors) > 0)
        SetupFail("Database import validation failed:\n\n" . implode("\n", $errors));
}

function ConnectMariaDb($password, &$dbHost, &$dbPort)
{
    $attempts = array(
        array("127.0.0.1", "3306"),
        array("localhost", "3306"),
        array("127.0.0.1", "3307"),
        array("localhost", "3307")
    );

    $errors = "";

    foreach($attempts as $attempt)
    {
        $host = $attempt[0];
        $port = $attempt[1];

        try {
            $dsn = "mysql:host=" . $host . ";port=" . $port . ";charset=utf8";
            $pdo = new PDO($dsn, "root", $password);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

            $dbHost = $host;
            $dbPort = $port;

            return $pdo;
        }
        catch(PDOException $ex) {
            $msg = $ex->getMessage();

            $errors .= "FAILED: host=" . $host . " port=" . $port . "\n";

            if(stripos($msg, "could not find driver") !== false)
                $errors .= "Reason: PHP pdo_mysql is not enabled in the DUP PHP profile.\n";
            else if(stripos($msg, "Access denied") !== false)
                $errors .= "Reason: MariaDB rejected the root login. Check the MariaDB 10 root password.\n";
            else if(stripos($msg, "Connection refused") !== false)
                $errors .= "Reason: MariaDB is not listening on this host/port.\n";
            else if(stripos($msg, "No such file") !== false)
                $errors .= "Reason: PHP tried a socket path that does not exist.\n";
            else
                $errors .= "Reason: " . $msg . "\n";

            $errors .= "\n";
        }
    }

    SetupFail(
        "Could not connect to MariaDB 10 as root.\n\n" .
        "Check these items:\n" .
        "1. MariaDB 10 is installed and running.\n" .
        "2. pdo_mysql is enabled in the Web Station PHP profile used by DUP.\n" .
        "3. The MariaDB root password is correct.\n" .
        "4. MariaDB 10 is listening on TCP 3306 or 3307.\n\n" .
        $errors
    );
}

ValidateSetupEnvironment();

$password = isset($_POST["password"]) ? $_POST["password"] : "";
$dbDir = isset($_POST["db_dir"]) && strlen($_POST["db_dir"]) > 0 ? $_POST["db_dir"] : __DIR__;
$dbFile = isset($_POST["filebd"]) ? 1 : 0;

if($dbFile == 1)
{
    WriteDbConfig($password, $dbDir, 1, "localhost", "");

    $src = __DIR__ . "/files/dup.db";
    $dst = $dbDir . "/dup.db";

    if(is_file($dst) && !@unlink($dst))
        SetupFail("Could not remove old SQLite DB: " . $dst);

    if(!copy($src, $dst))
        SetupFail("Could not copy SQLite DB from " . $src . " to " . $dst);

    header("Location: index.php");
    exit;
}

$dbHost = "localhost";
$dbPort = "";
$pdo = ConnectMariaDb($password, $dbHost, $dbPort);

try {
    $pdo->exec("DROP DATABASE IF EXISTS dup");
    $pdo->exec("CREATE DATABASE dup CHARACTER SET utf8 COLLATE utf8_unicode_ci");
    $pdo->exec("USE dup");
}
catch(PDOException $ex) {
    SetupFail("Could not reset/create dup database", $ex);
}

RunSqlFile($pdo, __DIR__ . "/files/dup.sql");
ValidateDatabaseImport($pdo);

WriteDbConfig($password, $dbDir, 0, $dbHost, $dbPort);

ShowSetupResultPage(
    "DUP setup completed successfully",
    array(
        "MariaDB database was created/reset.",
        "Required tables were imported and validated.",
        "db.php was updated with the working database host and port.",
        "Default admin account: admin / admin"
    ),
    array(
        "Database host" => $dbHost,
        "Database port" => $dbPort,
        "Required tables" => "OK"
    ),
    true
);
?>