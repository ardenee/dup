<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Add Account", 1, __FILE__, __LINE__, __FUNCTION__);

$uname = isset($_POST["uname"]) ? strtolower(trim($_POST["uname"])) : "";
$pass = isset($_POST["pass"]) ? $_POST["pass"] : "";

if($uname == "" || $pass == "")
{
    LogError("Add account failed: missing username or password.");
    header("Location: accounts.php?error=missing_fields");
    exit;
}

if(!preg_match('/^[a-z0-9._-]{3,64}$/', $uname))
{
    LogError("Add account failed: invalid username: " . $uname);
    header("Location: accounts.php?error=invalid_username");
    exit;
}

try
{
    $hash = md5("sault**" . $pass);

    $query = "INSERT INTO accounts (name, level, password) VALUES (:name, 1, :password)";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":name", $uname, PDO::PARAM_STR);
    $sdt->bindValue(":password", $hash, PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();

    header("Location: accounts.php");
    exit;
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>