<?php
include("dup.php");
$dup = new dup($profile);

if(!isset($_GET["confirm"]) || $_GET["confirm"] != "1")
{
    ?>
    <!DOCTYPE HTML>
    <HTML>
    <HEAD>
        <TITLE>dup - Clear Logs</TITLE>
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
        <SCRIPT TYPE="text/javascript" SRC="files/bootstrap.min.js"></SCRIPT>
        <SCRIPT SRC="files/script.js"></SCRIPT>
    </HEAD>
    <BODY>

    <?php echo PageHeader(); ?>

    <DIV id=SubMenu>
        <SPAN><A href="error.php">Error Report</A></SPAN>
        <SPAN><A href="logs.php">View DUP Logs</A></SPAN>
        <SPAN><A href="index.php">Back to DUP</A></SPAN>
    </DIV>
    </HEADER>

    <div id="contentWrapper">
        <div id="content">
            <h1 class="title">Clear Logs</h1>

            <div id="config">
                <div id="config-content">
                    <div id="config-components">
                        <div class="alert alert-error">
                            <p><b>This will clear DUP logs and error reports.</b></p>
                            <p>This includes application logs, PHP error logs, scan logs, setup errors, and error history.</p>
                            <p>This cannot be undone.</p>
                        </div>

                        <div class="component-group-save">
                            <a class="btn btn-danger" href="clear-logs.php?confirm=1" onclick="return confirm('Clear all DUP logs and error reports? This cannot be undone.');">Clear Logs</a>
                            <a class="btn" href="error.php">Cancel</a>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <?php echo PageFooter(); ?>

    </BODY>
    </HTML>
    <?php
    exit;
}

$logDir = __DIR__ . "/logs";

$filesToClear = array(
    $logDir . "/log.txt",
    $logDir . "/dup.log",
    $logDir . "/php_errors.log",
    $logDir . "/last_error.txt",
    $logDir . "/error_history.log",
    $logDir . "/setup_error.txt",
    $logDir . "/db_connect_error.txt",
    $logDir . "/index_debug.txt"
);

$scanLogs = glob($logDir . "/scan-profile-*.log");

if($scanLogs)
{
    foreach($scanLogs as $scanLog)
        $filesToClear[] = $scanLog;
}

$actionLogs = glob($logDir . "/action-profile-*.log");

if($actionLogs)
{
    foreach($actionLogs as $actionLog)
        $filesToClear[] = $actionLog;
}

foreach($filesToClear as $file)
{
    if(is_file($file))
        file_put_contents($file, "");
}

try
{
    if(isset($dblink) && $dblink)
    {
        if(isset($dbfile) && intval($dbfile) == 0)
            $dblink->exec("TRUNCATE TABLE logs;");
        else
            $dblink->exec("DELETE FROM logs;");
    }
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
}

header("Location: error.php");
exit;
?>