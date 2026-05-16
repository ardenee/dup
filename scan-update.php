<?php

include("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);

if($profileId <= 0)
{
    LogError("Invalid profile id passed to scan-update.php: " . $profile);
    header("Location: error.php");
    exit;
}

$runtime = isset($dup->Settings["runtime"]) ? intval($dup->Settings["runtime"]) : 10800;

if($runtime < 30)
    $runtime = 10800;

ini_set("max_execution_time", $runtime);
set_time_limit($runtime);

LogStr("Scan Update", 1, __FILE__, __LINE__, __FUNCTION__);

$mfs = ((1024 * 1024) * intval($dup->Profile['msize']));
$crcMode = isset($dup->Profile['crc32']) ? intval($dup->Profile['crc32']) : 0;

try
{
    SetProfileScanFlag(1);

    if($crcMode > 0)
    {
        UpdateStatus("Hashing...", "Looking for files to hash.");

        $max = GetTotalHashCandidates($profileId, $mfs);
        $num = 1;

        $ids = GetHashCandidateIds($profileId, $mfs);

        foreach($ids as $id)
        {
            $file = HashFile($id);

            if($num == 1 || $num % 20 == 0 || $num == $max)
                UpdateStatus("Hashing... (" . number_format($num) . " / " . number_format($max) . ")", $file);

            $num++;

            if(CheckStatus($profileId) == 0)
            {
                LogStr("Scan update stopped by profile scan flag.", 2, __FILE__, __LINE__, __FUNCTION__);
                break;
            }
        }

        $dups = CountDuplicateGroups($profileId, "crc32");
    }
    else
    {
        $dups = CountDuplicateGroups($profileId, "size");
    }

    FinishProfileUpdate($profileId, $dups);

    UpdateStatus("Refreshing", "Refreshing");
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    FailScanUpdate("Database error during scan update.");
    header("Location: error.php");
    exit;
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    FailScanUpdate("Error during scan update.");
    header("Location: error.php");
    exit;
}

exit;

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

function GetTotalHashCandidates($profileId, $mfs)
{
    global $dblink;

    $query = "
        SELECT COUNT(files.id) AS total
        FROM files
        INNER JOIN (
            SELECT size
            FROM files
            WHERE profile=:profile1
              AND (size>=:mfs OR size=17)
            GROUP BY size
            HAVING COUNT(size)>1 OR size=17
        ) AS files2 ON files.size=files2.size
        WHERE files.profile=:profile2;
    ";

    LogStr("MySQL/SQLite: count hash candidates", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile1", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile2", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":mfs", intval($mfs), PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["total"]))
        return intval($row["total"]);

    return 0;
}

//--------------------------------------------------------------------------------------

function GetHashCandidateIds($profileId, $mfs)
{
    global $dblink;

    $ids = array();

    $query = "
        SELECT files.id
        FROM files
        INNER JOIN (
            SELECT size
            FROM files
            WHERE profile=:profile1
              AND (size>=:mfs OR size=17)
            GROUP BY size
            HAVING COUNT(size)>1 OR size=17
        ) AS files2 ON files.size=files2.size
        WHERE files.profile=:profile2
        ORDER BY files.id;
    ";

    LogStr("MySQL/SQLite: get hash candidate ids", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile1", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile2", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":mfs", intval($mfs), PDO::PARAM_INT);
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

function CountDuplicateGroups($profileId, $type)
{
    global $dblink;

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

function FinishProfileUpdate($profileId, $dups)
{
    global $dblink;

    $query = "
        UPDATE profiles
        SET dups=:dups,
            scan=0,
            runs=runs+1,
            files=(SELECT COUNT(id) FROM files WHERE files.profile=:profile_count)
        WHERE id=:profile;
    ";

    LogStr("MySQL/SQLite: finish scan update", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":dups", intval($dups), PDO::PARAM_INT);
    $sdt->bindValue(":profile_count", $profileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();
}

//--------------------------------------------------------------------------------------

function FailScanUpdate($message)
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

function HashFile($id)
{
    global $dup;
    global $dblink;
    global $profileId;

    $id = intval($id);

    if($id <= 0)
        return "";

    $file = GetFile($id);

    if($file == "")
        return "";

    if(intval($dup->Profile['crc32']) == 2)
        $crc32 = $dup->full_file_crc($file);
    else
        $crc32 = $dup->file_crc($file);

    LogStr("Hash result: " . $crc32 . " | file=" . $file, 3, __FILE__, __LINE__, __FUNCTION__);

    $query = "UPDATE files SET crc32=:crc32 WHERE id=:id AND profile=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":crc32", $crc32, PDO::PARAM_STR);
    $sdt->bindValue(":id", $id, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    return $file;
}

//--------------------------------------------------------------------------------------

function GetFile($ID)
{
    global $dblink;
    global $profileId;

    $ID = intval($ID);
    $value = "";

    if($ID <= 0)
        return $value;

    $query = "SELECT file FROM files WHERE id=:id AND profile=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":id", $ID, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);

    if($row && isset($row["file"]))
        $value = $row["file"];

    $sdt->closeCursor();

    return $value;
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