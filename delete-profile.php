<?php
include("dup.php");

$dup = new dup($profile);

$profileId = isset($_POST["profile"]) ? intval($_POST["profile"]) : intval($profile);

if($profileId <= 0)
{
    LogError("Invalid profile id in delete-profile.php: " . $profile);
    header("Location: error.php");
    exit;
}

LogStr("Delete Profile '" . (isset($dup->Profile["path"]) ? $dup->Profile["path"] : $profileId) . "'", 1, __FILE__, __LINE__, __FUNCTION__);

try
{
    $dblink->beginTransaction();

    $queries = array(
        "DELETE FROM files WHERE profile=:profile;",
        "DELETE FROM status WHERE profile=:profile;",
        "DELETE FROM viewpages WHERE profile=:profile;",
        "DELETE FROM profiles WHERE id=:profile;"
    );

    foreach($queries as $query)
    {
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }

    $dblink->commit();

    //@unlink($profileId . ".txt");

    header("Location: index.php");
    exit;
}
catch(PDOException $ex)
{
    if($dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
catch(Exception $ex)
{
    if($dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>