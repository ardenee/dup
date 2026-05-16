<?php

include("dup.php");

$dup = new dup($profile);

$backupDir = __DIR__ . "/backup";
$statusFile = $backupDir . "/backup.data";

$sqlIgnore = ($dbfile == 0) ? "INSERT IGNORE" : "INSERT OR IGNORE";

if(!is_dir($backupDir))
    @mkdir($backupDir, 0777, true);

StatUpdate("Import Started\n");

try
{
    $files = glob($backupDir . "/*.txt");

    if(!$files || count($files) == 0)
    {
        StatUpdate("No backup .txt files found.\n");
        StatUpdate("Import Finished\n");
        exit;
    }

    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    foreach($files as $file)
        ImportBackupFile($file);

    StatUpdate("Import Finished\n");
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    StatUpdate("Import failed: " . $ex->getMessage() . "\n");
    exit;
}

//------------------------------------------------------------------------------------

function ImportBackupFile($file)
{
    global $dblink;
    global $sqlIgnore;

    $file = realpath($file);

    if($file === false || !is_file($file) || !is_readable($file))
    {
        StatUpdate("Skipping unreadable backup file.\n");
        return;
    }

    $lineCount = CountLines($file);
    StatUpdate("Importing from file '" . basename($file) . "' with " . number_format($lineCount) . " lines.\n");

    $handle = fopen($file, "r");

    if(!$handle)
        throw new Exception("Could not open backup file: " . basename($file));

    $settingsLine = fgets($handle);
    $profileLine = fgets($handle);

    $settingsData = json_decode($settingsLine, true);
    $profileData = json_decode($profileLine, true);

    if(!is_array($settingsData))
        throw new Exception("Invalid settings JSON in backup file: " . basename($file));

    if(!is_array($profileData))
        throw new Exception("Invalid profile JSON in backup file: " . basename($file));

    $dblink->beginTransaction();

    try
    {
        $profileId = AddProfileFromBackup($profileData);
        AddSettingsFromBackup($settingsData);

        $insertFile = $dblink->prepare("
            " . $sqlIgnore . " INTO files (file, size, fdate, crc32, profile)
            VALUES (:file, :size, :fdate, :crc32, :profile)
        ");

        $fileNum = 1;

        while(($line = fgets($handle)) !== false)
        {
            $value = json_decode($line, true);

            if(!is_array($value) || !isset($value["file"]) || trim($value["file"]) == "")
            {
                $fileNum++;
                continue;
            }

            $insertFile->bindValue(":file", (string)$value["file"], PDO::PARAM_STR);
            $insertFile->bindValue(":size", isset($value["size"]) ? intval($value["size"]) : 0, PDO::PARAM_INT);
            $insertFile->bindValue(":fdate", isset($value["fdate"]) ? intval($value["fdate"]) : 0, PDO::PARAM_INT);
            $insertFile->bindValue(":crc32", isset($value["crc32"]) ? (string)$value["crc32"] : "", PDO::PARAM_STR);
            $insertFile->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $insertFile->execute();

            if($fileNum % 200 == 0)
                StatUpdate("Importing from file '" . basename($file) . "' - Entry " . number_format($fileNum) . " of " . number_format($lineCount) . "\n");

            $fileNum++;
        }

        $insertFile->closeCursor();

        UpdateProfileCounts($profileId);

        $dblink->commit();

        StatUpdate("Importing from file '" . basename($file) . "' - Complete (" . number_format($fileNum - 1) . " records processed)\n\n");
    }
    catch(Exception $ex)
    {
        if($dblink->inTransaction())
            $dblink->rollBack();

        fclose($handle);
        throw $ex;
    }
    catch(PDOException $ex)
    {
        if($dblink->inTransaction())
            $dblink->rollBack();

        fclose($handle);
        throw $ex;
    }

    fclose($handle);
}

//------------------------------------------------------------------------------------

function AddProfileFromBackup($profileData)
{
    global $dblink;
    global $sqlIgnore;

    $query = "
        " . $sqlIgnore . " INTO profiles
        (msize, path, mpath, crc32, filetypes, scan, type, files, dups, runs, lastrun)
        VALUES
        (:msize, :path, :mpath, :crc32, :filetypes, 0, :type, :files, :dups, :runs, :lastrun)
    ";

    LogStr("MySQL/SQLite: import profile", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":msize", isset($profileData["msize"]) ? intval($profileData["msize"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":path", isset($profileData["path"]) ? (string)$profileData["path"] : "/", PDO::PARAM_STR);
    $sdt->bindValue(":mpath", isset($profileData["mpath"]) ? (string)$profileData["mpath"] : "/", PDO::PARAM_STR);
    $sdt->bindValue(":crc32", isset($profileData["crc32"]) ? intval($profileData["crc32"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":filetypes", isset($profileData["filetypes"]) ? (string)$profileData["filetypes"] : "", PDO::PARAM_STR);
    $sdt->bindValue(":type", isset($profileData["type"]) ? intval($profileData["type"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":files", isset($profileData["files"]) ? intval($profileData["files"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":dups", isset($profileData["dups"]) ? intval($profileData["dups"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":runs", isset($profileData["runs"]) ? intval($profileData["runs"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":lastrun", isset($profileData["lastrun"]) ? (string)$profileData["lastrun"] : date("Y-m-d H:i:s"), PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();

    $profileId = intval($dblink->lastInsertId());

    if($profileId <= 0)
        $profileId = LastProfileIDByPath(isset($profileData["path"]) ? (string)$profileData["path"] : "/");

    if($profileId <= 0)
        throw new Exception("Could not determine imported profile id.");

    EnsureProfileSupportRows($profileId);

    return $profileId;
}

//------------------------------------------------------------------------------------

function EnsureProfileSupportRows($profileId)
{
    global $dblink;
    global $sqlIgnore;

    $query = $sqlIgnore . " INTO status (profile, value, data) VALUES (:profile, :value, :data);";
    LogStr("MySQL/SQLite: import status", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":value", "Idle", PDO::PARAM_STR);
    $sdt->bindValue(":data", "Complete", PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();

    $query = $sqlIgnore . " INTO viewpages (data, profile) VALUES (:data, :profile);";
    LogStr("MySQL/SQLite: import viewpage", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":data", "", PDO::PARAM_STR);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//------------------------------------------------------------------------------------

function AddSettingsFromBackup($settingsData)
{
    global $dblink;

    $query = "
        UPDATE settings
        SET renametype=:renametype,
            namecleanup=:namecleanup,
            cleanupfilter=:cleanupfilter,
            dot=:dot,
            under=:under,
            doubleb=:doubleb,
            flatfolders=:flatfolders,
            logs=:logs,
            displaymoved=:displaymoved,
            cpfolder=:cpfolder,
            shortfilenames=:shortfilenames,
            runtime=:runtime,
            ifolders=:ifolders,
            download=:download
    ";

    LogStr("MySQL/SQLite: import settings", 3, __FILE__, __LINE__, __FUNCTION__);

    $runtime = isset($settingsData["runtime"]) ? intval($settingsData["runtime"]) : 10800;

    if($runtime < 30)
        $runtime = 10800;

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":renametype", isset($settingsData["renametype"]) ? intval($settingsData["renametype"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":namecleanup", isset($settingsData["namecleanup"]) ? intval($settingsData["namecleanup"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":cleanupfilter", isset($settingsData["cleanupfilter"]) ? (string)$settingsData["cleanupfilter"] : "", PDO::PARAM_STR);
    $sdt->bindValue(":dot", isset($settingsData["dot"]) ? intval($settingsData["dot"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":under", isset($settingsData["under"]) ? intval($settingsData["under"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":doubleb", isset($settingsData["doubleb"]) ? intval($settingsData["doubleb"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":flatfolders", isset($settingsData["flatfolders"]) ? intval($settingsData["flatfolders"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":logs", isset($settingsData["logs"]) ? intval($settingsData["logs"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":displaymoved", isset($settingsData["displaymoved"]) ? intval($settingsData["displaymoved"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":cpfolder", isset($settingsData["cpfolder"]) ? intval($settingsData["cpfolder"]) : 1, PDO::PARAM_INT);
    $sdt->bindValue(":shortfilenames", isset($settingsData["shortfilenames"]) ? intval($settingsData["shortfilenames"]) : 0, PDO::PARAM_INT);
    $sdt->bindValue(":runtime", $runtime, PDO::PARAM_INT);
    $sdt->bindValue(":ifolders", isset($settingsData["ifolders"]) ? (string)$settingsData["ifolders"] : "", PDO::PARAM_STR);
    $sdt->bindValue(":download", isset($settingsData["download"]) ? intval($settingsData["download"]) : 0, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//------------------------------------------------------------------------------------

function LastProfileIDByPath($path)
{
    global $dblink;

    $query = "SELECT id FROM profiles WHERE path=:path ORDER BY id DESC LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":path", $path, PDO::PARAM_STR);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["id"]))
        return intval($row["id"]);

    return 0;
}

//------------------------------------------------------------------------------------

function UpdateProfileCounts($profileId)
{
    global $dblink;

    $query = "UPDATE profiles SET files=(SELECT COUNT(id) FROM files WHERE profile=:profile_count) WHERE id=:profile;";
    LogStr("MySQL/SQLite: import update profile counts", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile_count", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//------------------------------------------------------------------------------------

function CountLines($file)
{
    $count = 0;
    $handle = fopen($file, "r");

    if(!$handle)
        return 0;

    while(!feof($handle))
    {
        fgets($handle);
        $count++;
    }

    fclose($handle);

    return $count;
}

//------------------------------------------------------------------------------------

function StatUpdate($line)
{
    global $statusFile;

    $handle = fopen($statusFile, "a+");

    if($handle)
    {
        fputs($handle, $line);
        fclose($handle);
    }
}

//------------------------------------------------------------------------------------

?>