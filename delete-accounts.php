<?php
include("dup.php");

$dup = new dup($profile);

$aid = isset($_POST["id"]) ? intval($_POST["id"]) : 0;

LogStr("Delete Account '$aid'", 1, __FILE__, __LINE__, __FUNCTION__);

if($aid <= 0)
{
    LogError("Delete account failed: invalid account id.");
    header("Location: error.php");
    exit;
}

if($aid == 1)
{
    LogError("Delete account blocked: admin account cannot be deleted.");
    header("Location: accounts.php?error=cannot_delete_admin");
    exit;
}

try
{
    $query = "DELETE FROM accounts WHERE id=:id AND id<>1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":id", $aid, PDO::PARAM_INT);
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