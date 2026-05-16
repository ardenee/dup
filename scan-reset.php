<?php

include("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);
$profilePath = isset($dup->Profile["path"]) ? $dup->Profile["path"] : "";

if($profileId <= 0)
{
    LogError("Invalid profile id in scan-reset.php: " . $profile);
    header("Location: error.php");
    exit;
}

LogStr("Reset '" . $profilePath . "'", 1, __FILE__, __LINE__, __FUNCTION__);

try
{
    $dblink->beginTransaction();

    $query = "UPDATE profiles SET scan=0 WHERE id=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    $query = "UPDATE status SET value=:value, data=:data WHERE profile=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":value", "Idle", PDO::PARAM_STR);
    $sdt->bindValue(":data", "Complete", PDO::PARAM_STR);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    $dblink->commit();

    header("Location: index.php");
    exit;
}
catch(PDOException $ex)
{
    if(isset($dblink) && $dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}

?>