<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Dup Search", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DupInt($value)
{
    return intval($value);
}

function HighlightSearch($value, $search)
{
    $escaped = DupHtml($value);

    if(trim((string)$search) == "")
        return $escaped;

    return preg_replace("/(" . preg_quote(DupHtml($search), "/") . ")/i", "<b>$1</b>", $escaped);
}

function JavaScriptSafeString($value)
{
    return str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", "", ""), (string)$value);
}

function DropProfile($full, $part)
{
    if($part != "" && strpos($full, $part) === 0)
        return trim(substr($full, strlen($part), strlen($full)), "//");

    return $full;
}

function ChopName($name)
{
    global $dup;

    if($dup->Settings['shortfilenames'] != 0 && strlen($name) > $dup->Settings['shortfilenames'])
        return "..." . substr($name, (strlen($name) - $dup->Settings['shortfilenames']), strlen($name));

    return $name;
}

function PrintProfileHeader($profileId, $path)
{
    echo "<TR class=\"nowrap tablesorter-infoOnly\">\n";
    echo " <td colspan=7><b><a href=\"profile-view.php?profile=" . intval($profileId) . "\">" . DupHtml($path) . "</a></b></td>\n";
    echo "</TR>\n";
}

function FetchDupGroups($profileId, $profileInfo, $search, $case, $start)
{
    global $dblink;
    global $dup;

    $groups = array();
    $type = intval($profileInfo['crc32']) == 0 ? "size" : "crc32";
    $whereParts = array("profile=:profile");
    $searchValue = $case ? $search : strtolower($search);

    if(strlen($search) > 0)
        $whereParts[] = ($case ? "file LIKE :search" : "LOWER(file) LIKE :search");

    if(intval($dup->Settings['displaymoved']) == 0 && isset($profileInfo['mpath']) && trim($profileInfo['mpath']) != "")
        $whereParts[] = "file NOT LIKE :mpath";

    if($type == "size")
        $query = "SELECT size AS dupkey, COUNT(size) AS cnt FROM files WHERE " . implode(" AND ", $whereParts) . " GROUP BY size HAVING COUNT(size)>1 ORDER BY size ASC LIMIT :start, 500;";
    else
        $query = "SELECT crc32 AS dupkey, COUNT(crc32) AS cnt FROM files WHERE crc32!='unknown' AND crc32!='0 Filled' AND crc32!='' AND " . implode(" AND ", $whereParts) . " GROUP BY crc32 HAVING COUNT(crc32)>1 ORDER BY crc32 ASC LIMIT :start, 500;";

    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", intval($profileId), PDO::PARAM_INT);
    $sdt->bindValue(":start", intval($start), PDO::PARAM_INT);

    if(strlen($search) > 0)
        $sdt->bindValue(":search", "%" . $searchValue . "%", PDO::PARAM_STR);

    if(intval($dup->Settings['displaymoved']) == 0 && isset($profileInfo['mpath']) && trim($profileInfo['mpath']) != "")
        $sdt->bindValue(":mpath", rtrim($profileInfo['mpath'], "/") . "/%", PDO::PARAM_STR);

    $sdt->execute();
    $dupKeys = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    foreach($dupKeys as $dupKeyRow)
    {
        $dupKey = isset($dupKeyRow['dupkey']) ? $dupKeyRow['dupkey'] : "";
        $rowWhere = array("profile=:profile");

        if($type == "size")
            $rowWhere[] = "size=:dupkey";
        else
            $rowWhere[] = "crc32=:dupkey";

        if(intval($dup->Settings['displaymoved']) == 0 && isset($profileInfo['mpath']) && trim($profileInfo['mpath']) != "")
            $rowWhere[] = "file NOT LIKE :mpath";

        $query = "SELECT id, file, size, fdate, crc32, profile, link FROM files WHERE " . implode(" AND ", $rowWhere) . " ORDER BY link ASC, file ASC;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt2 = $dblink->prepare($query);
        $sdt2->bindValue(":profile", intval($profileId), PDO::PARAM_INT);

        if($type == "size")
            $sdt2->bindValue(":dupkey", intval($dupKey), PDO::PARAM_INT);
        else
            $sdt2->bindValue(":dupkey", $dupKey, PDO::PARAM_STR);

        if(intval($dup->Settings['displaymoved']) == 0 && isset($profileInfo['mpath']) && trim($profileInfo['mpath']) != "")
            $sdt2->bindValue(":mpath", rtrim($profileInfo['mpath'], "/") . "/%", PDO::PARAM_STR);

        $sdt2->execute();
        $rows = $sdt2->fetchAll(PDO::FETCH_ASSOC);
        $sdt2->closeCursor();

        if(count($rows) > 1)
            $groups[] = array("type" => $type, "key" => $dupKey, "rows" => $rows);
    }

    return $groups;
}

$case = false;
$search = "";

if(isset($_POST["search"]))
    $search = trim($_POST["search"]);
else if(isset($_GET["search"]))
    $search = trim($_GET["search"]);

$search = str_replace(array("\r", "\n", "\0"), "", $search);
$profile = isset($_REQUEST["profile"]) ? intval($_REQUEST["profile"]) : 0;
$case = isset($_REQUEST["case"]) && intval($_REQUEST["case"]) == 1;
$start = isset($_REQUEST["start"]) ? intval($_REQUEST["start"]) : 0;

if($start < 0)
    $start = 0;
?>
<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Dup Search</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]><SCRIPT SRC="files/html5.js"></SCRIPT><![endif]-->
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <SCRIPT SRC="files/script.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">sbRoot = '/'; top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />';</SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
  <SCRIPT type="text/javascript">
    $(document).ready(function(){
        $("#NAVsearch").addClass("active");
        $("#showListTable:has(tbody tr)").tablesorter({ widgets: ['saveSort', 'zebra', 'stickyHeaders'], cssChildRow: 'tablesorter-childRow', headers: { 6: { sorter: false } } });
    });
  </SCRIPT>
 </HEAD>
 <BODY>
  <?php echo PageHeader(); ?>
  </HEADER>
<DIV id=contentWrapper>
 <DIV id=content>
 <form id=searchform name=searchform method=post action=search-dup.php>
 <H1 class=title>Dup Search</H1>
 <H1 class=title>Search : <input type="text" name="search" size="30" value="<?php echo DupHtml($search); ?>"> &nbsp; Scope :
  <?php
    $Profiles = $dup->GetProfiles("path");
    echo "<select name=\"profile\" id=\"profile\" class=\"input-medium\" style=\"width: 270px\">\n";
    echo " <option value=\"0\"" . (($profile == 0) ? " SELECTED" : "") . "> Global - All Profiles</option>\n";

    foreach($Profiles as $key => $val)
    {
        $id = DupInt($val['id']);
        $selected = ($id == $profile) ? " SELECTED" : "";
        echo " <option value=\"" . $id . "\"" . $selected . "> (" . $id . ") " . DupHtml($val['path']) . " </option>\n";
    }

    echo "</select>\n";
    echo "&nbsp;Case Sensitive : <input type=checkbox name=case value=1" . (($case == true) ? " CHECKED" : "") . ">";
 ?>
  &nbsp;<input type=Submit value=Search></H1>
 </form>

<form id=search name=search method=post action=action-files.php>
<input type="hidden" name="profile" value="<?php echo DupInt($profile); ?>">
<input type="hidden" id="act" name="act" value="none">
<TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
<THEAD>
  <TR>
    <TH class=nowrap>Num</TH>
    <TH>id</TH>
    <TH>File</TH>
    <TH>Size</TH>
    <TH>Modified Date</TH>
    <TH>CRC32</TH>
    <TH>Options</TH>
   </TR>
</THEAD>
<TBODY>
<?php
$num = 1;
$groupNum = 1;
$tsize = 0;
$dupsize = 0;
$totalRows = 0;
$totalGroups = 0;
$tbodyNum = 1;

if(strlen($search) > 0)
{
    try
    {
        $ProfileSettings = $dup->GetProfiles('path');

        foreach($ProfileSettings as $profileKey => $profileInfo)
        {
            $profileId = intval($profileInfo['id']);

            if($profile != 0 && $profileId != $profile)
                continue;

            PrintProfileHeader($profileId, isset($profileInfo['path']) ? $profileInfo['path'] : "");
            $groups = FetchDupGroups($profileId, $profileInfo, $search, $case, $start);

            foreach($groups as $group)
            {
                $rows = $group['rows'];
                $class = (($groupNum % 2 == 0) ? "subparent" : "mainparent");
                $childNum = 1;
                $dupGroupId = "dupgroup" . $tbodyNum;

                foreach($rows as $k => $row)
                {
                    $date = new DateTime("@" . intval($row['fdate']));
                    $icon = (isset($row['link']) && strlen($row['link']) > 0) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
                    $rowClass = $class;
                    $rowAttr = "";
                    $sorterChildClass = "";

                    if($k > 0)
                    {
                        $rowClass = ($childNum % 2 == 0) ? "subchild" : "child";
                        $rowAttr = " data-dupgroup=\"" . $dupGroupId . "\" style=\"display:none\"";
                        $sorterChildClass = " tablesorter-childRow";
                    }

                    $file = DropProfile($row['file'], isset($profileInfo['path']) ? $profileInfo['path'] : "");
                    $displayNum = ($k == 0) ? $groupNum : $childNum;
                    $countText = ($k == 0) ? " &nbsp;&nbsp (" . (count($rows) - 1) . ")" : "";
                    $b1 = ($k == 0) ? "<b>" : "";
                    $b2 = ($k == 0) ? "</b>" : "";
                    $rowId = intval($row['id']);
                    $rowProfile = intval($row['profile']);
                    $fileHtml = DupHtml($row['file']);
                    $shortFile = ChopName($file);
                    $fileJs = JavaScriptSafeString($row['file']);
                    $dropTitle = DupHtml($file);

                    echo "<TR class=\"" . $rowClass . $sorterChildClass . "\"" . $rowAttr . ">\n";
                    echo " <TD class=\"" . $rowClass . "\" align=\"right\" nowrap>" . $b1 . $displayNum . $b2 . $countText . "</TD>\n";
                    echo " <TD class=\"" . $rowClass . "\" nowrap><span title=\"" . (($icon == "<I class=icon-file></I>") ? "File" : "Symlink") . "\">" . $icon . "</span> &nbsp; <input type=checkbox id=\"" . (($k == 0) ? "pri" : "sec") . "\" name=\"files[]\" value=\"" . $rowId . "\"" . (($k == 0) ? "" : " CHECKED") . "> " . $rowId . "</TD>\n";

                    if($k == 0)
                        echo " <TD class=\"tvShow\"><a title=\"" . $fileHtml . "\" id=\"Expand\" onclick=\"displayRow('" . $dupGroupId . "');\" style=\"cursor:hand; cursor:pointer\"><B>" . HighlightSearch($shortFile, $search) . "</B></a></TD>\n";
                    else
                        echo " <TD class=\"tvShowchild\"><span title=\"" . $fileHtml . "\">" . HighlightSearch($shortFile, $search) . "</span></TD>\n";

                    echo " <TD class=\"" . $rowClass . "\" align=right nowrap><span title=\"" . number_format(intval($row['size'])) . " bytes\">" . $dup->format_bytes(intval($row['size'])) . "</span></TD>\n";
                    echo " <TD class=\"" . $rowClass . "\" align=center nowrap><span title=\"" . intval($row['fdate']) . "\">" . $date->format('Y-m-d H:i:s') . "</span></TD>\n";
                    echo " <TD class=\"" . $rowClass . "\" align=center nowrap>" . DupHtml($row['crc32']) . "</TD>\n";
                    echo " <TD class=\"" . $rowClass . "\" nowrap><center>";
                    echo "<a href=\"view-file.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-eye-open title=\"View '" . $dropTitle . "'\"></I></a> <b>/";
                    echo " <a href=\"download-files.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-download-alt title=\"Download '" . $dropTitle . "'\"></I></a> /";
                    echo " <a href=\"javascript:DeleteFile('" . $rowProfile . "', '" . $rowId . "', '" . $fileJs . "');\"><I class=icon-trash title=\"Delete '" . $dropTitle . "'\"></I></a> /";
                    echo " <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Rename', '" . $rowId . "');\"><I class=icon-edit title=\"Rename '" . $dropTitle . "'\"></I></a> /";
                    echo " <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Move', '" . $rowId . "');\"><I class=icon-share title=\"Move '" . $dropTitle . "'\"></I></a></b>";
                    echo "</center></TD>\n";
                    echo "</TR>\n";

                    if($k > 0)
                    {
                        $dupsize += intval($row['size']);
                        $childNum++;
                    }

                    $tsize += intval($row['size']);
                    $totalRows++;
                }

                $groupNum++;
                $totalGroups++;
                $tbodyNum++;
            }
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }
}
?>
</TBODY>
<TFOOT>
<TR>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><input id="expandButton" type=Button Value=Expand OnClick="displayAll()"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><input type=Button Value=Inverse OnClick="checkedAll('search')"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Possible listed amount of data to clean up :</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo $dup->format_bytes($dupsize); ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Displayed Dup Groups:</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo $totalGroups; ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><center><?php echo "<a href=\"javascript:handleClick('search', 'Delete')\"><I class=icon-trash title=\"Delete Selected\"></I></a> / <a href=\"javascript:handleClick('search', 'Rename')\"><I class=icon-edit title=\"Rename Selected\"></I></a> / <a href=\"javascript:handleClick('search', 'Move')\"><I class=icon-share title=\"Move Selected\"></I></a>"; ?></center></TH>
</TR>
<TR>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Total listed data size:</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo $dup->format_bytes($tsize); ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Displayed Items:</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo $totalRows; ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"></TH>
</TR>
</TFOOT>
</TABLE>
</form>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
<script type="text/javascript">
var allExpanded = false;

function setExpandButtonText()
{
    var button = document.getElementById('expandButton');

    if(button != null)
        button.value = allExpanded ? 'Collapse' : 'Expand';
}

function displayRow(num)
{
    var rows = document.querySelectorAll('#showListTable tr[data-dupgroup="' + num + '"]');

    for(var i = 0; i < rows.length; i++)
        rows[i].style.display = (rows[i].style.display == '') ? 'none' : '';
}

function displayAll()
{
    allExpanded = !allExpanded;
    var rows = document.querySelectorAll('#showListTable tr[data-dupgroup]');

    for(var i = 0; i < rows.length; i++)
        rows[i].style.display = allExpanded ? '' : 'none';

    setExpandButtonText();
}

function checkedAll(val)
{
    var inputs = document.querySelectorAll('#' + val + ' input[type="checkbox"][name="files[]"]');

    for(var i = 0; i < inputs.length; i++)
        inputs[i].checked = !inputs[i].checked;
}

function SingleFileAction(profile, act, fileid)
{
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "action-files.php";
    var fields = { profile: profile, act: act };

    for(var key in fields)
    {
        if(fields.hasOwnProperty(key))
        {
            var input = document.createElement("input");
            input.type = "hidden";
            input.name = key;
            input.value = fields[key];
            form.appendChild(input);
        }
    }

    var fileInput = document.createElement("input");
    fileInput.type = "hidden";
    fileInput.name = "files[]";
    fileInput.value = fileid;
    form.appendChild(fileInput);
    document.body.appendChild(form);
    form.submit();
}
function DeleteFile(profile, fid, file)
{
    if(confirm("Are you sure you want to Delete\n\n" + file + " ?"))
        SingleFileAction(profile, "Delete", fid);
}
function handleClick(form, act)
{
    if(confirm("Are you sure you want to '" + act + "' all the selected files?"))
    {
        document.getElementById('act').value = act;
        document.getElementById(form).submit();
    }
}
</script>
</HTML>
