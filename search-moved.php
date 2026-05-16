<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Moved Files Search", 1, __FILE__, __LINE__, __FUNCTION__);

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
    $escapedValue = DupHtml($value);

    if((string)$search == "")
        return $escapedValue;

    return preg_replace("/(" . preg_quote(DupHtml($search), "/") . ")/i", "<mark>$1</mark>", $escapedValue);
}

function AddMovedFilter(&$whereParts, &$bindValues, $profiles, $profile)
{
    $movedParts = array();

    foreach($profiles as $profileRow)
    {
        $profileId = isset($profileRow['id']) ? intval($profileRow['id']) : 0;
        $movedPath = isset($profileRow['mpath']) ? trim((string)$profileRow['mpath']) : "";

        if($profileId <= 0 || $movedPath == "")
            continue;

        if($profile != 0 && $profileId != $profile)
            continue;

        $profileKey = ":moved_profile_" . $profileId;
        $pathKey = ":moved_path_" . $profileId;

        $movedParts[] = "(f.profile=" . $profileKey . " AND f.file LIKE " . $pathKey . ")";
        $bindValues[$profileKey] = array($profileId, PDO::PARAM_INT);
        $bindValues[$pathKey] = array(rtrim($movedPath, "/") . "/%", PDO::PARAM_STR);
    }

    if(count($movedParts) > 0)
        $whereParts[] = "(" . implode(" OR ", $movedParts) . ")";
    else
        $whereParts[] = "1=0";
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
<TITLE>dup - Moved Files Search</TITLE>
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
    $("#showListTable:has(tbody tr)").tablesorter({ widgets: ['saveSort', 'zebra', 'stickyHeaders'] });
});
</SCRIPT>
<style>mark { background: #fff3a3; color: inherit; padding: 0 2px; border-radius: 2px; } .search-options { font-size: 14px; line-height: 28px; }</style>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
</HEADER>

<DIV id=contentWrapper>
 <DIV id=content>
 <form id=searchform name=searchform method=post action=search-moved.php>
 <H1 class=title>Moved Files Search</H1>
 <H1 class=title>Search : <input type="text" name="search" size="30" value="<?php echo DupHtml($search); ?>"> &nbsp;
  Scope :
  <?php
    $Profiles = $dup->GetProfiles("path");         
	echo "<select name=\"profile\" id=\"profile\" class=\"input-medium\" style=\"width: 270px\">\n";
	echo " <option value=\"0\"" . (($profile == 0) ? " SELECTED" : "") . "> Global - All Profiles</option>\n";
	foreach($Profiles as $key => $val)
	{
		$id = DupInt($val['id']);
		$pathHtml = DupHtml($val['path']);
		$selected = ($id == $profile) ? " SELECTED" : "";

		echo " <option value=\"" . $id . "\"" . $selected . "> (" . $id . ") " . $pathHtml . " </option>\n";
	}
	echo "</select>\n";    
    echo "&nbsp;Case Sensitive : <input type=checkbox name=case".(($case==true)?" CHECKED":"").">";  
 ?> 
  &nbsp;
  <input type=Submit value=Search></B></H1>
 </form> 
 
<SCRIPT type="text/javascript" charset="utf-8">
<!--
$(document).ready();
//-->
</SCRIPT>
 
<form id=search name=search method=post action=action-files.php>
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
  <input type="hidden" name="profile" value="<?php echo DupInt($profile); ?>">
  <input type="hidden" id="act" name="act" value="none">
  <TBODY>

<?php
$num = 1;
$tsize = 0;

$ps = $dup->GetProfiles("id");
$searchValue = $case ? $search : strtolower($search);
$likeValue = "%" . $searchValue . "%";

try
{
    $whereParts = array();
    $bindValues = array();
    AddMovedFilter($whereParts, $bindValues, $ps, $profile);

    if(strlen($search) > 0)
    {
        $whereParts[] = $case ? "f.file LIKE :search" : "LOWER(f.file) LIKE :search";
        $bindValues[":search"] = array($likeValue, PDO::PARAM_STR);
    }

    if($profile != 0)
    {
        $whereParts[] = "f.profile=:profile";
        $bindValues[":profile"] = array($profile, PDO::PARAM_INT);
    }

    $where = implode(" AND ", $whereParts);
    $order = ($profile != 0) ? " ORDER BY f.file ASC" : " ORDER BY f.profile ASC, f.file ASC";
    $query = "SELECT f.id, f.file, f.size, f.fdate, f.crc32, f.profile, f.link FROM files f WHERE " . $where . $order . " LIMIT :start, 995;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);

    foreach($bindValues as $key => $data)
        $sdt->bindValue($key, $data[0], $data[1]);

    $sdt->bindValue(":start", $start, PDO::PARAM_INT);
    $sdt->execute();
    $files = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();
    $ppath = "";

    foreach($files as $row)
    {
        $fid = DupInt($row['profile']);
        $rowId = DupInt($row['id']);
        $rowFile = isset($row['file']) ? $row['file'] : "";
        $rowLink = isset($row['link']) ? $row['link'] : "";
        $icon = (strlen($rowLink) > 0) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";

        if(isset($ps[$fid]))
        {
            $ppath = isset($ps[$fid]['path']) ? $ps[$fid]['path'] : "";
            echo "<TR class=nowrap>\n";
            echo " <td colspan=7><b><a href=\"profile-view.php?profile=" . $fid . "\">" . DupHtml($ppath) . "</a></b></td>\n";
            echo "</TR>\n";
            unset($ps[$fid]);
        }

        $size = isset($row['size']) ? intval($row['size']) : 0;
        $fdate = isset($row['fdate']) ? intval($row['fdate']) : 0;
        $crc32 = isset($row['crc32']) ? $row['crc32'] : "";
        $date = new DateTime("@" . $fdate);
        $fileJs = JavaScriptSafeString($rowFile);
        $fileTitle = DupHtml($rowFile);
        $dropTitle = DupHtml(DropProfile($rowFile, $ppath));
        $shortFile = ChopName($rowFile);

        echo "<TR class=nowrap>\n";
        echo " <TD class=nowrap>" . $num . "</TD>\n";
        echo " <TD class=nowrap><span title='" . (($icon == "<I class=icon-file></I>") ? "File" : "Symlink") . "' >" . $icon . "</span> &nbsp; <input type=checkbox id=\"sec\" name=\"files[]\" value=\"" . $rowId . "\" CHECKED> " . $rowId . "</TD>\n";
        echo " <TD class=nowrap><span title=\"" . $fileTitle . "\">" . HighlightSearch($shortFile, $search) . "</span></TD>\n";
        echo " <TD class=nowrap><center><span title=\"" . number_format($size) . " bytes\">" . $dup->format_bytes($size) . "</span></center></TD>\n";
        echo " <TD class=nowrap><span title=\"" . $fdate . "\">&nbsp;" . $date->format('Y-m-d H:i:s') . "</span></TD>\n";
        echo " <TD class=nowrap>" . DupHtml($crc32) . "</TD>\n";
        echo "<TD align=middle><center>";
        echo "<a href=\"view-file.php?profile=" . $fid . "&file=" . $rowId . "\"><I class=icon-eye-open title=\"View '" . $fileTitle . "'\"></I></a> <b>/";
        echo " <a href=\"download-files.php?profile=" . $fid . "&file=" . $rowId . "\"><I class=icon-download-alt title=\"Download '" . $fileTitle . "'\"></I></a> /";
        echo " <a href=\"javascript:DeleteFile('" . $fid . "', '" . $fileJs . "', '" . $rowId . "');\"><I class=icon-trash title=\"Delete '" . $fileTitle . "'\"></I></a> /";
        echo " <a href=\"javascript:SingleFileAction('" . $fid . "', 'Rename', '" . $rowId . "');\"> <I class=icon-edit title=\"Rename '" . $dropTitle . "'\"></I></a> /";
        echo " <a href=\"javascript:SingleFileAction('" . $fid . "', 'Move', '" . $rowId . "');\"> <I class=icon-share title=\"Move '" . $dropTitle . "'\"></I></a></b>";
        echo "</center></TD>\n";
        echo "</TR>\n";
        $num++;
        $tsize += $size;
    }
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
?>
</TBODY>
</form>
<TFOOT>
<TR>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><input type=Button Value=Inverse OnClick="displayAll('search')"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><input type=Button Value=Inverse OnClick="checkedAll('search')"></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Listed Data size :</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo $dup->format_bytes($tsize) ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;">Total Found:</TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><?php echo ($num-1) ?></TH>
<TH style="color: black; background-color:#dfdacf; border: 1pt solid black;"><center><?php echo "<a href=\"javascript:handleClick('search', 'Delete')\"><I class=icon-trash title=\"Delete Selected\"></I></a> / <a href=\"javascript:handleClick('search', 'Rename')\"><I class=icon-edit title=\"Rename Selected\"></I></a> / <a href=\"javascript:handleClick('search', 'Move')\"><I class=icon-share title=\"Move Selected\"></I></a>"; ?></center></TH>
</TR>
</TFOOT>
</TABLE>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
<script type="text/javascript">
var checked = true;
function checkedAll(val)
{
    checked = !checked;
    for(var i = 0; i < document.getElementById(val).elements.length; i++)
    {
        if(document.getElementById(val).elements[i].id == 'sec')
            document.getElementById(val).elements[i].checked = checked;
    }
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
function DeleteFile(profile, file, fid)
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
<?php
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
function JavaScriptSafeString($value)
{
    return str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", "", ""), (string)$value);
}
?>
