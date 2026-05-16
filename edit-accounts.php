<?php
include("dup.php");

$dup = new dup($profile);
$currentLevel = isset($level) ? intval($level) : 0;

$aid = isset($_POST["id"]) ? intval($_POST["id"]) : 0;
$uname = isset($_POST["uname"]) ? strtolower(trim($_POST["uname"])) : "";
$pass = isset($_POST["pass"]) ? $_POST["pass"] : "";

LogStr("Edit Account '$aid'", 1, __FILE__, __LINE__, __FUNCTION__);

if($aid <= 0)
{
    LogError("Edit account failed: invalid account id.");
    header("Location: error.php");
    exit;
}

if($aid == 1)
    $uname = "admin";

if($aid == 1 && $currentLevel != 1)
{
    LogError("Only an Admin account can edit the Admin account.");
    header("Location: error.php");
    exit;
}

if($uname == "")
{
    LogError("Edit account failed: missing username.");
    header("Location: accounts.php?error=missing_username");
    exit;
}

if(!preg_match('/^[a-z0-9._-]{3,64}$/', $uname))
{
    LogError("Edit account failed: invalid username: " . $uname);
    header("Location: accounts.php?error=invalid_username");
    exit;
}

try
{
    if($pass !== "")
    {
        $hash = md5("sault**" . $pass);

        $query = "UPDATE accounts SET name=:name, password=:password WHERE id=:id;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":name", $uname, PDO::PARAM_STR);
        $sdt->bindValue(":password", $hash, PDO::PARAM_STR);
        $sdt->bindValue(":id", $aid, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }
    else
    {
        $query = "UPDATE accounts SET name=:name WHERE id=:id;";
        LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":name", $uname, PDO::PARAM_STR);
        $sdt->bindValue(":id", $aid, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }

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