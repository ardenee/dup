<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Link Files  '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Link Files</TITLE>
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
    <!--
        $(document).ready(function(){ 
            $("#NAVhome").addClass("active");
        });
    //-->
    </SCRIPT>	
 
    <SCRIPT type="text/javascript" charset="utf-8">
	$(document).ready();
    </SCRIPT>
	<META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY> 
    <?php echo PageHeader(); ?>
   <DIV id=SubMenu><SPAN><A href="profile-add.php">Add Profile</A></SPAN></DIV>
  </HEADER>

<DIV id=contentWrapper>
 <DIV id=content>
  <H1 class=title>Files for <?php echo $dup->Profile['path']; ?> - Linking</H1>
  
<SCRIPT type=text/javascript charset=utf-8>
<!--

$(document).ready(function(){ 

    $("#showListTable:has(tbody tr)").tablesorter({
        widgets: ['zebra'],
    });
});
//-->
</SCRIPT>
  <form id="link" name="link" method="POST" action="action-list-files.php">
<input type="hidden" name="profile" value="<?php echo intval($profile); ?>">
   <input type="hidden" name="action" value="Link">

<TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
<THEAD>
  <TR>
   <TH class=nowrap>Num</TH>
   <TH>Type</TH>
   <TH>Select</TH>
   <TH>Current Name</TH>
  </TR>
  </THEAD>
  <TBODY>
  
<?php

$Files = array();
$profileId = intval($profile);

if(isset($_POST['files']) && is_array($_POST['files']))
{
    $ids = array();

    foreach($_POST['files'] as $val)
    {
        $val = intval($val);

        if($val > 0)
            $ids[] = $val;
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    try
    {
        $selectedCrcs = array();

        $query = "SELECT crc32 FROM files WHERE id=:id AND profile=:profile LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);

        foreach($ids as $id)
        {
            $sdt->bindValue(":id", $id, PDO::PARAM_INT);
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->execute();

            $row = $sdt->fetch(PDO::FETCH_ASSOC);

            if($row && isset($row["crc32"]) && $row["crc32"] != "")
                $selectedCrcs[] = $row["crc32"];

            $sdt->closeCursor();
        }

        $selectedCrcs = array_values(array_unique($selectedCrcs));

        $query = "SELECT id, file FROM files WHERE crc32=:crc32 AND profile=:profile ORDER BY file;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);

        foreach($selectedCrcs as $crc32)
        {
            $sdt->bindValue(":crc32", $crc32, PDO::PARAM_STR);
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->execute();

            while($row = $sdt->fetch(PDO::FETCH_ASSOC))
            {
                $id = intval($row["id"]);
                $Files[$crc32][$id] = $row["file"];
            }

            $sdt->closeCursor();
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    foreach($Files as $key => $value)
    {
        echo "<TR><TD colspan=\"4\"><b>Duplicate group: " . htmlspecialchars($key, ENT_QUOTES, "UTF-8") . "</b></TD></TR>\n";

        $tmpfilearray = array();
        $tmplinkarray = array();

        foreach($value as $id => $path)
        {
            if(is_link($path))
                $tmplinkarray[$id] = $path;
            else
                $tmpfilearray[$id] = $path;
        }

        if(count($tmpfilearray) == 0 && count($tmplinkarray) > 0)
            $tmpfilearray = $tmplinkarray;

        foreach($tmpfilearray as $k => $v)
        {
            $icon = is_link($v) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
            $typeTitle = is_link($v) ? "Symlink" : "File";

            $vHtml = htmlspecialchars($v, ENT_QUOTES, "UTF-8");
            $keyHtml = htmlspecialchars($key, ENT_QUOTES, "UTF-8");
            $checked = ($k == array_key_first($tmpfilearray)) ? " checked" : "";

            echo "<TR>\n";
            echo " <TD class=\"nowrap\">" . intval($k) . "</TD>\n";
            echo " <TD class=\"nowrap\" align=\"center\"><span title=\"" . $typeTitle . "\">" . $icon . "</span></TD>\n";
            echo " <TD class=\"nowrap\" align=\"center\"><span title=\"" . $vHtml . "\"><input id=\"keep_" . intval($k) . "\" type=\"radio\" name=\"" . $keyHtml . "\" value=\"" . $vHtml . "\"" . $checked . "></span></TD>\n";
            echo " <TD class=\"nowrap\"><span title=\"" . $vHtml . "\"><b>" . $vHtml . "</b></span></TD>\n";
            echo "</TR>\n";
        }

        foreach($tmplinkarray as $k => $v)
        {
            $icon = is_link($v) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
            $typeTitle = is_link($v) ? "Symlink" : "File";

            $vHtml = htmlspecialchars($v, ENT_QUOTES, "UTF-8");
            $keyHtml = htmlspecialchars($key, ENT_QUOTES, "UTF-8");

            echo "<TR>\n";
            echo " <TD class=\"nowrap\">" . intval($k) . "</TD>\n";
            echo " <TD class=\"nowrap\" align=\"center\"><span title=\"" . $typeTitle . "\">" . $icon . "</span></TD>\n";
            echo " <TD class=\"nowrap\" align=\"center\"><input type=\"checkbox\" name=\"oldname[" . $keyHtml . "][]\" value=\"" . $vHtml . "\" id=\"sel\" CHECKED></TD>\n";
            echo " <TD class=\"nowrap\"><span title=\"" . $vHtml . "\">" . $vHtml . "</span></TD>\n";
            echo "</TR>\n";
        }
    }
}

unset($Files);

?>
 </TBODY> 
 <TFOOT>
 <TR>
   <TH rowSpan="1" colSpan="1"></TH>
   <TH rowSpan="1" colSpan="1"></TH>
   <TH rowSpan="1" colSpan="1"><input type="Button" Value="Inverse" OnClick="checkedAll('link')"></TH>
   <TH rowSpan="1" colSpan="1" align=right><input type="submit" value=" Link Files "></TH>
 </TR>
 </TFOOT>
</TABLE> 
  </form> 
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  <?php echo PageFooter(); ?>
 </BODY>

<script type="text/javascript"> 
var checked = true;  
function checkedAll(val) {  
	if (checked == false)  
	  {checked = true}  
	else  
	  {checked = false}  
	for (var i = 0; i < document.getElementById(val).elements.length; i++)  
  {
    if(document.getElementById(val).elements[i].id == 'sel')
	    document.getElementById(val).elements[i].checked = checked;  
   } 
} 
</script> 
</HTML> 

<?php
//----------------------------------------------------------------------------
function DropProfile($full, $part)
{
	return substr($full, strlen($part), strlen($full));
}
//----------------------------------------------------------------------------
function CleanUpName($name)
{
    global $dup;

    $nameparts = pathinfo($name);
    $catches = explode(",", $dup->Settings['cleanupfilter']);

    $base = isset($nameparts['filename']) ? $nameparts['filename'] : $name;
    $extension = isset($nameparts['extension']) && $nameparts['extension'] != "" ? "." . $nameparts['extension'] : "";

    foreach($catches as $key => $val)
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
//----------------------------------------------------------------------------
function NameOnly($name)
{
	global $dup;
	$nname = $name;
	$nname = substr($nname, strrpos($nname, "/"));

		if($dup->Settings['namecleanup']==1)	
		return CleanUpName(trim($nname, "/"));	
	else
		return trim($nname, "/");
}
//--------------------------------------------------------------------------
function ChopName($name)
{
	global $dup;
	
	if($dup->Settings['shortfilenames']!=0)
	{	
		if(strlen($name)>$dup->Settings['shortfilenames'])
			return "...".substr($name, (strlen($name)-$dup->Settings['shortfilenames']), strlen($name));
		else
			return $name;
	}
	else
		return $name;
}
?>