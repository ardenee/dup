<?php

if(PHP_SAPI == "cli")
{
    $cliProfile = isset($argv[1]) ? intval($argv[1]) : 0;
    $cliAction = isset($argv[2]) ? trim($argv[2]) : "";

    if($cliProfile > 0)
    {
        $_GET["profile"] = $cliProfile;
        $_REQUEST["profile"] = $cliProfile;
    }

    if($cliAction != "")
    {
        $_GET["action"] = $cliAction;
        $_REQUEST["action"] = $cliAction;
    }
}

include("dup.php");

$profileId = intval($profile);
$allowedActions = array("Rename", "Delete", "Move", "Link");

if($profileId <= 0)
{
    LogError("Invalid profile id in action-files-thread.php: " . $profile);
    exit;
}

if(!in_array($action, $allowedActions))
{
    LogError("Invalid action in action-files-thread.php: " . $action);
    exit;
}

$dup = new dup($profileId);

$profilePath = isset($dup->Profile["path"]) ? $dup->Profile["path"] : "";

LogStr("Action " . $action . " '" . $profilePath . "'", 1, __FILE__, __LINE__, __FUNCTION__);


if(intval($dup->Profile['scan']) == 1)
{
    LogStr("action-files-thread: Profile scan/action already running. Exiting.", 2, __FILE__, __LINE__, __FUNCTION__);
    exit;
}

$max = 0;
$num = 1;
$FilesToAction = array();

$dup->Profile['scan'] = 1;

try
{
    $query = "UPDATE profiles SET scan=:scan WHERE id=:profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":scan", intval($dup->Profile['scan']), PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $sdt->closeCursor();

    $table = "files_" . strtolower($action);

    if(!in_array($table, array("files_delete", "files_rename", "files_move", "files_link")))
        throw new Exception("Invalid action table: " . $table);

    $query = "SELECT file, new, profile FROM `" . $table . "` WHERE profile=:profile;";
    LogStr("MySQL/SQLite: $query", 2, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $FilesToAction = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    $max = count($FilesToAction);
}
catch(Exception $ex)
{
    LogError(print_r($ex, true));
    ResetProfileStatus();
    exit;
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    ResetProfileStatus();
    exit;
}
	
$successCount = 0;
$failCount = 0;
	
// each block should control their own errors, taken out of above try catch to continue if one file fails.	
foreach($FilesToAction as $key => $row)
{			
	$result = false;

	switch($action)
	{
		case "Rename":
			$result = Rename_File($row['file'], $row['new'], $dup->Settings['renametype']);
			break;

		case "Delete":
			$result = Delete_File($row['file']);
			break;

		case "Move":
			$result = Move_File($row['file'], $row['new'], $dup->Settings['renametype']);
			break;

		case "Link":
			$result = Link_File($row['file'], $row['new']);
			break;

		default:
			$result = false;
			break;
	}

	if($result)
		$successCount++;
	else
		$failCount++;
		
	if($num % 20 == 0)
	{
		try
		{
			$query = "UPDATE status SET value=:value, data=:data WHERE profile=:profile;";
			LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

			$statusValue = $action . "ing... (" . number_format($num) . "/" . number_format($max) . ")";
			$statusData = isset($row["file"]) ? $row["file"] : "";

			$sdt = $dblink->prepare($query);
			$sdt->bindValue(":value", $statusValue, PDO::PARAM_STR);
			$sdt->bindValue(":data", $statusData, PDO::PARAM_STR);
			$sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
			$sdt->execute();
			$sdt->closeCursor();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}
	}		

	$num++;
}

//----------------------------------------------------------------------------	
LogStr("Action completed: action=" . $action . " success=" . $successCount . " failed=" . $failCount . " total=" . $max, 1, __FILE__, __LINE__, __FUNCTION__);
ResetProfileStatus();
//----------------------------------------------------------------------------
function ResetProfileStatus()
{
    global $profileId;
    global $dup;
    global $dblink;
    global $action;
    global $dbfile;

    try
    {
        $table = "files_" . strtolower($action);

        if(!in_array($table, array("files_delete", "files_rename", "files_move", "files_link")))
            throw new Exception("Invalid action table in ResetProfileStatus: " . $table);

        $dup->Profile['scan'] = 0;

        $query = "UPDATE profiles SET scan=:scan WHERE id=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":scan", 0, PDO::PARAM_INT);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "DELETE FROM `" . $table . "` WHERE profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $type = (intval($dup->Profile['crc32']) == 0) ? "size" : "crc32";
        $dups = intval(GetTotalDupsGroupsByType($profileId, $type, 0));

        $query = "UPDATE profiles SET files=(SELECT COUNT(id) FROM files WHERE files.profile=:profile_count), dups=:dups WHERE id=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile_count", $profileId, PDO::PARAM_INT);
        $sdt->bindValue(":dups", $dups, PDO::PARAM_INT);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "UPDATE status SET value=:value, data=:data WHERE profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":value", "Refreshing", PDO::PARAM_STR);
        $sdt->bindValue(":data", "Refreshing", PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        if($dbfile == 0)
        {
            try
            {
                $dblink->exec("OPTIMIZE TABLE `" . $table . "`;");
            }
            catch(PDOException $ex) {
                LogError(print_r($ex, true));
            }
        }
    }
    catch(Exception $ex)
    {
        LogError(print_r($ex, true));
    }
    catch(PDOException $ex)
    {
        LogError(print_r($ex, true));
    }
}
//----------------------------------------------------------------------------
function Rename_File($old, $new, $type)
{
    global $profileId;
    global $dblink;

    LogStr("Rename_File() renames file using PHP rename()", 2, __FILE__, __LINE__, __FUNCTION__);

    $old = trim($old);
    $new = trim($new);
    $type = intval($type);

    if($old == "" || $new == "")
    {
        LogError("Rename_File failed: empty source or target. old=" . $old . " new=" . $new);
        return false;
    }

    if(!is_file($old))
    {
        LogError("Rename_File failed: source file does not exist: " . $old);
        return false;
    }

    $finfo = pathinfo($old);
    $target = $finfo["dirname"] . "/" . basename($new);

    if($type == 0 && file_exists($target))
    {
        LogStr("Rename failed: target already exists: " . $target, 2, __FILE__, __LINE__, __FUNCTION__);
        return false;
    }

    if($type == 1 && file_exists($target))
        $target = GetNewName($target);

    if($type == 2 && file_exists($target))
    {
        if(NewDelete($target))
        {
            try
            {
                $query = "DELETE FROM files WHERE file=:file AND profile=:profile;";
                LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

                $sdt = $dblink->prepare($query);
                $sdt->bindValue(":file", $target, PDO::PARAM_STR);
                $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
                $sdt->execute();
                $sdt->closeCursor();
            }
            catch(PDOException $ex) {
                LogError(print_r($ex, true));
                return false;
            }
        }
        else
        {
            LogError("Rename_File failed: could not delete existing target: " . $target);
            return false;
        }
    }

    if(!@rename($old, $target))
    {
        LogError("Rename_File failed: rename() failed from " . $old . " to " . $target);
        return false;
    }

    try
    {
        $query = "UPDATE files SET file=:newfile WHERE file=:oldfile AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":newfile", $target, PDO::PARAM_STR);
        $sdt->bindValue(":oldfile", $old, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "DELETE FROM files_rename WHERE file=:file AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":file", $old, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        return false;
    }

    LogStr("Rename succeeded: " . $old . " -> " . $target, 2, __FILE__, __LINE__, __FUNCTION__);

    return true;
}
//----------------------------------------------------------------------------
function GetNewName($name)
{
    LogStr("GetNewName() Gets available filename", 2, __FILE__, __LINE__, __FUNCTION__);

    $num = 1;
    $nameParts = pathinfo($name);

    $dir = isset($nameParts["dirname"]) ? $nameParts["dirname"] : ".";
    $filename = isset($nameParts["filename"]) ? $nameParts["filename"] : basename($name);
    $extension = isset($nameParts["extension"]) && $nameParts["extension"] != "" ? "." . $nameParts["extension"] : "";

    $candidate = $dir . "/" . $filename . " (" . $num . ")" . $extension;

    while(file_exists($candidate) || is_link($candidate))
    {
        $num++;
        $candidate = $dir . "/" . $filename . " (" . $num . ")" . $extension;
    }

    return $candidate;
}
//----------------------------------------------------------------------------
function Move_File($old, $new, $type)
{
    global $profileId;
    global $dblink;

    LogStr("Move_File() moves a file using PHP rename()", 2, __FILE__, __LINE__, __FUNCTION__);

    $old = trim($old);
    $new = trim($new);
    $type = intval($type);

    if($old == "" || $new == "")
    {
        LogError("Move_File failed: empty source or target. old=" . $old . " new=" . $new);
        return false;
    }

    if(!is_file($old))
    {
        LogError("Move_File failed: source file does not exist: " . $old);
        return false;
    }

    $nameparts = pathinfo($new);
    $targetDir = isset($nameparts["dirname"]) ? $nameparts["dirname"] : "";

    if($targetDir == "")
    {
        LogError("Move_File failed: target directory is empty for " . $new);
        return false;
    }

    if(!is_dir($targetDir))
    {
        if(@mkdir($targetDir, 0777, true))
            LogStr("mkdir() Success: " . $targetDir, 2, __FILE__, __LINE__, __FUNCTION__);
        else
        {
            LogError("Move_File failed: could not create target directory: " . $targetDir);
            return false;
        }
    }

    if($type == 0 && file_exists($new))
    {
        LogError("Move_File failed: target already exists: " . $new);
        return false;
    }

    if($type == 1 && file_exists($new))
        $new = GetNewName($new);

    if($type == 2 && file_exists($new))
    {
        if(NewDelete($new))
        {
            try
            {
                $query = "DELETE FROM files WHERE file=:file AND profile=:profile;";
                LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

                $sdt = $dblink->prepare($query);
                $sdt->bindValue(":file", $new, PDO::PARAM_STR);
                $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
                $sdt->execute();
                $sdt->closeCursor();
            }
            catch(PDOException $ex) {
                LogError(print_r($ex, true));
                return false;
            }
        }
        else
        {
            LogError("Move_File failed: could not delete existing target: " . $new);
            return false;
        }
    }

    if(!@rename($old, $new))
    {
        LogError("Move_File failed: rename() failed from " . $old . " to " . $new);
        return false;
    }

    try
    {
        $query = "UPDATE files SET file=:newfile WHERE file=:oldfile AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":newfile", $new, PDO::PARAM_STR);
        $sdt->bindValue(":oldfile", $old, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "DELETE FROM files_move WHERE file=:file AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":file", $old, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        return false;
    }

    LogStr("Move succeeded: " . $old . " -> " . $new, 2, __FILE__, __LINE__, __FUNCTION__);
    return true;
}
//----------------------------------------------------------------------------
function Link_File($old, $new)
{
    global $profileId;
    global $dup;
    global $dblink;

    LogStr("Link_File() symlinks a file using PHP symlink()", 2, __FILE__, __LINE__, __FUNCTION__);

    $old = trim($old);
    $new = trim($new);

    if($old == "" || $new == "")
    {
        LogError("Link_File failed: empty source or target. old=" . $old . " new=" . $new);
        return false;
    }

    if(!file_exists($old))
    {
        LogError("Link_File failed: source does not exist: " . $old);
        return false;
    }

    $nameparts = pathinfo($new);
    $targetDir = isset($nameparts["dirname"]) ? $nameparts["dirname"] : "";

    if($targetDir == "")
    {
        LogError("Link_File failed: target directory is empty for " . $new);
        return false;
    }

    if(!is_dir($targetDir))
    {
        if(@mkdir($targetDir, 0777, true))
            LogStr("mkdir() Success: " . $targetDir, 2, __FILE__, __LINE__, __FUNCTION__);
        else
        {
            LogError("Link_File failed: could not create target directory: " . $targetDir);
            return false;
        }
    }

    if(file_exists($new) || is_link($new))
    {
        if(!NewDelete($new))
        {
            LogError("Link_File failed: could not remove existing target: " . $new);
            return false;
        }
    }

    if(!@symlink($old, $new))
    {
        LogError("Link_File failed: symlink() failed from " . $old . " to " . $new);
        return false;
    }

    if(!is_link($new))
    {
        LogError("Link_File failed: target was not created as a symlink: " . $new);
        return false;
    }

    try
    {
        $query = "UPDATE files SET link=:link WHERE file=:file AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":link", $old, PDO::PARAM_STR);
        $sdt->bindValue(":file", $new, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $nsize = $dup->fsize($new);

        $query = "UPDATE files SET size=:size WHERE link=:link AND file=:file AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":size", intval($nsize), PDO::PARAM_INT);
        $sdt->bindValue(":link", $old, PDO::PARAM_STR);
        $sdt->bindValue(":file", $new, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();

        $query = "DELETE FROM files_link WHERE file=:file AND new=:new AND profile=:profile;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":file", $old, PDO::PARAM_STR);
        $sdt->bindValue(":new", $new, PDO::PARAM_STR);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        return false;
    }

    LogStr("Link succeeded: " . $new . " -> " . $old, 2, __FILE__, __LINE__, __FUNCTION__);
    return true;
}
//----------------------------------------------------------------------------
function NewDelete($file)
{
    $file = trim($file);

    if($file == "")
        return false;

    if(!file_exists($file) && !is_link($file))
        return true;

    if(@unlink($file))
    {
        LogStr("unlink() Success: " . $file, 2, __FILE__, __LINE__, __FUNCTION__);
        return true;
    }

    LogError("unlink() Failed: " . $file);
    return false;
}
//----------------------------------------------------------------------------
function Delete_File($old)
{
    global $profileId;
    global $dblink;

    LogStr("Delete_File() deletes a file using NewDelete function based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);

    $old = trim($old);

    if($old == "")
    {
        LogError("Delete_File failed: empty file path.");
        return false;
    }

    if(NewDelete($old))
    {
        LogStr("Delete succeeded: " . $old, 2, __FILE__, __LINE__, __FUNCTION__);

        try
        {
            $query = "DELETE FROM files WHERE file=:file AND profile=:profile;";
            LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":file", $old, PDO::PARAM_STR);
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->execute();
            $sdt->closeCursor();

            $query = "DELETE FROM files_delete WHERE file=:file AND profile=:profile;";
            LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":file", $old, PDO::PARAM_STR);
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->execute();
            $sdt->closeCursor();
        }
        catch(PDOException $ex) {
            LogError(print_r($ex, true));
            return false;
        }

        return true;
    }

    LogError("Delete failed: " . $old);
    return false;
}
//----------------------------------------------------------------------------
?>