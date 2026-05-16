<?php

include("dup.php");

$dup = new dup($profile);

$backupDir = __DIR__ . "/backup";
$statusFile = $backupDir . "/backup.data";

if(!is_dir($backupDir))
    @mkdir($backupDir, 0777, true);

@unlink($statusFile);

StatUpdate("Export Started\n");

try
{
    $Profiles = GetExportProfiles();
    $Settings = GetExportSettings();
    $profilecount = count($Profiles);
    $key = 1;

    foreach($Profiles as $Profile)
    {
        ExportProfileFiles($Profile, $Settings, $key, $profilecount);
        $key++;
    }

    StatUpdate("Export Finished\n");
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    StatUpdate("Export failed: " . $ex->getMessage() . "\n");
    header("Location: error.php");
    exit;
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    StatUpdate("Export failed: database error\n");
    header("Location: error.php");
    exit;
}

header("Location: setup-backup.php");
exit;

//------------------------------------------------------------------------------------

function GetExportProfiles()
{
    global $dblink;

    $value = array();

    $query = "SELECT * FROM profiles ORDER BY id;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->query($query);

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
        $value[] = $row;

    $sdt->closeCursor();

    return $value;
}

//------------------------------------------------------------------------------------

function GetExportSettings()
{
    global $dblink;

    $value = array();

    $query = "SELECT * FROM settings LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->query($query);
    $row = $sdt->fetch(PDO::FETCH_ASSOC);

    if($row)
        $value = $row;

    $sdt->closeCursor();

    if(count($value) == 0)
        throw new Exception("No settings row found for export.");

    return $value;
}

//------------------------------------------------------------------------------------

function ExportProfileFiles($Profile, $Settings, $key, $profilecount)
{
    global $dblink;
    global $backupDir;

    if(!isset($Profile["id"]))
        throw new Exception("Profile missing id during export.");

    $profileId = intval($Profile["id"]);

    if($profileId <= 0)
        throw new Exception("Invalid profile id during export.");

    $query = "SELECT COUNT(id) AS number FROM files WHERE profile=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $ct = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    $total = ($ct && isset($ct["number"])) ? intval($ct["number"]) : 0;

    $query = "SELECT * FROM files WHERE profile=:profile ORDER BY id;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $outputFile = $backupDir . "/" . $profileId . ".txt";
    $tmpFile = $outputFile . ".tmp";

    @unlink($tmpFile);

    $handle = fopen($tmpFile, "w");

    if(!$handle)
        throw new Exception("Could not create backup file: " . $tmpFile);

    fwrite($handle, json_encode($Settings, JSON_UNESCAPED_SLASHES) . "\n");
    fwrite($handle, json_encode($Profile, JSON_UNESCAPED_SLASHES) . "\n");

    $filenum = 1;

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        fwrite($handle, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n");

        if($filenum % 200 == 0)
            StatUpdate("Exporting Profile: " . $key . " of " . $profilecount . " - Record " . number_format($filenum) . " of " . number_format($total) . "\n");

        $filenum++;
    }

    fclose($handle);
    $sdt->closeCursor();

    if(!@rename($tmpFile, $outputFile))
    {
        @unlink($tmpFile);
        throw new Exception("Could not finalize backup file: " . $outputFile);
    }

    StatUpdate("Exporting Profile: " . $key . " of " . $profilecount . " - Complete (" . number_format($filenum - 1) . " records exported)\n\n");
}

//------------------------------------------------------------------------------------

function StatUpdate($line)
{
    global $statusFile;

    $handle = fopen($statusFile, "a+");

    if($handle)
    {
        fwrite($handle, $line);
        fclose($handle);
    }
}

//------------------------------------------------------------------------------------

?>