<?php

if(PHP_SAPI == "cli")
{
    $cliProfile = isset($argv[1]) ? intval($argv[1]) : 0;

    if($cliProfile > 0)
    {
        $_GET["profile"] = $cliProfile;
        $_REQUEST["profile"] = $cliProfile;
    }
}

include("dup.php");

$profileId = intval($profile);

if($profileId <= 0)
{
    LogError("Invalid profile id in scan-files-thread.php: " . $profile);
    exit;
}

$dup = new dup($profileId);

$runtime = isset($dup->Settings["runtime"]) ? intval($dup->Settings["runtime"]) : 10800;

if($runtime < 30)
    $runtime = 10800;

ini_set("max_execution_time", $runtime);
set_time_limit($runtime);

LogStr("Scan Files", 1, __FILE__, __LINE__, __FUNCTION__);

$scanRoot = isset($dup->Profile["path"]) ? rtrim($dup->Profile["path"], "/") : "";
$minFileSize = ((1024 * 1024) * intval($dup->Profile["msize"]));
$fileTypes = BuildFileTypeList(isset($dup->Profile["filetypes"]) ? $dup->Profile["filetypes"] : "");
$skipFolders = BuildSkipFolderList(isset($dup->Settings["ifolders"]) ? $dup->Settings["ifolders"] : "");
$crcMode = isset($dup->Profile["crc32"]) ? intval($dup->Profile["crc32"]) : 0;

if($scanRoot == "" || !is_dir($scanRoot))
{
    LogError("Scan root is not a readable directory: " . $scanRoot);
    FailScan("Scan path is not a readable directory.");
    exit;
}

try
{
    SetProfileScanFlag(1);
    UpdateStatus("Scanning Files", "Scanning " . $scanRoot);

    ClearProfileFiles();

    $scanResult = ScanFolderToDatabase($scanRoot, $minFileSize, $fileTypes, $skipFolders);

    LogStr("Scan insert complete: scanned=" . $scanResult["scanned"] . " inserted=" . $scanResult["inserted"] . " skipped=" . $scanResult["skipped"] . " errors=" . $scanResult["errors"], 1, __FILE__, __LINE__, __FUNCTION__);

    if(CheckStatus($profileId) != 0 && $crcMode > 0)
        $dups = HashDuplicateCandidates($minFileSize);
    else
        $dups = CountDuplicateGroups(($crcMode > 0) ? "crc32" : "size");

    FinishScan($dups);
    UpdateStatus("Idle", "Complete");
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    FailScan("Database error during scan.");
    exit;
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    FailScan("Error during scan.");
    exit;
}

exit;

//--------------------------------------------------------------------------------------

function BuildFileTypeList($fileTypes)
{
    $value = array();
    $parts = explode(",", strtolower((string)$fileTypes));

    foreach($parts as $part)
    {
        $part = trim($part);
        $part = ltrim($part, ".");

        if($part != "" && !in_array($part, $value))
            $value[] = $part;
    }

    return $value;
}

//--------------------------------------------------------------------------------------

function BuildSkipFolderList($folders)
{
    $value = array();
    $parts = explode(",", (string)$folders);

    foreach($parts as $part)
    {
        $part = trim($part);
        $part = trim($part, "/\\");
        $part = strtolower($part);

        if($part != "" && !in_array($part, $value))
            $value[] = $part;
    }

    return $value;
}

//--------------------------------------------------------------------------------------

function SetProfileScanFlag($scan)
{
    global $dblink;
    global $profileId;

    $query = "UPDATE profiles SET scan=:scan WHERE id=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":scan", intval($scan), PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//--------------------------------------------------------------------------------------

function UpdateStatus($value, $data)
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

//--------------------------------------------------------------------------------------

function ClearProfileFiles()
{
    global $dblink;
    global $profileId;

    $query = "DELETE FROM files WHERE profile=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//--------------------------------------------------------------------------------------

function BuildFindCommand($scanRoot, $skipFolders)
{
    $findBin = "/usr/bin/find";

    if(!is_file($findBin) || !is_executable($findBin))
        $findBin = "find";

    $cmd = escapeshellcmd($findBin) . " " . escapeshellarg($scanRoot) . " ";
    $prunes = array();

    foreach($skipFolders as $skip)
    {
        $skip = trim((string)$skip);

        if($skip != "")
            $prunes[] = "-iname " . escapeshellarg($skip);
    }

    if(count($prunes) > 0)
        $cmd .= "\\( " . implode(" -o ", $prunes) . " \\) -prune -o ";

    $cmd .= "\\( -type f -o -type l \\) -print 2>/dev/null";

    return $cmd;
}

//--------------------------------------------------------------------------------------

function ScanFolderToDatabase($scanRoot, $minFileSize, $fileTypes, $skipFolders)
{
    global $dblink;
    global $profileId;
    global $sqliteOr;

    $result = array(
        "scanned" => 0,
        "inserted" => 0,
        "skipped" => 0,
        "errors" => 0
    );

    $insertSql = "INSERT " . $sqliteOr . " IGNORE INTO files (file, size, fdate, crc32, profile, link) VALUES (:file, :size, :fdate, :crc32, :profile, :link);";
    LogStr("MySQL/SQLite: scan insert prepared", 3, __FILE__, __LINE__, __FUNCTION__);

    $insert = $dblink->prepare($insertSql);

    $cmd = BuildFindCommand($scanRoot, $skipFolders);
    LogStr("Scan find command: " . $cmd, 3, __FILE__, __LINE__, __FUNCTION__);

    UpdateStatus("Finding files...", "Starting folder scan: " . $scanRoot);

    $handle = popen($cmd, "r");

    if(!$handle)
        throw new Exception("Could not start find command for scan.");

    $dblink->beginTransaction();

    $lastStatus = 0;
    $lastCommit = 0;

    while(($path = fgets($handle)) !== false)
    {
        $path = rtrim($path, "\r\n");

        if($path == "")
            continue;

        $result["scanned"]++;

        if(ShouldSkipPath($path, $skipFolders))
        {
            $result["skipped"]++;
            continue;
        }

        if(is_link($path))
        {
            if(ProcessFilePath($insert, $path, $minFileSize, $fileTypes, true, $skipFolders))
                $result["inserted"]++;
            else
                $result["skipped"]++;
        }
        else if(is_file($path))
        {
            if(ProcessFilePath($insert, $path, $minFileSize, $fileTypes, false, $skipFolders))
                $result["inserted"]++;
            else
                $result["skipped"]++;
        }
        else
        {
            $result["skipped"]++;
        }

        if($result["scanned"] - $lastStatus >= 250)
        {
            $lastStatus = $result["scanned"];

            UpdateStatus(
                "Finding files... " . number_format($result["inserted"]) . " found",
                "Checked " . number_format($result["scanned"]) . " entries. Current: " . $path
            );

            if(CheckStatus($profileId) == 0)
            {
                LogStr("Scan stopped by profile scan flag.", 1, __FILE__, __LINE__, __FUNCTION__);
                break;
            }
        }

        if($result["inserted"] - $lastCommit >= 1000)
        {
            $lastCommit = $result["inserted"];
            $dblink->commit();
            $dblink->beginTransaction();

            UpdateStatus(
                "Saving scan list... " . number_format($result["inserted"]) . " files",
                "Checked " . number_format($result["scanned"]) . " entries."
            );
        }
    }

    pclose($handle);

    if($dblink->inTransaction())
        $dblink->commit();

    $insert->closeCursor();

    UpdateStatus(
        "Scan list complete",
        "Checked " . number_format($result["scanned"]) . " entries, found " . number_format($result["inserted"]) . " files."
    );

    return $result;
}

//--------------------------------------------------------------------------------------

function ProcessFilePath($insert, $path, $minFileSize, $fileTypes, $isLink, $skipFolders)
{
    global $profileId;

    if(ShouldSkipPath($path, $skipFolders))
        return false;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    if(count($fileTypes) > 0 && !in_array($ext, $fileTypes))
        return false;

    $size = @filesize($path);

    if($size === false)
        $size = 0;

    $size = intval($size);

    if($size <= 0 || $size < $minFileSize)
        return false;

    $fdate = @filemtime($path);

    if($fdate === false)
        $fdate = 0;

    $link = "";

    if($isLink)
        $link = ResolveSymlinkTarget($path);

    $insert->bindValue(":file", $path, PDO::PARAM_STR);
    $insert->bindValue(":size", $size, PDO::PARAM_INT);
    $insert->bindValue(":fdate", intval($fdate), PDO::PARAM_INT);
    $insert->bindValue(":crc32", "unknown", PDO::PARAM_STR);
    $insert->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $insert->bindValue(":link", $link, PDO::PARAM_STR);
    $insert->execute();

    return true;
}

//--------------------------------------------------------------------------------------

function ResolveSymlinkTarget($path)
{
    $target = @readlink($path);

    if($target === false || $target == "")
        return "";

    if(substr($target, 0, 1) == "/")
        return $target;

    return dirname($path) . "/" . $target;
}

//--------------------------------------------------------------------------------------

function ShouldSkipPath($path, $skipFolders)
{
    if(!is_array($skipFolders) || count($skipFolders) == 0)
        return false;

    $path = str_replace("\\", "/", (string)$path);
    $parts = explode("/", trim($path, "/"));

    foreach($parts as $part)
    {
        $part = strtolower(trim($part));

        foreach($skipFolders as $skip)
        {
            $skip = strtolower(trim((string)$skip));

            if($skip != "" && $part == $skip)
                return true;
        }
    }

    return false;
}

//--------------------------------------------------------------------------------------

function ShouldSkipFolder($folder, $skipFolders)
{
    return ShouldSkipPath($folder, $skipFolders);
}

//--------------------------------------------------------------------------------------

function HashDuplicateCandidates($minFileSize)
{
    global $dup;
    global $dblink;
    global $profileId;

    UpdateStatus("Hashing...", "Looking for files to hash.");

    $ids = GetHashCandidateIds($minFileSize);
    $max = count($ids);
    $num = 1;

    foreach($ids as $id)
    {
        $file = GetFileById($id);

        if($file != "")
        {
            if(intval($dup->Profile["crc32"]) == 2)
                $hash = $dup->full_file_crc($file);
            else
                $hash = $dup->file_crc($file);

            $query = "UPDATE files SET crc32=:crc32 WHERE id=:id AND profile=:profile;";
            LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":crc32", $hash, PDO::PARAM_STR);
            $sdt->bindValue(":id", $id, PDO::PARAM_INT);
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->execute();
            $sdt->closeCursor();
        }

        if($num == 1 || $num % 20 == 0 || $num == $max)
        {
            UpdateStatus("Hashing... (" . number_format($num) . " / " . number_format($max) . ")", $file);

            if(CheckStatus($profileId) == 0)
                break;
        }

        $num++;
    }

    UpdateSymlinkHashes();

    return CountDuplicateGroups("crc32");
}

//--------------------------------------------------------------------------------------

function GetHashCandidateIds($minFileSize)
{
    global $dblink;
    global $profileId;

    $ids = array();

    $query = "
        SELECT files.id
        FROM files
        INNER JOIN (
            SELECT size
            FROM files
            WHERE profile=:profile1
              AND size>=:mfs
            GROUP BY size
            HAVING COUNT(size)>1
        ) AS files2 ON files.size=files2.size
        WHERE files.profile=:profile2
        ORDER BY files.id;
    ";

    LogStr("MySQL/SQLite: get hash candidate ids", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile1", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile2", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":mfs", intval($minFileSize), PDO::PARAM_INT);
    $sdt->execute();

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        if(isset($row["id"]))
            $ids[] = intval($row["id"]);
    }

    $sdt->closeCursor();

    return $ids;
}

//--------------------------------------------------------------------------------------

function UpdateSymlinkHashes()
{
    global $dblink;
    global $profileId;

    $query = "SELECT id, file, link FROM files WHERE link!='' AND profile=:profile ORDER BY id;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $links = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    $max = count($links);
    $num = 1;

    foreach($links as $row)
    {
        $link = isset($row["link"]) ? $row["link"] : "";
        $id = isset($row["id"]) ? intval($row["id"]) : 0;

        if($id <= 0 || $link == "")
            continue;

        $hash = GetHashForPathOrLinkTarget($link);

        if($hash != "")
        {
            $query = "UPDATE files SET crc32=:crc32 WHERE id=:id AND profile=:profile;";
            LogStr("MySQL/SQLite: update symlink hash", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt2 = $dblink->prepare($query);
            $sdt2->bindValue(":crc32", $hash, PDO::PARAM_STR);
            $sdt2->bindValue(":id", $id, PDO::PARAM_INT);
            $sdt2->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt2->execute();
            $sdt2->closeCursor();
        }

        if($num == 1 || $num % 20 == 0 || $num == $max)
            UpdateStatus("Looking up Symlinks... (" . number_format($num) . "/" . number_format($max) . ")", $link);

        $num++;
    }
}

//--------------------------------------------------------------------------------------

function GetHashForPathOrLinkTarget($path)
{
    global $dblink;
    global $profileId;
    global $dup;

    $query = "SELECT crc32, file FROM files WHERE file=:file AND profile=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: lookup symlink target hash", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":file", $path, PDO::PARAM_STR);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["crc32"]) && $row["crc32"] != "" && $row["crc32"] != "unknown")
        return $row["crc32"];

    if(is_file($path))
    {
        if(intval($dup->Profile["crc32"]) == 2)
            return $dup->full_file_crc($path);

        return $dup->file_crc($path);
    }

    return "";
}

//--------------------------------------------------------------------------------------

function GetFileById($id)
{
    global $dblink;
    global $profileId;

    $id = intval($id);

    if($id <= 0)
        return "";

    $query = "SELECT file FROM files WHERE id=:id AND profile=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":id", $id, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["file"]))
        return $row["file"];

    return "";
}

//--------------------------------------------------------------------------------------

function CountDuplicateGroups($type)
{
    global $dblink;
    global $profileId;

    $type = ($type == "crc32") ? "crc32" : "size";

    if($type == "crc32")
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM (
                SELECT crc32
                FROM files
                WHERE profile=:profile
                  AND crc32!='unknown'
                  AND crc32!=''
                GROUP BY crc32
                HAVING COUNT(crc32)>1
            ) AS dupgroups;
        ";
    }
    else
    {
        $query = "
            SELECT COUNT(*) AS total
            FROM (
                SELECT size
                FROM files
                WHERE profile=:profile
                GROUP BY size
                HAVING COUNT(size)>1
            ) AS dupgroups;
        ";
    }

    LogStr("MySQL/SQLite: count duplicate groups by " . $type, 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["total"]))
        return intval($row["total"]);

    return 0;
}

//--------------------------------------------------------------------------------------

function FinishScan($dups)
{
    global $dblink;
    global $profileId;

    $query = "
        UPDATE profiles
        SET dups=:dups,
            scan=0,
            runs=runs+1,
            files=(SELECT COUNT(id) FROM files WHERE files.profile=:profile_count)
        WHERE id=:profile;
    ";

    LogStr("MySQL/SQLite: finish scan", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":dups", intval($dups), PDO::PARAM_INT);
    $sdt->bindValue(":profile_count", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//--------------------------------------------------------------------------------------

function FailScan($message)
{
    try
    {
        SetProfileScanFlag(0);
        UpdateStatus("Error", $message);
    }
    catch(Exception $ex)
    {
        LogError(print_r($ex, true));
    }
}

//--------------------------------------------------------------------------------------

function CheckStatus($pro)
{
    global $dblink;

    $pro = intval($pro);

    if($pro <= 0)
        return 0;

    $query = "SELECT scan FROM profiles WHERE id=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $pro, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["scan"]))
        return intval($row["scan"]);

    return 0;
}

//--------------------------------------------------------------------------------------

?>