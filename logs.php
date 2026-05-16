<?php
include("dup.php");

$dup = new dup($profile);
LogStr("Logs", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function LType($t)
{
    global $c;

    switch(intval($t))
    {
        case 1:
            $c = "Green";
            return "Logs";

        case 2:
            $c = "Blue";
            return "Info";

        case 3:
            $c = "Brown";
            return "Debug";

        case 4:
            $c = "Red";
            return "Error";

        default:
            $c = "black";
            return "Unknown";
    }
}

$show = 200;
$level = 1;
$userFilter = -1;

if(isset($_POST['level']))
    $level = intval($_POST['level']);
else if(isset($_GET['level']))
    $level = intval($_GET['level']);

if(isset($_POST['show']))
    $show = intval($_POST['show']);
else if(isset($_GET['show']))
    $show = intval($_GET['show']);

if(isset($_POST['user_id']))
    $userFilter = intval($_POST['user_id']);
else if(isset($_GET['user_id']))
    $userFilter = intval($_GET['user_id']);

if($level < 1 || $level > 4)
    $level = 1;

$allowedShow = array(200, 500, 2000, 5000);

if(!in_array($show, $allowedShow))
    $show = 200;

$users = array();

try
{
    $query = "SELECT id, name FROM accounts ORDER BY name ASC;";
    $sdt = $dblink->prepare($query);
    $sdt->execute();
    $users = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - Logs</TITLE>
<META CHARSET="utf-8">
<META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
<META name="robots" content="noindex">
<!--[if lt IE 9]><SCRIPT SRC="files/html5.js"></SCRIPT><![endif]-->
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
    $("#historyTable:has(tbody tr)").tablesorter({widgets: ['zebra', 'filter'], sortList: [[1,1]]});
});
</SCRIPT>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
<DIV id=SubMenu><SPAN><A href="clear-logs.php">Clear Logs</A></SPAN></DIV>
</HEADER>
<div id="contentWrapper">
<div id="content">
<h1 class="title">Logs - Level <?php echo intval($level) . " / " . intval($show); ?></h1>
<form name="logs" id="logs" action="logs.php" method="post">
<div class="h2footer align-right"><b>Minimum logging level to display:</b>
<select name="level" id="level" onchange="this.form.submit()">
<option value="1" <?php echo (($level == 1) ? " SELECTED" : ""); ?>>Level 1 - Default</option>
<option value="2" <?php echo (($level == 2) ? " SELECTED" : ""); ?>>Level 2 - More Information</option>
<option value="3" <?php echo (($level == 3) ? " SELECTED" : ""); ?>>Level 3 - Debug</option>
<option value="4" <?php echo (($level == 4) ? " SELECTED" : ""); ?>>Level 4 - All</option>
</select>
</div>
<br>
<div class="h2footer align-right"><b>User:</b>
<select name="user_id" id="user_id" onchange="this.form.submit()">
<option value="-1" <?php echo (($userFilter == -1) ? " SELECTED" : ""); ?>>All Users</option>
<option value="0" <?php echo (($userFilter == 0) ? " SELECTED" : ""); ?>>System / Unknown</option>
<?php
foreach($users as $user)
{
    $id = intval($user['id']);
    echo "<option value=\"" . $id . "\"" . (($userFilter == $id) ? " SELECTED" : "") . ">" . DupHtml($user['name']) . "</option>\n";
}
?>
</select>
</div>
<br>
<div class="h2footer align-right"><b>Number of Logs to Show:</b>
<select name="show" id="show" onchange="this.form.submit()">
<option value="200" <?php echo (($show == 200) ? " SELECTED" : ""); ?>>200</option>
<option value="500" <?php echo (($show == 500) ? " SELECTED" : ""); ?>>500</option>
<option value="2000" <?php echo (($show == 2000) ? " SELECTED" : ""); ?>>2000</option>
<option value="5000" <?php echo (($show == 5000) ? " SELECTED" : ""); ?>>5000</option>
</select>
</div>
<br>
</form>
<?php
$c = "Green";
$whereParts = array("l.type<=:level");

if($userFilter >= 0)
    $whereParts[] = "l.user_id=:user_id";

echo "<TABLE id=\"historyTable\" class=\"tablesorter\" border=\"0\" cellSpacing=\"1\" cellPadding=\"0\">\n";
echo " <THEAD>\n";
echo " <TR class=\"tablesorter-header\">\n";
echo " <TH class=\"nowrap tablesorter-header tablesorter-headerSortUp\"><div class=\"tablesorter-header-inner\">Num (id)</div></TH>\n";
echo " <TH class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Time</div></TH>\n";
echo " <TH class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">User</div></TH>\n";
echo " <TH class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Type</div></TH>\n";
echo " <TH class=\"tablesorter-header\"><div class=\"tablesorter-header-inner\">Info</div></TH>\n";
echo " <TH class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Function</div></TH>\n";
echo " <TH class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">File (Line)</div></TH>\n";
echo " </TR>\n";
echo " </THEAD>\n";
echo " <TBODY>\n";

try
{
    $query = "SELECT l.id, l.stamp, l.type, l.str, l.function, l.page, l.line, l.user_id, a.name AS user_name FROM logs l LEFT JOIN accounts a ON a.id=l.user_id WHERE " . implode(" AND ", $whereParts) . " ORDER BY l.id DESC LIMIT :show;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":level", $level, PDO::PARAM_INT);

    if($userFilter >= 0)
        $sdt->bindValue(":user_id", $userFilter, PDO::PARAM_INT);

    $sdt->bindValue(":show", $show, PDO::PARAM_INT);
    $sdt->execute();
    $logs = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    $num = 1;

    if(count($logs) == 0)
    {
        echo " <TR>\n";
        echo " <TD colspan=\"7\" align=\"center\"><b>No log entries found.</b></TD>\n";
        echo " </TR>\n";
    }

    foreach($logs as $row)
    {
        $TYPE = LType(isset($row['type']) ? intval($row['type']) : 0);
        $id = isset($row['id']) ? intval($row['id']) : 0;
        $stamp = isset($row['stamp']) ? $row['stamp'] : "";
        $userId = isset($row['user_id']) ? intval($row['user_id']) : 0;
        $userName = isset($row['user_name']) && $row['user_name'] != "" ? $row['user_name'] : (($userId == 0) ? "System / Unknown" : "User #" . $userId);
        $str = isset($row['str']) ? FromSafeSQLValue($row['str']) : "";
        $function = isset($row['function']) ? $row['function'] : "";
        $page = isset($row['page']) ? $row['page'] : "";
        $line = isset($row['line']) ? intval($row['line']) : 0;

        echo " <TR>\n";
        echo " <TD style=\"width:50px\"><span title=\"id: " . number_format($id) . "\"> " . number_format($num) . " </span></TD>\n";
        echo " <TD class=\"nowrap\" style=\"width:150px\"> " . DupHtml($stamp) . " </TD>\n";
        echo " <TD class=\"nowrap\" style=\"width:120px\"><span title=\"User ID: " . $userId . "\">" . DupHtml($userName) . "</span></TD>\n";
        echo " <TD style=\"width:50px\"><B><font color=\"" . DupHtml($c) . "\"> " . DupHtml($TYPE) . " </font></B></TD>\n";
        echo " <TD> " . DupHtml($str) . " </TD>\n";
        echo " <TD style=\"width:150px\"> " . DupHtml($function) . " </TD>\n";
        echo " <TD style=\"width:150px\"><B><font color=\"brown\"><span title=\"Line: " . $line . "\">" . DupHtml($page) . "</span></font></B></TD>\n";
        echo " </TR>\n";
        $num++;
    }
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}

echo " </TBODY>\n";
echo "</TABLE>\n";
?>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
</HTML>
