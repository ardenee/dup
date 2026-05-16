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

$filters = str_replace(array("\r", "\n", "\0"), "", $filters);
$selectedFiles = isset($_POST['files']) && is_array($_POST['files']) ? $_POST['files'] : array();

LogStr("Renaming '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "global") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

$successCount = 0;
$failCount = 0;
$skipCount = 0;

if(count($selectedFiles) == 0)
{
    LogStr("Mass rename skipped: no selected files.", 2, __FILE__, __LINE__, __FUNCTION__);
    header("Location: renaming.php?profile=" . $profileId . "&filters=" . urlencode($filters));
    exit;
}

$selectedIds = array();

foreach($selectedFiles as $selectedFile)
{
    $id = intval($selectedFile);

    if($id > 0)
        $selectedIds[] = $id;
}

$selectedIds = array_values(array_unique($selectedIds));

if(count($selectedIds) == 0)
{
    LogStr("Mass rename skipped: selected file IDs were invalid.", 2, __FILE__, __LINE__, __FUNCTION__);
    header("Location: renaming.php?profile=" . $profileId . "&filters=" . urlencode($filters));
    exit;
}

try
{
    $placeholders = array();

    foreach($selectedIds as $idx => $id)
        $placeholders[] = ":id" . $idx;

    $where = "id IN (" . implode(",", $placeholders) . ")";

    if($profileId != 0)
        $where .= " AND profile=:profile";

    $query = "SELECT id, file, profile FROM files WHERE " . $where . " ORDER BY file;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);

    foreach($selectedIds as $idx => $id)
        $sdt->bindValue(":id" . $idx, $id, PDO::PARAM_INT);

    if($profileId != 0)
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);

    $sdt->execute();

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        if(!isset($row['file']))
        {
            $skipCount++;
            continue;
        }

        $old = trim($row['file']);
        $path_parts = pathinfo($old);
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

        if(!@rename($old, $new))
        {
            $lastError = error_get_last();
            $errorText = $lastError ? " | " . print_r($lastError, true) : "";
            LogError("Mass rename failed: " . $old . " -> " . $new . $errorText);
            $failCount++;
            continue;
        }

        try
        {
            $rowProfile = isset($row['profile']) ? intval($row['profile']) : $profileId;
            $query2 = "UPDATE files SET file=:newfile WHERE id=:id AND file=:oldfile AND profile=:profile;";
            LogStr("MySQL/SQLite: $query2", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt2 = $dblink->prepare($query2);
            $sdt2->bindValue(":newfile", $new, PDO::PARAM_STR);
            $sdt2->bindValue(":oldfile", $old, PDO::PARAM_STR);
            $sdt2->bindValue(":id", intval($row['id']), PDO::PARAM_INT);
            $sdt2->bindValue(":profile", $rowProfile, PDO::PARAM_INT);
            $sdt2->execute();
            $sdt2->closeCursor();

            LogStr("Rename Success: " . $old . " -> " . $new, 2, __FILE__, __LINE__, __FUNCTION__);
            $successCount++;
        }
        catch(PDOException $ex) {
            LogError(print_r($ex, true));
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

    return CleanUpNameFiltersOnly($basename);
}
//----------------------------------------------------------------------------
function CleanUpName($name)
{
    global $dup;
    global $filters;

    $nameparts = pathinfo($name);
    $catches = explode(",", $filters);
    $base = isset($nameparts['filename']) ? $nameparts['filename'] : $name;
    $extension = isset($nameparts['extension']) && $nameparts['extension'] != "" ? "." . $nameparts['extension'] : "";

    foreach($catches as $val)
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
function CleanUpNameFiltersOnly($name)
{
    global $filters;

    $nameparts = pathinfo($name);
    $catches = explode(",", $filters);
    $base = isset($nameparts['filename']) ? $nameparts['filename'] : $name;
    $extension = isset($nameparts['extension']) && $nameparts['extension'] != "" ? "." . $nameparts['extension'] : "";

    foreach($catches as $val)
    {
        $val = trim($val);

        if($val != "")
            $base = str_ireplace($val, "", $base);
    }

    return trim($base) . $extension;
}
//----------------------------------------------------------------------------
?>
