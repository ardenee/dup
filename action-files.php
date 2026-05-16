<?php

require_once("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);

$allowedActions = array(
    "Delete" => "files-delete.php",
    "Rename" => "files-rename.php",
    "Move"   => "files-move.php",
    "Link"   => "files-link.php"
);

$act = isset($_POST["act"]) ? trim($_POST["act"]) : "";
$files = isset($_POST["files"]) ? $_POST["files"] : array();

LogStr("Action request: " . $act . " profile=" . $profileId, 1, __FILE__, __LINE__, __FUNCTION__);

if($profileId <= 0)
{
    LogError("Invalid profile id in action-files.php: " . $profile);
    header("Location: error.php");
    exit;
}

if(!isset($allowedActions[$act]))
{
    LogError("Invalid file action requested: " . $act);
    header("Location: error.php");
    exit;
}

if(!is_array($files))
    $files = array($files);

$cleanFiles = array();

foreach($files as $fileId)
{
    $fileId = intval($fileId);

    if($fileId > 0)
        $cleanFiles[] = $fileId;
}

$cleanFiles = array_values(array_unique($cleanFiles));

if(count($cleanFiles) == 0)
{
    LogError("No valid files selected for action: " . $act);
    header("Location: profile-view.php?profile=" . $profileId);
    exit;
}

$form = $allowedActions[$act];

?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - <?php echo htmlspecialchars($act, ENT_QUOTES, "UTF-8"); ?> Files</TITLE>
<META CHARSET="utf-8">
</HEAD>
<BODY onload="document.getElementById('actionForm').submit();">

<form action="<?php echo htmlspecialchars($form, ENT_QUOTES, "UTF-8"); ?>" id="actionForm" name="actionForm" method="post">
<?php
foreach($cleanFiles as $fileId)
    echo "    <input type=\"hidden\" name=\"files[]\" value=\"" . $fileId . "\">\n";
?>
    <input type="hidden" name="profile" value="<?php echo $profileId; ?>">
    <input type="hidden" name="from" value="profile-view.php?profile=<?php echo $profileId; ?>">
</form>

<noscript>
    <p>JavaScript is required to continue this action.</p>
    <button type="submit" form="actionForm">Continue</button>
</noscript>

</BODY>
</HTML>