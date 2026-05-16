<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Add New Profile", 1, __FILE__, __LINE__, __FUNCTION__);

$path = isset($_POST["main_dir"]) ? rtrim(trim($_POST["main_dir"]), "/") : "";
$mpath = isset($_POST["move_dir"]) ? rtrim(trim($_POST["move_dir"]), "/") : "";
$crc32 = isset($_POST["crc32"]) ? intval($_POST["crc32"]) : 0;
$filetypes = isset($_POST["filetypes"]) ? trim($_POST["filetypes"]) : "";
$msize = isset($_POST["msize"]) ? intval($_POST["msize"]) : 0;
$type = isset($_POST["type"]) ? intval($_POST["type"]) : 1;

if($msize < 0)
    $msize = 0;


if($path == "" || $mpath == "")
{
    header("Location: profile-add.php?error=missing_paths");
    exit;
}

if(!is_dir($path) || !is_dir($mpath))
{
    header("Location: profile-add.php?error=invalid_paths");
    exit;
}

try
{
    $query = "INSERT INTO profiles (msize, path, mpath, crc32, filetypes, scan, type, files, dups, runs) VALUES (:msize, :path, :mpath, :crc32, :filetypes, 0, :type, 0, 0, 0)";
    LogStr("MySQL/SQLite: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);
	$dblink->beginTransaction();

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":msize", $msize, PDO::PARAM_INT);
    $sdt->bindValue(":path", $path, PDO::PARAM_STR);
    $sdt->bindValue(":mpath", $mpath, PDO::PARAM_STR);
    $sdt->bindValue(":crc32", $crc32, PDO::PARAM_INT);
    $sdt->bindValue(":filetypes", $filetypes, PDO::PARAM_STR);
    $sdt->bindValue(":type", $type, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    $id = intval($dblink->lastInsertId());

    if($id <= 0)
        throw new Exception("Profile insert succeeded but lastInsertId() returned no valid id.");

    LogStr("LASTID: " . $id, 3, __FILE__, __LINE__, __FUNCTION__);

    $query = "INSERT INTO status (profile, value, data) VALUES (:profile, :value, :data)";
    LogStr("MySQL: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $id, PDO::PARAM_INT);
    $sdt->bindValue(":value", "Idle", PDO::PARAM_STR);
    $sdt->bindValue(":data", "Complete", PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();

    $query = "INSERT INTO viewpages (data, profile) VALUES (:data, :profile)";
    LogStr("MySQL: " . $query, 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":data", "", PDO::PARAM_STR);
    $sdt->bindValue(":profile", $id, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

	$dblink->commit();
    header("Location: index.php");
    exit;
}
catch(Exception $ex)
{
    if(isset($dblink) && $dblink->inTransaction())
        $dblink->rollBack();

    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>