<?php
include("dup.php");

$dup = new dup($profile);
LogStr("Renaming '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "global") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function JavaScriptSafeString($value)
{
    return str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", "", ""), (string)$value);
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
        return "..." . substr($name, strlen($name) - $dup->Settings['shortfilenames']);

    return $name;
}

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

function NameOnly($name)
{
    $nameParts = pathinfo($name);

    if(isset($nameParts['basename']))
        return $nameParts['basename'];

    return basename($name);
}

function CleanNameOnly($name)
{
    global $dup;

    $nameParts = pathinfo($name);
    $basename = isset($nameParts['basename']) ? $nameParts['basename'] : basename($name);

    if($dup->Settings['namecleanup'] == 1)
        return CleanUpName($basename);

    return CleanUpNameFiltersOnly($basename);
}

function HighlightFilterText($value, $filters)
{
    $escaped = DupHtml($value);
    $parts = explode(",", (string)$filters);

    foreach($parts as $part)
    {
        $part = trim($part);

        if($part == "")
            continue;

        $escapedPart = preg_quote(DupHtml($part), "/");
        $escaped = preg_replace("/(" . $escapedPart . ")/i", "<mark>$1</mark>", $escaped);
    }

    return $escaped;
}

function ProfileHeader($id, $path)
{
    echo "<TR class=\"nowrap tablesorter-infoOnly\">\n";
    echo " <TD colspan=6><b><a href=\"profile-view.php?profile=" . intval($id) . "\">" . DupHtml($path) . "</a></b></TD>\n";
    echo "</TR>\n";
}

function PagerHtml($filters, $profileId, $dupsOnly, $start, $pageLimit, $shown, $totalFound, $submitted)
{
    if(!$submitted)
        return "<div class=\"searchPager\"><span>Click Search to display rename candidates.</span></div>";

    if($totalFound <= 0)
        return "<div class=\"searchPager\"><span>No rename candidates found.</span></div>";

    $base = "renaming.php?submitted=true&filters=" . urlencode($filters) . "&profile=" . intval($profileId) . ($dupsOnly ? "&dups=1" : "");
    $rangeStart = $start + 1;
    $rangeEnd = $start + $shown;
    $html = "<div class=\"searchPager\">";

    if($start > 0)
        $html .= "<a class=\"btn\" href=\"" . $base . "&start=" . max(0, $start - $pageLimit) . "\">Previous</a>";
    else
        $html .= "<span class=\"btn disabled\">Previous</span>";

    $html .= " <span class=\"searchPagerText\">Showing " . number_format($rangeStart) . "-" . number_format($rangeEnd) . " of " . number_format($totalFound) . " rename candidates</span> ";

    if(($start + $shown) < $totalFound)
        $html .= "<a class=\"btn\" href=\"" . $base . "&start=" . ($start + $pageLimit) . "\">Next</a>";
    else
        $html .= "<span class=\"btn disabled\">Next</span>";

    return $html . "</div>";
}

function LoadRenameCandidatesForProfile($profileInfo, $dupsOnly)
{
    global $dblink;
    global $dup;
    global $filters;

    $profileId = intval($profileInfo['id']);
    $rows = array();
    $whereParts = array("f.profile=:profile");

    if($dupsOnly)
        $whereParts[] = "EXISTS (SELECT 1 FROM files f2 INNER JOIN profiles p2 ON p2.id=f2.profile WHERE f2.profile=f.profile AND f2.id<>f.id AND ((p2.crc32=0 AND f2.size=f.size) OR (p2.crc32<>0 AND f.crc32<>'' AND f.crc32<>'0 Filled' AND f2.crc32=f.crc32)))";

    $query = "SELECT f.* FROM files f WHERE " . implode(" AND ", $whereParts) . " ORDER BY f.file ASC;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();
    $MovedFolders = $dup->GetMovedFolders($profileId);

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        if(!isset($row['file']))
            continue;

        if(IsMovedFile($row['file'], $MovedFolders) && $dup->Settings['displaymoved'] == 0)
            continue;

        $old = trim(NameOnly($row['file']));
        $new = trim(CleanNameOnly($row['file']));

        if($old == $new)
            continue;

        $row['_old_name'] = $old;
        $row['_new_name'] = $new;
        $rows[] = $row;
    }

    $sdt->closeCursor();
    return $rows;
}

$profileId = isset($_REQUEST['profile']) ? intval($_REQUEST['profile']) : intval($profile);
$filters = isset($dup->Settings['cleanupfilter']) ? $dup->Settings['cleanupfilter'] : "";

if(isset($_POST['filters']))
    $filters = trim($_POST['filters']);
else if(isset($_GET['filters']))
    $filters = trim($_GET['filters']);

$filters = str_replace(array("\r", "\n", "\0"), "", $filters);
$dupsOnly = isset($_REQUEST['dups']) && intval($_REQUEST['dups']) == 1;
$submitted = isset($_REQUEST['submitted']);
$start = isset($_REQUEST['start']) ? intval($_REQUEST['start']) : 0;

if($start < 0)
    $start = 0;

$pageLimit = 990;
$downloads = (isset($dup->Settings['download']) && intval($dup->Settings['download']) == 1);
$Profiles = $dup->GetProfiles("path");
$profileCandidateSets = array();
$totalFound = 0;

if($submitted)
{
    try
    {
        foreach($Profiles as $profileInfo)
        {
            $currentProfileId = intval($profileInfo['id']);

            if($profileId != 0 && $currentProfileId != $profileId)
                continue;

            $rows = LoadRenameCandidatesForProfile($profileInfo, $dupsOnly);
            $profileCandidateSets[] = array("profile" => $profileInfo, "rows" => $rows);
            $totalFound += count($rows);
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }
}

if($totalFound > 0 && $start >= $totalFound)
    $start = floor(($totalFound - 1) / $pageLimit) * $pageLimit;

$pageCandidateSets = array();
$seen = 0;
$shown = 0;
$remaining = $pageLimit;

foreach($profileCandidateSets as $profileSet)
{
    $profileRows = array();
    $profileCount = count($profileSet['rows']);

    if($profileCount > 0 && $seen + $profileCount > $start && $remaining > 0)
    {
        $offset = max(0, $start - $seen);
        $profileRows = array_slice($profileSet['rows'], $offset, $remaining);
        $shown += count($profileRows);
        $remaining -= count($profileRows);
    }

    $pageCandidateSets[] = array("profile" => $profileSet['profile'], "rows" => $profileRows);
    $seen += $profileCount;

    if($remaining <= 0)
        break;
}
?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - Renaming</TITLE>
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
<SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
<SCRIPT SRC="files/script.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" CHARSET="utf-8">sbRoot='/';top_image_html='<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />';</SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
<SCRIPT type="text/javascript">
$(document).ready(function(){
    $("#NAVrename").addClass("active");
    $("#showListTable:has(tbody tr)").tablesorter({widgets:['saveSort','zebra','stickyHeaders']});
});
</SCRIPT>
<STYLE>
mark{background:#fff3a3;color:inherit;padding:0 2px;border-radius:2px;}
#showListTable{width:100%;table-layout:fixed;}
#showListTable th:nth-child(1){width:55px;}
#showListTable th:nth-child(2){width:80px;}
#showListTable th:nth-child(3){width:65px;}
#showListTable th:nth-child(6){width:150px;}
#showListTable td:nth-child(4),#showListTable td:nth-child(5){white-space:normal!important;overflow-wrap:anywhere;word-break:break-word;}
.searchPager{margin:10px 0;padding:8px;background:#dfdacf;border:1px solid #999;border-radius:4px;text-align:center;}
.searchPager .btn{display:inline-block;margin:0 14px;}
.searchPagerText{display:inline-block;min-width:380px;text-align:center;}
.searchPager .disabled{opacity:.45;cursor:default;}
</STYLE>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
</HEADER>
<DIV id="contentWrapper">
<DIV id="content">
<form id="renaming" name="renaming" method="post" action="renaming.php">
<input type="hidden" name="submitted" id="submitted" value="true">
<H1 class="title">Mass Renaming
<?php
    echo "<select name=\"profile\" id=\"profile\" class=\"input-medium\" style=\"width:400px\">\n";
    echo " <option value=\"0\"" . (($profileId == 0) ? " SELECTED" : "") . "> Global - All Profiles</option>\n";

    foreach($Profiles as $val)
    {
        $id = intval($val['id']);
        echo " <option value=\"" . $id . "\"" . (($profileId == $id) ? " SELECTED" : "") . "> (" . $id . ") " . DupHtml($val['path']) . " </option>\n";
    }

    echo "</select>\n";
    echo " &nbsp;&nbsp; Filters: <input type=\"text\" name=\"filters\" id=\"filters\" value=\"" . DupHtml($filters) . "\" size=\"50\">";
    echo " &nbsp;&nbsp; Dups Only: <input type=\"checkbox\" name=\"dups\" value=\"1\"" . (($dupsOnly) ? " CHECKED" : "") . ">";
?>
&nbsp;<input type="Submit" value="Search"></H1>
</form>

<?php echo PagerHtml($filters, $profileId, $dupsOnly, $start, $pageLimit, $shown, $totalFound, $submitted); ?>

<form id="rename" name="rename" method="post" action="rename-renaming.php">
<input type="hidden" name="profile" value="<?php echo intval($profileId); ?>">
<input type="hidden" name="filters" id="filters" value="<?php echo DupHtml($filters); ?>">
<input type="hidden" name="dups" id="dups" value="<?php echo $dupsOnly ? 1 : 0; ?>">
<input type="hidden" name="act" id="act" value="Rename">
<TABLE id="showListTable" class="tablesorter" border="0" cellSpacing="1" cellPadding="0">
<THEAD>
<TR>
<TH class="nowrap">Num</TH>
<TH>Select</TH>
<TH>Type</TH>
<TH>Current Name</TH>
<TH>New Name</TH>
<TH>Options</TH>
</TR>
</THEAD>
<TBODY>
<?php
$num = $start + 1;
$displayed = 0;

foreach($pageCandidateSets as $profileSet)
{
    $profileInfo = $profileSet['profile'];
    ProfileHeader(intval($profileInfo['id']), isset($profileInfo['path']) ? $profileInfo['path'] : "");

    foreach($profileSet['rows'] as $row)
    {
        $icon = "<I class=icon-file></I>";
        $type = "File";

        if(isset($row['link']) && strlen($row['link']) > 0)
        {
            $icon = "<I class=icon-magnet></I>";
            $type = "Link";
        }

        $rowId = intval($row['id']);
        $rowProfile = intval($row['profile']);
        $fileHtml = DupHtml($row['file']);
        $old = ChopName(trim($row['_old_name']));
        $new = ChopName(trim($row['_new_name']));
        $oldHtml = HighlightFilterText($old, $filters);
        $newHtml = DupHtml($new);
        $typeHtml = DupHtml($type);
        $downloadHtml = $downloads ? " <b>/</b> <a href=\"download-files.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-download-alt title=\"Download\"></I></a>" : "";

        echo "<TR>\n";
        echo " <TD class=\"nowrap\">" . $num . "</TD>\n";
        echo " <TD class=\"nowrap\" align=\"middle\"><input type=\"checkbox\" id=\"sec\" name=\"files[]\" value=\"" . $rowId . "\" CHECKED></TD>\n";
        echo " <TD class=\"nowrap\" title=\"" . $typeHtml . "\" align=\"middle\">" . $icon . "</TD>\n";
        echo " <TD title=\"" . $fileHtml . "\">" . $oldHtml . "</TD>\n";
        echo " <TD title=\"" . $fileHtml . "\">" . $newHtml . "</TD>\n";
        echo " <TD class=\"nowrap\" align=\"middle\"><a href=\"view-file.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-eye-open title=\"View\"></I></a>" . $downloadHtml . " <b>/</b> <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Delete', '" . $rowId . "');\"><I class=icon-trash title=\"Delete\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Move', '" . $rowId . "');\"><I class=icon-share title=\"Move\"></I></a></TD>\n";
        echo "</TR>\n";

        $num++;
        $displayed++;
    }
}
?>
</TBODY>
<TFOOT>
<TR>
<TH align="middle"><input type="Button" Value="Rename" OnClick="handleClick('rename','Rename')"></TH>
<TH align="middle"><input type="Button" Value="Inverse" OnClick="checkedAll('rename')"></TH>
<TH colSpan="2" align="left">Displayed / Total:</TH>
<TH align="left"><?php echo number_format($displayed) . " / " . number_format($totalFound); ?></TH>
<TH align="middle"><a href="javascript:handleClick('rename','Delete')"><I class=icon-trash title="Delete Selected"></I></a> <b>/</b> <a href="javascript:handleClick('rename','Move')"><I class=icon-share title="Move Selected"></I></a></TH>
</TR>
</TFOOT>
</TABLE>
</form>
<?php echo PagerHtml($filters, $profileId, $dupsOnly, $start, $pageLimit, $shown, $totalFound, $submitted); ?>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
<SCRIPT type="text/javascript">
function checkedAll(val)
{
    var inputs = document.querySelectorAll('#' + val + ' input[type="checkbox"][name="files[]"]');

    for(var i = 0; i < inputs.length; i++)
        inputs[i].checked = !inputs[i].checked;
}

function handleClick(form, act)
{
    var formElement = document.getElementById(form);
    var actionInput = document.getElementById('act');
    var selected = document.querySelectorAll('#' + form + ' input[type="checkbox"][name="files[]"]:checked').length;

    if(selected <= 0)
    {
        alert('No files are selected.');
        return;
    }

    if(actionInput != null)
        actionInput.value = act;

    if(act == 'Rename')
    {
        if(confirm('Rename ' + selected + ' selected file(s) on this page?'))
        {
            formElement.action = 'rename-renaming.php';
            formElement.submit();
        }

        return;
    }

    if(confirm(act + ' ' + selected + ' selected file(s) on this page?'))
    {
        formElement.action = 'action-files.php';
        formElement.submit();
    }
}

function SingleFileAction(profile, act, fileid)
{
    var form = document.createElement("form");
    form.method = "POST";
    form.action = "action-files.php";
    var fields = {profile: profile, act: act};

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
</SCRIPT>
</HTML>
