<?php

require_once("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);
$allowedActions = array("Delete", "Rename", "Move", "Link");

$action = "";

if(isset($action) && trim($action) != "")
    $action = trim($action);
else if(isset($_POST["action"]))
    $action = trim($_POST["action"]);

if($profileId <= 0)
{
    LogError("Invalid profile id in action-list-files.php: " . $profile);
    header("Location: error.php");
    exit;
}

if(!in_array($action, $allowedActions))
{
    LogError("Invalid action-list-files action: " . $action);
    header("Location: error.php");
    exit;
}

LogStr($action . "ing Files '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function FindDupPhpCli()
{
    $candidates = array(
        "/usr/local/bin/php82",
        "/usr/local/bin/php8.2",
        "/usr/local/bin/php",
        "/var/packages/PHP8.2/target/usr/local/bin/php82",
        "/var/packages/PHP8.2/target/usr/local/bin/php",
        "/var/packages/PHP8.2/target/bin/php",
        "/usr/bin/php",
        "/bin/php"
    );

    foreach($candidates as $candidate)
    {
        if(is_file($candidate) && is_executable($candidate))
            return $candidate;
    }

    $which = trim((string)shell_exec("command -v php 2>/dev/null"));

    if($which != "" && is_file($which) && is_executable($which))
        return $which;

    return "";
}

function UpdateProfileStatus($value, $data)
{
    global $dblink;
    global $profileId;

    $query = "UPDATE status SET value=:value, data=:data WHERE profile=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":value", $value, PDO::PARAM_STR);
    $sdt->bindValue(":data", $data, PDO::PARAM_STR);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

function QueueActionFile($table, $file, $new)
{
    global $dblink;
    global $profileId;
    global $dbfile;

    if(!in_array($table, array("files_delete", "files_rename", "files_move", "files_link")))
        throw new Exception("Invalid action queue table: " . $table);

    if($dbfile == 0)
        $query = "INSERT IGNORE INTO `" . $table . "` (file, new, profile) VALUES (:file, :new, :profile);";
    else
        $query = "INSERT OR IGNORE INTO `" . $table . "` (file, new, profile) VALUES (:file, :new, :profile);";

    LogStr("MySQL/SQLite: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":file", $file, PDO::PARAM_STR);
    $sdt->bindValue(":new", $new, PDO::PARAM_STR);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

function ValidateQueuedPath($path)
{
    $path = trim((string)$path);

    if($path == "")
        return "";

    $path = str_replace("\\", "/", $path);

    while(strpos($path, "//") !== false)
        $path = str_replace("//", "/", $path);

    return $path;
}

$canStart = false;

if($dbfile == 0)
    $canStart = (intval($dup->Profile['scan']) == 0);
else
    $canStart = (ScanRunning() == false);

if(!$canStart)
{
    if($dbfile == 0)
        LogStr("Action blocked: scan/action already running.", 1, __FILE__, __LINE__, __FUNCTION__);
    else
        LogStr("Action blocked: SQLite can only allow one large update at a time.", 1, __FILE__, __LINE__, __FUNCTION__);

    header("Location: index.php");
    exit;
}

try
{
    if(!isset($_POST["oldname"]) || !is_array($_POST["oldname"]) || count($_POST["oldname"]) == 0)
    {
        LogError("No files were submitted for action: " . $action);
        header("Location: profile-view.php?profile=" . $profileId);
        exit;
    }

    $table = "files_" . strtolower($action);

    if(!in_array($table, array("files_delete", "files_rename", "files_move", "files_link")))
        throw new Exception("Invalid action table: " . $table);

    UpdateProfileStatus("Saving " . $action . " list...", isset($dup->Profile['path']) ? $dup->Profile['path'] : "");

    $dblink->beginTransaction();

    if($action == "Link")
    {
        $oldNames = $_POST["oldname"];

        foreach($oldNames as $key => $links)
        {
            if(!is_array($links))
                continue;

            $source = isset($_POST[$key]) ? ValidateQueuedPath($_POST[$key]) : "";

            foreach($links as $link)
            {
                $link = ValidateQueuedPath($link);

                if($source != "" && $link != "" && $source != $link)
                    QueueActionFile($table, $source, $link);
            }
        }
    }
    else
    {
        $oldNames = $_POST["oldname"];
        $newNames = isset($_POST["newname"]) && is_array($_POST["newname"]) ? $_POST["newname"] : array();

        foreach($oldNames as $key => $oldName)
        {
            $file = ValidateQueuedPath(FilterOff($key));
            $newName = isset($newNames[$key]) ? ValidateQueuedPath($newNames[$key]) : ValidateQueuedPath($oldName);

            if($file != "")
                QueueActionFile($table, $file, $newName);
        }
    }

    $dblink->commit();

    UpdateProfileStatus("Starting " . $action . "ing...", isset($dup->Profile['path']) ? $dup->Profile['path'] : "");

    $phpCli = FindDupPhpCli();

    if($phpCli == "")
        throw new Exception("Could not find PHP CLI binary for action-files-thread.php");

    $script = __DIR__ . "/action-files-thread.php";
    $actionLog = __DIR__ . "/logs/action-profile-" . $profileId . "-" . strtolower($action) . "-" . date("Ymd-His") . ".log";

    $cmd = escapeshellarg($phpCli) . " -q " . escapeshellarg($script) . " " . escapeshellarg((string)$profileId) . " " . escapeshellarg($action) . " >> " . escapeshellarg($actionLog) . " 2>&1 &";

    LogStr("Action command: " . $cmd, 3, __FILE__, __LINE__, __FUNCTION__);

    shell_exec($cmd);
}
catch(Exception $ex)
{
    if(isset($dblink) && $dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    header("Location: error.php");
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

header("Location: index.php");
exit;

?>