<?php
include("dup.php");

$dup = new dup($profile);

$profileId = intval($profile);
$profilePath = isset($dup->Profile['path']) ? $dup->Profile['path'] : "";

LogStr("View Profile '" . $profilePath . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function ProfileViewHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DropProfile($full, $part)
{
    if($part != "" && strpos($full, $part) === 0)
        return trim(substr($full, strlen($part)), "//");

    return $full;
}

function ChopName($name)
{
    global $dup;

    if($dup->Settings['shortfilenames'] != 0 && strlen($name) > $dup->Settings['shortfilenames'])
    {
        if(strlen($name) > $dup->Settings['shortfilenames'])
            return "..." . substr($name, strlen($name) - $dup->Settings['shortfilenames']);
    }

    return $name;
}

function JavaScriptSafeString($value)
{
    return str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", "", ""), (string)$value);
}

function BuildIgnoreFolderList($folders)
{
    $out = array();
    $parts = explode(",", (string)$folders);

    foreach($parts as $part)
    {
        $part = strtolower(trim(trim($part), "/\\"));

        if($part != "" && !in_array($part, $out))
            $out[] = $part;
    }

    return $out;
}

function IsIgnoredFolderFile($file, $ignoreFolders)
{
    if(!is_array($ignoreFolders) || count($ignoreFolders) == 0)
        return false;

    $parts = explode("/", trim(str_replace("\\", "/", (string)$file), "/"));

    foreach($parts as $part)
    {
        $part = strtolower(trim($part));

        if($part != "" && in_array($part, $ignoreFolders))
            return true;
    }

    return false;
}

function sortByValue($array, $sortOn, $sortOn2, $direction = SORT_ASC)
{
    $sort = array();
    $sort2 = array();

    foreach($array as $key => $value)
    {
        $sort[$key] = isset($value[$sortOn]) ? $value[$sortOn] : "";
        $sort2[$key] = isset($value[$sortOn2]) ? $value[$sortOn2] : "";
    }

    array_multisort($sort, $direction, $sort2, $direction, $array);
    return $array;
}

$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

if($page < 1)
    $page = 1;

$tstart = $dup->GetPage($page);

if(isset($tstart["start"]) && strlen($tstart["start"]) > 0)
    $start = intval($tstart["start"]);
else
{
    $tstart = $dup->GetPage($page - 1);

    if(isset($tstart["start"]) && strlen($tstart["start"]) > 0 && isset($tstart["end"]) && strlen($tstart["end"]) > 0)
        $start = intval($tstart["end"]);
    else
        $start = 0;
}

if($start < 0)
    $start = 0;

$DUPS = $dup->GetDups($profileId, $start);
$ignoreFolders = BuildIgnoreFolderList(isset($dup->Settings['ifolders']) ? $dup->Settings['ifolders'] : "");
$movedFolders = array(isset($dup->Profile['mpath']) ? $dup->Profile['mpath'] : "");
$displayGroups = array();
$tsize = 0;
$dupsize = 0;
$TotalItems = 0;
$parent = $start + 1;
$groupNum = 1;

foreach($DUPS as $key => $value)
{
    if($key == "stats")
        continue;

    if(!is_array($value))
        continue;

    $value = sortByValue($value, 'link', 'file');
    $filtered = array();

    foreach($value as $row)
    {
        if(!isset($row['file']))
            continue;

        if(IsIgnoredFolderFile($row['file'], $ignoreFolders))
            continue;

        if($dup->Settings['displaymoved'] == 0 && IsMovedFile($row['file'], $movedFolders))
            continue;

        $filtered[] = $row;
    }

    if(count($filtered) < 2)
        continue;

    $displayGroups[] = array(
        "group" => $groupNum,
        "parent" => $parent,
        "rows" => $filtered
    );

    $parent++;
    $groupNum++;
}

$displayedDupGroups = count($displayGroups);
$endPageMarker = max($start, $parent - 1);
$dup->UpdatePages($page, $start, $endPageMarker);
$matchedOn = (isset($dup->Profile['crc32']) && intval($dup->Profile['crc32']) == 0) ? "Size" : "CRC32";
$profileDups = isset($dup->Profile['dups']) ? intval($dup->Profile['dups']) : 0;
$hasNext = ($endPageMarker < $profileDups);
?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - View Profile</TITLE>
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
<SCRIPT type="text/javascript" src="files/tableClick.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" CHARSET="utf-8">top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />';</SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
<SCRIPT type="text/javascript">
$(document).ready(function(){
    $("#NAVhome").addClass("active");
    $("#showListTable:has(tbody tr)").tablesorter({widgets:['zebra'], headers:{6:{sorter:false}}});
});
</SCRIPT>
<STYLE>
#showListTable{width:100%;table-layout:fixed;}
#showListTable th:nth-child(1){width:70px;}
#showListTable th:nth-child(2){width:120px;}
#showListTable th:nth-child(4){width:105px;}
#showListTable th:nth-child(5){width:155px;}
#showListTable th:nth-child(6){width:95px;}
#showListTable th:nth-child(7){width:165px;}
#showListTable td:nth-child(3){white-space:normal!important;overflow-wrap:anywhere;word-break:break-word;}
.profileFooterCell{color:black;background-color:#dfdacf;border:1pt solid black;}
</STYLE>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
<DIV id=SubMenu>
<SPAN>
<A href="profile-add.php">Add Profile</A> |
<A href="profile-settings.php?profile=<?php echo $profileId; ?>">Profile Settings</A> |
<A href="<?php echo "javascript:ScanProfile('" . $profileId . "', 'Scan', '" . SafeJavaValue($profilePath) . "');"; ?>">Rescan Profile</A> |
<A href="<?php echo "javascript:DeleteProfile('" . $profileId . "', 'Delete', '" . SafeJavaValue($profilePath) . "');"; ?>">Delete Profile</A>
</SPAN>
</DIV>
</HEADER>
<DIV id=contentWrapper>
<DIV id=content>
<H1 class=title>Files for <B><?php echo ProfileViewHtml($profilePath); ?></B></H1>
<form id=dups name=dups method=post action="action-files.php">
<input type="hidden" name="profile" value="<?php echo $profileId; ?>">
<input type="hidden" name="from" value="profile-view.php?profile=<?php echo $profileId; ?>">
<input type=hidden id=act name=act value=none>
<input type=hidden id=sel name=sel value=1>
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
foreach($displayGroups as $displayGroup)
{
    $rows = $displayGroup['rows'];
    $groupId = "dupgroup" . intval($displayGroup['group']);
    $parentNumber = intval($displayGroup['parent']);
    $parentClass = (($parentNumber % 2 == 0) ? "subparent" : "mainparent");
    $childNum = 1;

    foreach($rows as $k => $v)
    {
        $date = new DateTime("@" . intval($v['fdate']));
        $icon = (isset($v['link']) && strlen($v['link']) > 0) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
        $file = DropProfile($v['file'], $profilePath);
        $fileTitle = ProfileViewHtml($v['file']);
        $fileShort = ProfileViewHtml(ChopName($file));
        $rowId = intval($v['id']);
        $rowClass = $parentClass;
        $displayNum = $parentNumber;
        $count = " &nbsp;&nbsp (" . (count($rows) - 1) . ")";
        $b1 = "<b>";
        $b2 = "</b>";
        $rowStyle = "";
        $rowData = "";

        if($k > 0)
        {
            $rowClass = (($childNum % 2 == 0) ? "child" : "subchild");
            $displayNum = $childNum;
            $count = "";
            $b1 = "";
            $b2 = "";
            $rowStyle = " style=\"display:none\"";
            $rowData = " data-dupgroup=\"" . $groupId . "\"";
            $dupsize += intval($v['size']);
            $childNum++;
        }

        $tsize += intval($v['size']);
        $TotalItems++;

        echo "<TR class=\"" . $rowClass . "\"" . $rowData . $rowStyle . ">\n";
        echo " <TD class=\"" . $rowClass . "\" align=\"right\" nowrap>" . $b1 . $displayNum . $b2 . $count . "</TD>\n";

        if($dup->Settings['download'] == 1)
            echo " <TD class=\"" . $rowClass . "\" nowrap><span title=\"" . (($icon == "<I class=icon-file></I>") ? "File" : "Symlink") . "\">" . $icon . "</span> &nbsp; <input type=checkbox id=\"" . (($k == 0) ? "pri" : "sec") . "\" name=\"files[]\" value=\"" . $rowId . "\"" . (($k == 0) ? "" : " CHECKED") . "> <a id=\"Download\" href=\"javascript:Download('" . $rowId . "');\" alt=\"Download\" title=\"Download\">" . $rowId . "</a></TD>\n";
        else
            echo " <TD class=\"" . $rowClass . "\" nowrap><span title=\"" . (($icon == "<I class=icon-file></I>") ? "File" : "Symlink") . "\">" . $icon . "</span> &nbsp; <input type=checkbox id=\"" . (($k == 0) ? "pri" : "sec") . "\" name=\"files[]\" value=\"" . $rowId . "\"" . (($k == 0) ? "" : " CHECKED") . "> " . $rowId . "</TD>\n";

        if($k == 0)
            echo " <TD class=tvShow><a title=\"" . $fileTitle . "\" alt=\"" . $fileTitle . "\" id=\"Expand\" onclick=\"displayRow('" . $groupId . "');\" style=\"cursor:hand; cursor:pointer\"><B>" . $fileShort . "</B></a></TD>\n";
        else
            echo " <TD class=tvShowchild><span title=\"" . $fileTitle . "\">" . $fileShort . "</span></TD>\n";

        echo " <TD class=\"" . $rowClass . "\" align=right nowrap><span title=\"" . number_format(intval($v['size'])) . " bytes\">" . $dup->format_bytes(intval($v['size'])) . "</span></TD>\n";
        echo " <TD class=\"" . $rowClass . "\" align=center nowrap><span title=\"" . intval($v['fdate']) . "\">" . $date->format('Y-m-d H:i:s') . "</span></TD>\n";
        echo " <TD class=\"" . $rowClass . "\" align=center nowrap>" . (($v['crc32'] == "0 Filled") ? "<font color=red><b>" . ProfileViewHtml($v['crc32']) . "</b></font>" : ProfileViewHtml($v['crc32'])) . "</TD>\n";
        echo " <TD class=\"" . $rowClass . "\" nowrap><center><a href=\"javascript:DeleteFile('" . $profileId . "', '" . $rowId . "', '" . JavaScriptSafeString($v['file']) . "');\"><I class=icon-trash title=\"Delete '" . ProfileViewHtml($file) . "'\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('Rename', " . $rowId . ");\"><I class=icon-edit title=\"Rename '" . ProfileViewHtml($file) . "'\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('Move', " . $rowId . ");\"><I class=icon-share title=\"Move '" . ProfileViewHtml($file) . "'\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('Link', " . $rowId . ");\"><I class=icon-magnet title=\"Link '" . ProfileViewHtml($file) . "'\"></I></a></center></TD>\n";
        echo "</TR>\n";
    }
}
?>
</TBODY>
<TFOOT>
<TR>
<TH class="profileFooterCell" align=middle><a href="javascript:displayAll()"><font color=Black><span id="expandText">Expand</span></font></a></TH>
<TH class="profileFooterCell" align=middle><a href="javascript:checkedAll('dups')"><font color=Black>Inverse</font></a></TH>
<TH class="profileFooterCell" align=left>Possible listed amount of data to clean up :</TH>
<TH class="profileFooterCell" nowrap><?php echo $dup->format_bytes($dupsize); ?></TH>
<TH class="profileFooterCell">Total Profile Dups:</TH>
<TH class="profileFooterCell"><span title="Profile Dups"><?php echo number_format($profileDups); ?></span></TH>
<TH class="profileFooterCell" nowrap><center><a href="javascript:handleClick('dups', 'Delete')"><I class=icon-trash title="Delete Selected"></I></a> / <a href="javascript:handleClick('dups', 'Rename')"><I class=icon-edit title="Rename Selected"></I></a> / <a href="javascript:handleClick('dups', 'Move')"><I class=icon-share title="Move Selected"></I></a> / <a href="javascript:handleClick('dups', 'Link')"><I class=icon-magnet title="Link Selected"></I></a></center></TH>
</TR>
<TR>
<TH class="profileFooterCell" nowrap><?php echo (($page > 1) ? "<a title=\"Previous\" href=\"profile-view.php?profile=" . $profileId . "&page=" . ($page - 1) . "\"><I class=icon-chevron-left title=\"Previous\"></I></a>" : ""); ?></TH>
<TH class="profileFooterCell"><span title="Displayed Dups"><?php echo number_format($displayedDupGroups); ?></span></TH>
<TH class="profileFooterCell">Total listed data size:</TH>
<TH class="profileFooterCell" nowrap><?php echo $dup->format_bytes($tsize); ?></TH>
<TH class="profileFooterCell">Matched on: <?php echo ProfileViewHtml($matchedOn); ?></TH>
<TH class="profileFooterCell"><span title="Displayed Vars"><?php echo number_format($TotalItems); ?></span></TH>
<TH class="profileFooterCell" nowrap><?php echo ($hasNext ? "<a title=\"Next\" href=\"profile-view.php?profile=" . $profileId . "&page=" . ($page + 1) . "\"><I class=icon-chevron-right title=\"Next\"></I></a>" : ""); ?></TH>
</TR>
</TFOOT>
</TABLE>
</form>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
<SCRIPT type="text/javascript">
var allExpanded = false;

function setExpandText()
{
    var elem = document.getElementById('expandText');

    if(elem != null)
        elem.innerHTML = allExpanded ? 'Collapse' : 'Expand';
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

    setExpandText();
}

var checked = true;
function checkedAll(val)
{
    var type = document.getElementById('sel').value;
    var form = document.getElementById(val);

    if(type == 1)
    {
        for(var i = 0; i < form.elements.length; i++)
        {
            if(form.elements[i].id == 'pri')
                form.elements[i].checked = checked;
        }

        document.getElementById('sel').value = 2;
    }
    else if(type == 2)
    {
        checked = !checked;

        for(var i = 0; i < form.elements.length; i++)
        {
            if(form.elements[i].id == 'sec')
                form.elements[i].checked = checked;
        }

        document.getElementById('sel').value = 3;
    }
    else if(type == 3)
    {
        for(var i = 0; i < form.elements.length; i++)
        {
            if(form.elements[i].id == 'pri')
                form.elements[i].checked = checked;
        }

        document.getElementById('sel').value = 4;
    }
    else if(type == 4)
    {
        checked = !checked;

        for(var i = 0; i < form.elements.length; i++)
        {
            if(form.elements[i].id == 'sec')
                form.elements[i].checked = checked;
        }

        document.getElementById('sel').value = 1;
    }
}

function SingleFileAction(act, fileid)
{
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "action-files.php";

    var fields = {
        profile: "<?php echo $profileId; ?>",
        from: "profile-view.php?profile=<?php echo $profileId; ?>",
        act: act
    };

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

function DeleteFile(profile, fileid, file)
{
    if(confirm("Are you sure you want to Delete...\n\n" + file))
        SingleFileAction("Delete", fileid);
}

function handleClick(form, act)
{
    if(confirm('Are you sure you want to \' + act + '\' all the selected files?'))
    {
        document.getElementById('act').value = act;
        document.getElementById(form).submit();
    }
}

function DeleteProfile(id, act, title)
{
    if(act == "Delete")
    {
        if(confirm("Are you sure you want to delete profile:\n" + title + "\n\nThis cannot be undone."))
        {
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "delete-profile.php";

            var input = document.createElement("input");
            input.type = "hidden";
            input.name = "profile";
            input.value = id;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    else
        window.location.href = "profile-settings.php?profile=" + encodeURIComponent(id);
}

function ScanProfile(id, act, title)
{
    if(act == 'Scan')
    {
        if(confirm('Are you sure you want to \' + act + '\' profile \n\n\'' + title + '\' ?\n\nThis may take some time but will run in the background.'))
            window.open('scan-files.php?profile=' + id, '_self');
    }
}

function Download(file)
{
    window.open("download-files.php?profile=<?php echo $profileId; ?>&file=" + encodeURIComponent(file), "Download File", "height=50,width=100,menubar=no,resizable=no,scrollbars=no,titlebar=no,toolbar=no");
}
</SCRIPT>
</HTML>
