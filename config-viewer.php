<?php
include("dup.php");
$dup = new dup($profile);
LogStr("File Viewer Settings", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DupSettingInt($key, $default = 0)
{
    global $dup;
    return isset($dup->Settings[$key]) ? intval($dup->Settings[$key]) : intval($default);
}

function DupSettingStr($key, $default = "")
{
    global $dup;
    return isset($dup->Settings[$key]) ? (string)$dup->Settings[$key] : (string)$default;
}

$defaultImageExts = "jpg,jpeg,png,gif,webp,bmp";
$defaultTextExts = "txt,log,json,xml,csv,nfo,srt,md,php,css,js,html,htm,ini,conf";
$defaultPdfExts = "pdf";
?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - File Viewer Settings</TITLE>
<META CHARSET="utf-8">
<META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
<META name="robots" content="noindex">
<LINK REL="shortcut icon" HREF="images/dup.png">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">
<SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>
<SCRIPT type="text/javascript">
$(document).ready(function(){ $("#NAVconfig").addClass("active"); });
</SCRIPT>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
<DIV id=SubMenu><SPAN><A HREF="config-help.php">Help</A> | <A HREF="config-general.php">General</A> | <A HREF="config-profiletypes.php">Profile Types</A> | <A HREF="config-viewer.php">File Viewer</A></SPAN></DIV>
</HEADER>
<div id="contentWrapper">
<div id="content">
<h1 class="title">File Viewer Configuration</h1>
<div id="config">
<div id="config-content">
<form id="configForm" action="update-config.php" method="post">
<input type="hidden" name="renametype" value="<?php echo DupSettingInt("renametype"); ?>">
<input type="hidden" name="cleanupfilter" value="<?php echo DupHtml(DupSettingStr("cleanupfilter")); ?>">
<input type="hidden" name="dot" value="<?php echo DupSettingInt("dot"); ?>">
<input type="hidden" name="under" value="<?php echo DupSettingInt("under"); ?>">
<input type="hidden" name="doubleb" value="<?php echo DupSettingInt("doubleb"); ?>">
<input type="hidden" name="shortfilenames" value="<?php echo DupSettingInt("shortfilenames"); ?>">
<input type="hidden" name="runtime" value="<?php echo DupSettingInt("runtime", 10800); ?>">
<input type="hidden" name="ifolders" value="<?php echo DupHtml(DupSettingStr("ifolders")); ?>">
<?php if(DupSettingInt("namecleanup") == 1) echo "<input type=\"hidden\" name=\"namecleanup\" value=\"on\">\n"; ?>
<?php if(DupSettingInt("flatfolders") == 1) echo "<input type=\"hidden\" name=\"flatfolders\" value=\"on\">\n"; ?>
<?php if(DupSettingInt("logs") == 1) echo "<input type=\"hidden\" name=\"logs\" value=\"on\">\n"; ?>
<?php if(DupSettingInt("displaymoved") == 1) echo "<input type=\"hidden\" name=\"displaymoved\" value=\"on\">\n"; ?>
<?php if(DupSettingInt("cpfolder") == 1) echo "<input type=\"hidden\" name=\"cpfolder\" value=\"on\">\n"; ?>
<?php if(DupSettingInt("download") == 1) echo "<input type=\"hidden\" name=\"download\" value=\"on\">\n"; ?>
<div id="config-components">
<div class="component-group clearfix">
<div class="component-group-desc"><h3>File Viewer</h3><p>Control which file types may be previewed from View links.</p></div>
<fieldset class="component-group-list">
<div class="field-pair">
<input type="checkbox" name="view_enable" id="view_enable" <?php echo ((DupSettingInt("view_enable", 1) == 1) ? " CHECKED" : ""); ?>/>
<label class="clearfix" for="view_enable"><span class="component-title">Enable File Viewer</span><span class="component-desc">Allow View links to display metadata and safe previews.</span></label>
</div>
<div class="field-pair">
<label class="nocheck clearfix"><span class="component-title">Image Preview Extensions</span><input type="text" name="view_image_exts" value="<?php echo DupHtml(DupSettingStr("view_image_exts", $defaultImageExts)); ?>" size="55" /></label>
<label class="nocheck clearfix"><span class="component-title">&nbsp;</span><span class="component-desc">Comma separated. Defaults: <?php echo DupHtml($defaultImageExts); ?></span></label>
</div>
<div class="field-pair">
<label class="nocheck clearfix"><span class="component-title">Text Preview Extensions</span><input type="text" name="view_text_exts" value="<?php echo DupHtml(DupSettingStr("view_text_exts", $defaultTextExts)); ?>" size="55" /></label>
<label class="nocheck clearfix"><span class="component-title">&nbsp;</span><span class="component-desc">Comma separated. Text previews should be escaped before display.</span></label>
</div>
<div class="field-pair">
<label class="nocheck clearfix"><span class="component-title">PDF Preview Extensions</span><input type="text" name="view_pdf_exts" value="<?php echo DupHtml(DupSettingStr("view_pdf_exts", $defaultPdfExts)); ?>" size="55" /></label>
<label class="nocheck clearfix"><span class="component-title">&nbsp;</span><span class="component-desc">Comma separated. Leave blank to disable PDF inline view.</span></label>
</div>
<div class="component-group-save"><input type="submit" class="btn config_submitter" value="Save Changes" /></div>
</fieldset>
</div>
</div>
</form>
</div>
</div>
</div>
</div>
<?php echo PageFooter(); ?>
</BODY>
</HTML>
