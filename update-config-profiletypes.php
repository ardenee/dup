<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Update Exts '" . (isset($dup->Profile["path"]) ? $dup->Profile["path"] : "") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function CleanExts($exts)
{
    $exts = strtolower((string)$exts);
    $exts = str_replace(array("\r", "\n", " "), "", $exts);

    $parts = explode(",", $exts);
    $clean = array();

    foreach($parts as $part)
    {
        $part = trim($part);
        $part = ltrim($part, ".");

        if($part != "" && preg_match('/^[a-z0-9_+-]+$/', $part) && !in_array($part, $clean))
            $clean[] = $part;
    }

    return implode(",", $clean);
}

function CleanProfileTypeName($name)
{
    $name = trim((string)$name);
    $name = preg_replace('/\s+/', ' ', $name);

    if(strlen($name) > 80)
        $name = substr($name, 0, 80);

    return $name;
}

function SetMessage($success, $stage)
{
    global $dblink;
    global $dbfile;

    $dbName = ($dbfile == 0) ? "MariaDB" : "SQLite";

    if($success)
        $message = array("notification-1" => array("message" => $dbName . " database updated", "type" => "notice", "title" => "Profile Types Saved"));
    else
        $message = array("notification-1" => array("message" => "Error saving profile types during " . $stage . ", check logs!", "type" => "error", "title" => "Settings NOT Saved!"));

    try
    {
        $query = "UPDATE msg SET message=:message;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":message", json_encode($message), PDO::PARAM_STR);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }
}

$profileTypeId = isset($_POST["profiletype"]) ? intval($_POST["profiletype"]) : 0;
$deleteProfileType = isset($_POST["delpt"]);
$newProfileTypeName = isset($_POST["ptname"]) ? CleanProfileTypeName($_POST["ptname"]) : "";
$exts = isset($_POST["exts"]) ? CleanExts($_POST["exts"]) : "";

try
{
    $dblink->beginTransaction();

    if($deleteProfileType && $profileTypeId > 0)
    {
        $query = "DELETE FROM profiletypes WHERE id=:id;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":id", $profileTypeId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }

    if($newProfileTypeName != "")
    {
        if($GLOBALS["dbfile"] == 0)
            $query = "INSERT IGNORE INTO profiletypes (exts, name) VALUES ('', :name);";
        else
            $query = "INSERT OR IGNORE INTO profiletypes (exts, name) VALUES ('', :name);";

        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":name", $newProfileTypeName, PDO::PARAM_STR);
        $sdt->execute();
        $sdt->closeCursor();
    }

    if(!$deleteProfileType && $profileTypeId > 0)
    {
        if($exts == "" && isset($dup->Profile["filetypes"]))
            $exts = CleanExts($dup->Profile["filetypes"]);

        $query = "UPDATE profiletypes SET exts=:exts WHERE id=:id;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":exts", $exts, PDO::PARAM_STR);
        $sdt->bindValue(":id", $profileTypeId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }

    $dblink->commit();
    SetMessage(true, "save");
}
catch(PDOException $ex)
{
    if(isset($dblink) && $dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    SetMessage(false, "database update");
    header("Location: error.php");
    exit;
}

header("Location: config-profiletypes.php");
exit;
?>