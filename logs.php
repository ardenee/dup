<?php
include("dup.php");

$dup = new dup($profile);
LogStr("Logs", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function LogTypeText($type)
{
    switch(intval($type))
    {
        case 1: return array("Logs", "Green");
        case 2: return array("Info", "Blue");
        case 3: return array("Debug", "Brown");
        case 4: return array("Error", "Red");
    }

    return array("Unknown", "black");
}

$level = isset($_REQUEST['level']) ? intval($_REQUEST['level']) : 1;
$show = isset($_REQUEST['show']) ? intval($_REQUEST['show']) : 200;
$userFilter = isset($_REQUEST['user_id']) ? intval($_REQUEST['user_id']) : -1;

if($level < 1 || $level > 4)
    $level = 1;

if(!in_array($show, array(200, 500, 2000, 5000)))
    $show = 200;

$users = array();

try
{
    $sdt = $dblink->prepare("SELECT id, name FROM accounts ORDER BY name ASC;");
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
<LINK REL="shortcut icon" HREF="images/dup.png">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">
<SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
<SCRIPT SRC="files/script.js"></SCRIPT>
<SCRIPT type="text/javascript">
$(document).ready(function(){
    $("#NAVlogs").addClass("active");
    $("#historyTable:has(tbody tr)").tablesorter({widgets:['zebra'], sortList:[[1,1]]});
});
</SCRIPT>
<STYLE>
.logFilterBox {
    margin: 8px 0 14px 0;
    padding: 10px;
    background: #dfdacf;
    border: 1px solid #999;
    border-radius: 4px;
}
.logFilterRow {
    display: block;
    clear: both;
    margin: 6px 0;
    line-height: 28px;
}
.logFilterRow label {
    display: inline-block;
    width: 245px;
    text-align: right;
    margin-right: 10px;
    font-weight: bold;
}
.logFilterRow select {
    width: 260px;
}
.logFilterRow select#show {
    width: 90px;
}
#historyTable td:nth-child(5) {
    white-space: normal !important;
    overflow-wrap: anywhere;
    word-break: break-word;
}
</STYLE>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
<DIV id=SubMenu><SPAN><A href="clear-logs.php">Clear Logs</A></SPAN></DIV>
</HEADER>
<DIV id="contentWrapper">
<DIV id="content">
<H1 class="title">Logs - Level <?php echo intval($level) . " / " . intval($show); ?></H1>
<form name="logs" id="logs" action="logs.php" method="post">
<DIV class="logFilterBox">
    <DIV class="logFilterRow">
        <label for="level">Minimum logging level to display:</label>
        <select name="level" id="level" onchange="this.form.submit()">
            <option value="1" <?php echo (($level == 1) ? " SELECTED" : ""); ?>>Level 1 - Default</option>
            <option value="2" <?php echo (($level == 2) ? " SELECTED" : ""); ?>>Level 2 - More Information</option>
            <option value="3" <?php echo (($level == 3) ? " SELECTED" : ""); ?>>Level 3 - Debug</option>
            <option value="4" <?php echo (($level == 4) ? " SELECTED" : ""); ?>>Level 4 - All</option>
        </select>
    </DIV>
    <DIV class="logFilterRow">
        <label for="user_id">User:</label>
        <select name="user_id" id="user_id" onchange="this.form.submit()">
            <option value="-1" <?php echo (($userFilter == -1) ? " SELECTED" : ""); ?>>All Users</option>
            <option value="0" <?php echo (($userFilter == 0) ? " SELECTED" : ""); ?>>System / Unknown</option>
<?php
foreach($users as $user)
{
    $id = intval($user['id']);
    echo "            <option value=\"" . $id . "\"" . (($userFilter == $id) ? " SELECTED" : "") . ">" . DupHtml($user['name']) . "</option>\n";
}
?>
        </select>
    </DIV>
    <DIV class="logFilterRow">
        <label for="show">Number of Logs to Show:</label>
        <select name="show" id="show" onchange="this.form.submit()">
            <option value="200" <?php echo (($show == 200) ? " SELECTED" : ""); ?>>200</option>
            <option value="500" <?php echo (($show == 500) ? " SELECTED" : ""); ?>>500</option>
            <option value="2000" <?php echo (($show == 2000) ? " SELECTED" : ""); ?>>2000</option>
            <option value="5000" <?php echo (($show == 5000) ? " SELECTED" : ""); ?>>5000</option>
        </select>
    </DIV>
</DIV>
</form>
<TABLE id="historyTable" class="tablesorter" border="0" cellSpacing="1" cellPadding="0">
<THEAD>
<TR>
<TH class="nowrap">Num (id)</TH>
<TH class="nowrap">Time</TH>
<TH class="nowrap">User</TH>
<TH class="nowrap">Type</TH>
<TH>Info</TH>
<TH class="nowrap">Function</TH>
<TH class="nowrap">File (Line)</TH>
</TR>
</THEAD>
<TBODY>
<?php
$whereParts = array("l.type<=:level");

if($userFilter >= 0)
    $whereParts[] = "l.user_id=:user_id";

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

    if(count($logs) == 0)
    {
        echo "<TR><TD colspan=\"7\" align=\"center\"><b>No log entries found.</b></TD></TR>\n";
    }

    $num = 1;

    foreach($logs as $row)
    {
        $typeData = LogTypeText(isset($row['type']) ? intval($row['type']) : 0);
        $id = isset($row['id']) ? intval($row['id']) : 0;
        $stamp = isset($row['stamp']) ? $row['stamp'] : "";
        $userId = isset($row['user_id']) ? intval($row['user_id']) : 0;
        $userName = isset($row['user_name']) && $row['user_name'] != "" ? $row['user_name'] : (($userId == 0) ? "System / Unknown" : "User #" . $userId);
        $str = isset($row['str']) ? FromSafeSQLValue($row['str']) : "";
        $function = isset($row['function']) ? $row['function'] : "";
        $page = isset($row['page']) ? $row['page'] : "";
        $line = isset($row['line']) ? intval($row['line']) : 0;

        echo "<TR>\n";
        echo " <TD class=\"nowrap\"><span title=\"id: " . number_format($id) . "\">" . number_format($num) . "</span></TD>\n";
        echo " <TD class=\"nowrap\">" . DupHtml($stamp) . "</TD>\n";
        echo " <TD class=\"nowrap\"><span title=\"User ID: " . $userId . "\">" . DupHtml($userName) . "</span></TD>\n";
        echo " <TD class=\"nowrap\"><B><font color=\"" . DupHtml($typeData[1]) . "\">" . DupHtml($typeData[0]) . "</font></B></TD>\n";
        echo " <TD>" . DupHtml($str) . "</TD>\n";
        echo " <TD class=\"nowrap\">" . DupHtml($function) . "</TD>\n";
        echo " <TD class=\"nowrap\"><B><font color=\"brown\"><span title=\"Line: " . $line . "\">" . DupHtml($page) . "</span></font></B></TD>\n";
        echo "</TR>\n";
        $num++;
    }
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>
</TBODY>
</TABLE>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
</HTML>
