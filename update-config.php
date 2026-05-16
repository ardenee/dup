<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Settings - Update", 1, __FILE__, __LINE__, __FUNCTION__);

function CleanFilters($filters)
{
    LogStr("CleanFilters() Clean up filters via passed pram", 2, __FILE__, __LINE__, __FUNCTION__);

    $filters = str_replace(array("\r", "\n", "\0"), "", (string)$filters);
    $tmp = explode(",", $filters);
    $clean = array();

    foreach($tmp as $val)
    {
        $val = trim(strtolower($val));

        if($val != "" && !in_array($val, $clean))
            $clean[] = $val;
    }

    return implode(",", $clean);
}

function PostInt($name, $default = 0)
{
    if(!isset($_POST[$name]))
        return intval($default);

    return intval($_POST[$name]);
}

function PostCheckbox($name)
{
    return isset($_POST[$name]) ? 1 : 0;
}

function SafeReturnTo()
{
    $allowed = array("config-general.php", "config-viewer.php", "config-profiletypes.php");
    $returnTo = isset($_POST["return_to"]) ? basename((string)$_POST["return_to"]) : "config-general.php";

    if(!in_array($returnTo, $allowed))
        return "config-general.php";

    return $returnTo;
}

function EnsureSettingsColumn($name, $definition)
{
    global $dblink;

    try {
        $dblink->exec("ALTER TABLE settings ADD " . $name . " " . $definition . ";");
    }
    catch(PDOException $ex) {}
}

function SetConfigMessage($success)
{
    global $dblink;
    global $dbfile;

    $dbName = ($dbfile == 0) ? "MariaDB" : "SQLite";

    if($success)
        $message = array("notification-1" => array("message" => $dbName . " database updated", "type" => "notice", "title" => "Settings Saved!"));
    else
        $message = array("notification-1" => array("message" => "Error saving to " . $dbName . ", check logs!", "type" => "error", "title" => "Settings NOT Saved!"));

    try
    {
        $query = "UPDATE msg SET message=:message;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":message", json_encode($message), PDO::PARAM_STR);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }
}

$returnTo = SafeReturnTo();

EnsureSettingsColumn("view_enable", "int(11) NOT NULL DEFAULT '1'");
EnsureSettingsColumn("view_image_exts", "text COLLATE utf8_bin NOT NULL");
EnsureSettingsColumn("view_text_exts", "text COLLATE utf8_bin NOT NULL");
EnsureSettingsColumn("view_pdf_exts", "text COLLATE utf8_bin NOT NULL");

$renametype = PostInt("renametype", isset($dup->Settings["renametype"]) ? $dup->Settings["renametype"] : 0);
$namecleanup = PostCheckbox("namecleanup");
$cleanupfilter = isset($_POST["cleanupfilter"]) ? CleanFilters($_POST["cleanupfilter"]) : (isset($dup->Settings["cleanupfilter"]) ? $dup->Settings["cleanupfilter"] : "");
$dot = PostInt("dot", isset($dup->Settings["dot"]) ? $dup->Settings["dot"] : 0);
$under = PostInt("under", isset($dup->Settings["under"]) ? $dup->Settings["under"] : 0);
$doubleb = PostInt("doubleb", isset($dup->Settings["doubleb"]) ? $dup->Settings["doubleb"] : 0);
$flatfolders = PostCheckbox("flatfolders");
$logs = PostCheckbox("logs");
$displaymoved = PostCheckbox("displaymoved");
$cpfolder = PostCheckbox("cpfolder");
$shortfilenames = PostInt("shortfilenames", isset($dup->Settings["shortfilenames"]) ? $dup->Settings["shortfilenames"] : 0);
$runtime = PostInt("runtime", isset($dup->Settings["runtime"]) ? $dup->Settings["runtime"] : 10800);
$ifolders = isset($_POST["ifolders"]) ? CleanFilters($_POST["ifolders"]) : (isset($dup->Settings["ifolders"]) ? $dup->Settings["ifolders"] : "");
$download = PostCheckbox("download");
$viewEnable = PostCheckbox("view_enable");
$viewImageExts = isset($_POST["view_image_exts"]) ? CleanFilters($_POST["view_image_exts"]) : "jpg,jpeg,png,gif,webp,bmp";
$viewTextExts = isset($_POST["view_text_exts"]) ? CleanFilters($_POST["view_text_exts"]) : "txt,log,json,xml,csv,nfo,srt,md";
$viewPdfExts = isset($_POST["view_pdf_exts"]) ? CleanFilters($_POST["view_pdf_exts"]) : "pdf";

if($runtime < 30)
    $runtime = 10800;

try
{
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
            download=:download,
            view_enable=:view_enable,
            view_image_exts=:view_image_exts,
            view_text_exts=:view_text_exts,
            view_pdf_exts=:view_pdf_exts
    ";

    LogStr("MySQL/SQLite: update settings", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":renametype", $renametype, PDO::PARAM_INT);
    $sdt->bindValue(":namecleanup", $namecleanup, PDO::PARAM_INT);
    $sdt->bindValue(":cleanupfilter", $cleanupfilter, PDO::PARAM_STR);
    $sdt->bindValue(":dot", $dot, PDO::PARAM_INT);
    $sdt->bindValue(":under", $under, PDO::PARAM_INT);
    $sdt->bindValue(":doubleb", $doubleb, PDO::PARAM_INT);
    $sdt->bindValue(":flatfolders", $flatfolders, PDO::PARAM_INT);
    $sdt->bindValue(":logs", $logs, PDO::PARAM_INT);
    $sdt->bindValue(":displaymoved", $displaymoved, PDO::PARAM_INT);
    $sdt->bindValue(":cpfolder", $cpfolder, PDO::PARAM_INT);
    $sdt->bindValue(":shortfilenames", $shortfilenames, PDO::PARAM_INT);
    $sdt->bindValue(":runtime", $runtime, PDO::PARAM_INT);
    $sdt->bindValue(":ifolders", $ifolders, PDO::PARAM_STR);
    $sdt->bindValue(":download", $download, PDO::PARAM_INT);
    $sdt->bindValue(":view_enable", $viewEnable, PDO::PARAM_INT);
    $sdt->bindValue(":view_image_exts", $viewImageExts, PDO::PARAM_STR);
    $sdt->bindValue(":view_text_exts", $viewTextExts, PDO::PARAM_STR);
    $sdt->bindValue(":view_pdf_exts", $viewPdfExts, PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();

    SetConfigMessage(true);
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    SetConfigMessage(false);
    header("Location: error.php");
    exit;
}

header("Location: " . $returnTo);
exit;
?>