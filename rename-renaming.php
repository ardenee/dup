<?php

include("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);

if($profileId != 0 && isset($dup->Profile['id']) && $profileId != intval($dup->Profile['id']))
    $profileId = intval($dup->Profile['id']);

if(isset($_POST['filters']))
    $filters = trim($_POST['filters']);
else
    $filters = isset($dup->Settings['cleanupfilter']) ? $dup->Settings['cleanupfilter'] : "";

$filters = str_replace(array("\r", "\n"), "", $filters);

LogStr("Renaming '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "global") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

$MovedFolders = $dup->GetMovedFolders($profileId);
$successCount = 0;
$failCount = 0;
$skipCount = 0;

try
{
    if($profileId != 0)
    {
        $query = "SELECT file FROM files WHERE profile=:profile ORDER BY file LIMIT 995;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();
    }
    else
    {
        $query = "SELECT file FROM files ORDER BY profile, file LIMIT 995;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->query($query);
    }

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        if(!isset($row['file']))
            continue;

        if(IsMovedFile($row['file'], $MovedFolders) && $dup->Settings['displaymoved'] == 0)
        {
            $skipCount++;
            continue;
        }

        $path_parts = pathinfo($row['file']);
        $old = trim($row['file']);
        $basename = isset($path_parts['basename']) ? $path_parts['basename'] : basename($old);
        $dirname = isset($path_parts['dirname']) ? $path_parts['dirname'] : ".";

        $new = trim($dirname . "/" . CleanName($basename));

        if($old == $new)
        {
            $skipCount++;
            continue;
        }

        if(file_exists($new) || is_link($new))
            $new = trim(MultiFile($new));

        if(!is_file($old) && !is_link($old))
        {
            LogError("Mass rename source missing: " . $old);
            $failCount++;
            continue;
        }

        if(@rename($old, $new))
        {
            LogStr("Rename Success: $old -> $new", 2, __FILE__, __LINE__, __FUNCTION__);

            try
            {
                if($profileId != 0)
                {
                    $query = "UPDATE files SET file=:newfile WHERE file=:oldfile AND profile=:profile;";
                    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

                    $sdt2 = $dblink->prepare($query);
                    $sdt2->bindValue(":newfile", $new, PDO::PARAM_STR);
                    $sdt2->bindValue(":oldfile", $old, PDO::PARAM_STR);
                    $sdt2->bindValue(":profile", $profileId, PDO::PARAM_INT);
                    $sdt2->execute();
                    $sdt2->closeCursor();
                }
                else
                {
                    $query = "UPDATE files SET file=:newfile WHERE file=:oldfile;";
                    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

                    $sdt2 = $dblink->prepare($query);
                    $sdt2->bindValue(":newfile", $new, PDO::PARAM_STR);
                    $sdt2->bindValue(":oldfile", $old, PDO::PARAM_STR);
                    $sdt2->execute();
                    $sdt2->closeCursor();
                }

                $successCount++;
            }
            catch(PDOException $ex) {
                LogError(print_r($ex, true));
                $failCount++;
            }
        }
        else
        {
            $lastError = error_get_last();
			$errorText = $lastError ? " | " . print_r($lastError, true) : "";

			LogError("Mass rename failed: " . $old . " -> " . $new . $errorText);
			$failCount++;
        }
    }

    $sdt->closeCursor();
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}

LogStr("Mass rename complete: success=" . $successCount . " failed=" . $failCount . " skipped=" . $skipCount, 1, __FILE__, __LINE__, __FUNCTION__);

header("Location: renaming.php?profile=" . $profileId . "&filters=" . urlencode($filters));
exit;

//----------------------------------------------------------------------------

function MultiFile($name)
{
    LogStr("MultiFile() Gets available filename", 2, __FILE__, __LINE__, __FUNCTION__);

    $path_parts = pathinfo($name);
    $dir = isset($path_parts['dirname']) ? $path_parts['dirname'] : ".";
    $filename = isset($path_parts['filename']) ? $path_parts['filename'] : basename($name);
    $extension = isset($path_parts['extension']) && $path_parts['extension'] != "" ? "." . $path_parts['extension'] : "";

    $num = 1;
    $candidate = $name;

    while(file_exists($candidate) || is_link($candidate))
    {
        $candidate = $dir . "/" . $filename . " (" . $num . ")" . $extension;
        $num++;
    }

    return $candidate;
}

//----------------------------------------------------------------------------

function CleanName($name)
{
    LogStr("CleanName() Cleans name based on settings", 2, __FILE__, __LINE__, __FUNCTION__);

    global $dup;

    $nameparts = pathinfo($name);
    $basename = isset($nameparts['basename']) ? $nameparts['basename'] : $name;

    if($dup->Settings['namecleanup'] == 1)
        return CleanUpName($basename);

    return $basename;
}

//----------------------------------------------------------------------------

function CleanUpName($name)
{
    LogStr("CleanUpName() Cleans name based on cleanup filter", 2, __FILE__, __LINE__, __FUNCTION__);

    global $dup;
    global $filters;

    $nameparts = pathinfo($name);
    $catches = explode(",", $filters);

    $base = isset($nameparts['filename']) ? $nameparts['filename'] : $name;
    $extension = isset($nameparts['extension']) && $nameparts['extension'] != "" ? "." . $nameparts['extension'] : "";

    foreach($catches as $key => $val)
    {
        $val = trim($val);

        if($val != "")
            $base = str_ireplace($val, "", $base);
    }

    if($dup->Settings['dot'] > 0)
        $base = str_ireplace(".", (($dup->Settings['dot'] == 1) ? " " : "_"), $base);

    if($dup->Settings['under'] > 0)
        $base = str_ireplace("_", (($dup->Settings['under'] == 1) ? " " : "."), $base);

if($dup->Settings['doubleb'] > 0)
{
    while(strpos($base, "  ") !== false)
        $base = str_replace("  ", " ", $base);
}

    return trim($base) . $extension;
}

//----------------------------------------------------------------------------

?>