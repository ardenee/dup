<?php
include("dup.php");

$dup = new dup($profile);

function ReadLogTail($file, $maxBytes = 65536)
{
    if(!is_file($file))
        return "";

    $size = filesize($file);

    if($size === false)
        return "";

    $fh = fopen($file, "rb");

    if(!$fh)
        return "";

    if($size > $maxBytes)
        fseek($fh, -$maxBytes, SEEK_END);

    $data = fread($fh, $maxBytes);
    fclose($fh);

    if($data === false)
        return "";

    return $data;
}

function DupLogText($value, $fallback)
{
    return htmlspecialchars($value != "" ? $value : $fallback, ENT_QUOTES, "UTF-8");
}

$lastError    = ReadLogTail(__DIR__ . "/logs/last_error.txt");
$errorHistory = ReadLogTail(__DIR__ . "/logs/error_history.log");
$phpErrors    = ReadLogTail(__DIR__ . "/logs/php_errors.log");
$dupLog       = ReadLogTail(__DIR__ . "/logs/dup.log");
$scanLogs     = glob(__DIR__ . "/logs/scan-profile-*.log");
$scanLogText  = "";

$actionLogs = glob(__DIR__ . "/logs/action-profile-*.log");
$actionLogText = "";

if($actionLogs)
{
    rsort($actionLogs);
    $actionLogText = ReadLogTail($actionLogs[0]);
}

if($scanLogs)
{
    rsort($scanLogs);
    $scanLogText = ReadLogTail($scanLogs[0]);
}
?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - Error Report</TITLE>
<META CHARSET="utf-8">
<META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
<META name="robots" content="noindex">

<!--[if lt IE 9]>
<SCRIPT SRC="files/html5.js"></SCRIPT>
<![endif]-->

<LINK REL="shortcut icon" HREF="images/dup.png">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
<LINK rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
<LINK rel="stylesheet" type="text/css" href="files/jquery.qtip.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">

<SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/bootstrap.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.cookie.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.cookiejar.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.json-2.2.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.selectboxes.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.tablesorter.widgets.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.qtip-2012-04-26.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.expand-1.3.8.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.form-2.92.js"></SCRIPT>
<SCRIPT SRC="files/script.js"></SCRIPT>

<SCRIPT TYPE="text/javascript" CHARSET="utf-8">
sbRoot = "/";
top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />';
</SCRIPT>

<SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>

<SCRIPT type="text/javascript">
$(document).ready(function(){
    $("#NAVlogs").addClass("active");
});
</SCRIPT>

<META name=GENERATOR content="MSHTML 8.00.7601.17940">

<STYLE>
.error-log-section {
    clear: both;
    margin: 0 0 20px 0;
    padding: 15px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.error-log-section h3 {
    margin: 0 0 10px 0;
}

.error-log-section .component-title {
    display: block;
    margin-bottom: 6px;
    font-weight: bold;
}

.error-log-section pre {
    display: block;
    width: auto;
    min-height: 80px;
    max-height: 360px;
    overflow: auto;
    white-space: pre-wrap;
    word-wrap: break-word;
    margin: 0;
    padding: 10px;
    background: #f8f8f8;
    border: 1px solid #ccc;
    border-radius: 3px;
    font-family: Consolas, Monaco, monospace;
    font-size: 12px;
    line-height: 1.35;
}
</STYLE>

</HEAD>

<BODY>

<?php echo PageHeader(); ?>

<DIV id=SubMenu>
<SPAN><A href="clear-logs.php?confirm=1" onclick="return confirm('Clear all DUP logs and error reports? This cannot be undone.');">Clear Logs</A></SPAN>
    <SPAN><A href="logs.php">View DUP Logs</A></SPAN>
    <SPAN><A href="index.php">Back to DUP</A></SPAN>
</DIV>
</HEADER>

<div id="contentWrapper">
<div id="content">

<h1 class="title">Error Report</h1>

<div id="config">
<div id="config-content">
<div id="config-components">

<div class="error-log-section">
    <h3>Latest captured application error</h3>
    <span class="component-title">logs/last_error.txt</span>
    <pre><?php echo DupLogText($lastError, "No captured application error found."); ?></pre>
</div>

<div class="error-log-section">
    <h3>PHP error log</h3>
    <span class="component-title">logs/php_errors.log</span>
    <pre><?php echo DupLogText($phpErrors, "No PHP errors found."); ?></pre>
</div>


<div class="error-log-section">
    <h3>DUP application log</h3>
    <span class="component-title">logs/dup.log</span>
    <pre><?php echo DupLogText($dupLog, "No DUP application log entries found."); ?></pre>
</div>


<div class="error-log-section">
    <h3>Latest scan log</h3>
    <span class="component-title">logs/scan-profile-*.log</span>
    <pre><?php echo DupLogText($scanLogText, "No scan log found."); ?></pre>
</div>

<div class="error-log-section">
    <h3>Latest action log</h3>
    <span class="component-title">logs/action-profile-*.log</span>
    <pre><?php echo DupLogText($actionLogText, "No action log found."); ?></pre>
</div>

<div class="error-log-section">
    <h3>Error history</h3>
    <span class="component-title">logs/error_history.log</span>
    <pre><?php echo DupLogText($errorHistory, "No error history found."); ?></pre>
</div>

<div class="component-group-save">
<a class="btn btn-danger" href="clear-logs.php?confirm=1" onclick="return confirm('Clear all DUP logs and error reports? This cannot be undone.');">Clear Logs</a>
<a class="btn" href="logs.php">View DUP Logs</a>
<a class="btn" href="index.php">Back to DUP</a>
</div>

</div><!-- /config-components -->
</div><!-- /config-content -->
</div><!-- /config -->

</div><!-- /content -->
</div><!-- /contentWrapper -->

<?php echo PageFooter(); ?>

</BODY>
</HTML>