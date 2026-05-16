<?php
include("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);
//$scanLog = __DIR__ . "/logs/scan-profile-" . $profileId . ".log";
$scanLog = __DIR__ . "/logs/scan-profile-" . $profileId . "-" . date("Ymd-His") . ".log";

LogStr("Files Scan '" . (isset($dup->Profile["path"]) ? $dup->Profile["path"] : "") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function FindPhpCli()
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

if($profileId <= 0)
{
    LogError("Invalid profile id passed to scan-files.php: " . $profile);
    header("Location: error.php");
    exit;
}

if(!isset($dup->Profile["id"]) || intval($dup->Profile["id"]) <= 0)
{
    LogError("Profile could not be loaded for scan. profile=" . $profileId);
    header("Location: error.php");
    exit;
}

$canStart = false;

if($dbfile == 0)
    $canStart = (intval($dup->Profile["scan"]) == 0);
else
    $canStart = (ScanRunning() == false);

if($canStart)
{
    try
    {
        $query = "UPDATE status SET value=:value, data=:data WHERE profile=:profile;";
        LogStr("MySQL/SQLite: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":value", "Starting Scan...", PDO::PARAM_STR);
        $sdt->bindValue(":data", $dup->Profile["path"], PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "UPDATE profiles SET scan=1 WHERE id=:profile;";
        LogStr("MySQL/SQLite: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    $phpCli = FindPhpCli();

    if($phpCli == "")
    {
        LogError("Could not find a usable PHP CLI binary for scan-files-thread.php");
        header("Location: error.php");
        exit;
    }

    $script = __DIR__ . "/scan-files-thread.php";

    if(!is_file($script))
    {
        LogError("Scanner script missing: " . $script);
        header("Location: error.php");
        exit;
    }

    $cmd = escapeshellarg($phpCli) . " -q " . escapeshellarg($script) . " " . escapeshellarg((string)$profileId) . " >> " . escapeshellarg($scanLog) . " 2>&1 &";

    LogStr("Scan command: " . $cmd, 3, __FILE__, __LINE__, __FUNCTION__);

    shell_exec($cmd);
}
else
{
    if($dbfile == 0)
        LogStr("Scan already running; will not re-run.", 4, __FILE__, __LINE__, __FUNCTION__);
    else
        LogStr("SQLite can only run 1 scan at a time.", 4, __FILE__, __LINE__, __FUNCTION__);
}

header("Location: index.php");
exit;
?>