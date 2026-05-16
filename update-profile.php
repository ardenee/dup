<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Save Profile Settings '$profile'", 1, __FILE__, __LINE__, __FUNCTION__);

function CleanProfileFileTypes($value)
{
    $value = strtolower((string)$value);
    $value = str_replace(array("\r", "\n", " "), "", $value);

    $parts = explode(",", $value);
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

$profileId = intval($profile);

$path = isset($_POST["main_dir"]) ? rtrim(trim($_POST["main_dir"]), "/") : "/";
$mpath = isset($_POST["move_dir"]) ? rtrim(trim($_POST["move_dir"]), "/") : "/";
$crc32 = isset($_POST["crc32"]) ? intval($_POST["crc32"]) : 0;
$filetypes = isset($_POST["filetypes"]) ? CleanProfileFileTypes($_POST["filetypes"]) : "";
$msize = isset($_POST["msize"]) ? intval($_POST["msize"]) : 0;
$type = isset($_POST["type"]) ? intval($_POST["type"]) : 1;

if($msize < 0)
    $msize = 0;

if($profileId <= 0)
{
    LogError("Invalid profile id in update-profile.php: " . print_r($_POST, true));
    header("Location: error.php");
    exit;
}

if($path == "")
    $path = "/";

if($mpath == "")
    $mpath = "/";

if(!is_dir($path) || !is_dir($mpath))
{
    header("Location: profile-settings.php?profile=" . $profileId . "&error=invalid_paths");
    exit;
}

try
{
    if(isset($_POST["resetruns"]))
    {
        $query = "UPDATE profiles SET runs=0 WHERE id=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }

    if(isset($_POST["resetscan"]))
    {
        $query = "UPDATE profiles SET scan=0 WHERE id=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "UPDATE status SET data=:data, value=:value WHERE profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":data", "Complete", PDO::PARAM_STR);
        $sdt->bindValue(":value", "Idle", PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        //@unlink($profileId . ".txt");
    }

    $query = "UPDATE profiles SET msize=:msize, path=:path, mpath=:mpath, crc32=:crc32, filetypes=:filetypes, type=:type WHERE id=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":msize", $msize, PDO::PARAM_INT);
    $sdt->bindValue(":path", $path, PDO::PARAM_STR);
    $sdt->bindValue(":mpath", $mpath, PDO::PARAM_STR);
    $sdt->bindValue(":crc32", $crc32, PDO::PARAM_INT);
    $sdt->bindValue(":filetypes", $filetypes, PDO::PARAM_STR);
    $sdt->bindValue(":type", $type, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    header("Location: index.php");
    exit;
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>