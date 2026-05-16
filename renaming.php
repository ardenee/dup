<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Renaming '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "global") . "'", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
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

$profileId = intval($profile);

if($profileId != 0 && isset($dup->Profile['id']) && $profileId != intval($dup->Profile['id']))
    $profileId = intval($dup->Profile['id']);

$filters = isset($dup->Settings['cleanupfilter']) ? $dup->Settings['cleanupfilter'] : "";

if(isset($_POST['filters']))
    $filters = trim($_POST['filters']);
else if(isset($_GET['filters']))
    $filters = trim($_GET['filters']);

$filters = str_replace(array("\r", "\n", "\0"), "", $filters);
$dupsOnly = isset($_REQUEST['dups']) && intval($_REQUEST['dups']) == 1;
$submitted = true;
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Renaming</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]-->
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <SCRIPT SRC="files/script.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
        sbRoot = '/';
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />';
  </SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
  <SCRIPT type="text/javascript">
    $(document).ready(function(){
        $("#NAVrename").addClass("active");
        $("#showListTable:has(tbody tr)").tablesorter({ widgets: ['saveSort', 'zebra', 'stickyHeaders'] });
    });
  </SCRIPT>
  <style>
    mark { background: #fff3a3; color: inherit; padding: 0 2px; border-radius: 2px; }
  </style>
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
    $Profiles = $dup->GetProfiles("path");

    echo "<select name=\"profile\" id=\"profile\" class=\"input-medium\" style=\"width: 400px\">\n";
    echo " <option value=\"0\"" . (($profileId == 0) ? " SELECTED" : "") . "> Global - All Profiles</option>\n";

    foreach($Profiles as $key => $val)
    {
        $id = intval($val['id']);
        echo " <option value=\"" . $id . "\"" . (($profileId == $id) ? " SELECTED" : "") . "> (" . $id . ") " . DupHtml($val['path']) . " </option>\n";
    }

    echo "</select>\n";
    echo " &nbsp;&nbsp; Filters: <input type=\"text\" name=\"filters\" id=\"filters\" value=\"" . DupHtml($filters) . "\" size=\"50\">";
    echo " &nbsp;&nbsp; Dups Only: <input type=\"checkbox\" name=\"dups\" value=\"1\"" . (($dupsOnly) ? " CHECKED" : "") . ">";
  ?>
  &nbsp;
  <input type="Submit" value="Search"></H1>
 </form>

<form id="rename" name="rename" method="post" action="rename-renaming.php">
<input type="hidden" name="profile" value="<?php echo $profileId; ?>">
<input type="hidden" name="filters" id="filters" value="<?php echo DupHtml($filters); ?>">
<input type="hidden" name="dups" id="dups" value="<?php echo $dupsOnly ? 1 : 0; ?>">

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
if($submitted)
{
    $MovedFolders = $dup->GetMovedFolders($profileId);
    $num = 1;

    try
    {
        $whereParts = array();

        if($profileId != 0)
            $whereParts[] = "f.profile=:profile";

        if($dupsOnly)
            $whereParts[] = "EXISTS (SELECT 1 FROM files f2 INNER JOIN profiles p2 ON p2.id=f2.profile WHERE f2.profile=f.profile AND f2.id<>f.id AND ((p2.crc32=0 AND f2.size=f.size) OR (p2.crc32<>0 AND f.crc32<>'' AND f.crc32<>'0 Filled' AND f2.crc32=f.crc32)))";

        $whereSql = count($whereParts) > 0 ? " WHERE " . implode(" AND ", $whereParts) : "";
        $orderSql = ($profileId != 0) ? " ORDER BY f.file" : " ORDER BY f.profile, f.file";
        $query = "SELECT f.* FROM files f" . $whereSql . $orderSql . " LIMIT 995;";

        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);

        if($profileId != 0)
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);

        $sdt->execute();

        while($row = $sdt->fetch(PDO::FETCH_ASSOC))
        {
            if(!isset($row['file']))
                continue;

            if(IsMovedFile($row['file'], $MovedFolders) && $dup->Settings['displaymoved'] == 0)
                continue;

            $old = ChopName(trim(NameOnly($row['file'])));
            $new = ChopName(trim(CleanNameOnly($row['file'])));

            if($old == $new)
                continue;

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
            $oldHtml = HighlightFilterText($old, $filters);
            $newHtml = DupHtml($new);
            $typeHtml = DupHtml($type);

            echo "<TR>\n";
            echo " <TD class=\"nowrap\">" . $num . "</TD>\n";
            echo " <TD class=\"nowrap\" align=\"middle\"><input type=\"checkbox\" id=\"sec\" name=\"files[]\" value=\"" . $rowId . "\" CHECKED></TD>\n";
            echo " <TD class=\"nowrap\" title=\"" . $typeHtml . "\" align=\"middle\">" . $icon . "</TD>\n";
            echo " <TD class=\"nowrap\" title=\"" . $fileHtml . "\">" . $oldHtml . "</TD>\n";
            echo " <TD class=\"nowrap\" title=\"" . $fileHtml . "\">" . $newHtml . "</TD>\n";
            echo " <TD class=\"nowrap\" align=\"middle\"><a href=\"view-file.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-eye-open title=\"View\"></I></a> <b>/</b> <a href=\"download-files.php?profile=" . $rowProfile . "&file=" . $rowId . "\"><I class=icon-download-alt title=\"Download\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Delete', '" . $rowId . "');\"><I class=icon-trash title=\"Delete\"></I></a> <b>/</b> <a href=\"javascript:SingleFileAction('" . $rowProfile . "', 'Move', '" . $rowId . "');\"><I class=icon-share title=\"Move\"></I></a></TD>\n";
            echo "</TR>\n";
            $num++;

            if($num > 995)
                break;
        }

        $sdt->closeCursor();
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
   <TH align="middle"><input type="Button" Value="Rename" OnClick="handleClick('rename','Rename')"></TH>
   <TH align="middle"><input type="Button" Value="Inverse" OnClick="checkedAll('rename')"></TH>
   <TH colSpan="4" align="left"></TH>
 </TR>
</TFOOT>
</TABLE>
</form>
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
function handleClick(form, act)
{
    if(confirm('Are you sure you want to Mass ' + act + ' the selected files?'))
        document.getElementById(form).submit();
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
</script>
</HTML>
<?php
function ChopName($name)
{
    global $dup;
    if($dup->Settings['shortfilenames'] != 0 && strlen($name) > $dup->Settings['shortfilenames'])
        return "..." . substr($name, (strlen($name) - $dup->Settings['shortfilenames']), strlen($name));
    return $name;
}
function DropProfile($full, $part)
{
    if($part != "" && strpos($full, $part) === 0)
        return substr($full, strlen($part), strlen($full));
    return $full;
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
?>
