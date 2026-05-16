<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Move Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - General Search</TITLE>
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
            $("#NAVsearch").addClass("active");
        });
    //-->
    </SCRIPT>	  
  
 <SCRIPT type="text/javascript" charset="utf-8">
    <!--
	$(document).ready(function(){ 
		$("#showListTable:has(tbody tr)").tablesorter({
			widgets: ['saveSort', 'zebra', 'stickyHeaders']
		});
	});
	//-->
    </SCRIPT>
  <META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY>
 
    <?php echo PageHeader(); ?>
   <DIV id=SubMenu><SPAN><A href="profile-add.php">Add Profile</A> | <A href="profile-view.php?profile=<?php echo $dup->Profile['id']; ?>">View Profile</A></SPAN></DIV>
  </HEADER>

  <DIV id=contentWrapper>
   <DIV id=content>
	<H1 class=title>Files for Moving</H1>

  <form id="move" name="move" method="POST" action="action-list-files.php">
   <input type="hidden" name="profile" value="<?php echo intval($profile); ?>">
   <input type="hidden" name="action" value="Move">

<TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
 <THEAD>
  <TR>
   <TH class=nowrap>Num</TH>
   <TH class=nowrap>Type</TH>
   <TH class=nowrap>Select</TH>
   <TH>Current Location</TH>
   <TH>New Location</TH>
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
        $query = "SELECT id, file FROM files WHERE profile=:profile AND id=:id LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);

        foreach($ids as $id)
        {
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->bindValue(":id", $id, PDO::PARAM_INT);
            $sdt->execute();

            $row = $sdt->fetch(PDO::FETCH_ASSOC);

            if($row && isset($row["file"]))
                $Files[] = $row["file"];

            $sdt->closeCursor();
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    $profilePathHtml = htmlspecialchars($dup->Profile['path'], ENT_QUOTES, "UTF-8");
    $profileMovePathHtml = htmlspecialchars($dup->Profile['mpath'], ENT_QUOTES, "UTF-8");

    echo "<TR class=\"nowrap\">\n";
    echo " <td colspan=\"3\">&nbsp;</td>\n";
    echo " <td class=\"nowrap\"><b><a href=\"profile-view.php?profile=" . $profileId . "\">" . $profilePathHtml . "</a></b></td>\n";
    echo " <td class=\"nowrap\"><b><a href=\"profile-view.php?profile=" . $profileId . "\">" . $profileMovePathHtml . "</a></b></td>\n";
    echo "</TR>\n";

    foreach($Files as $k => $v)
    {
        $icon = is_link($v) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
        $typeTitle = is_link($v) ? "Symlink" : "File";

        $newfile = NewLoc($v);

        $vHtml = htmlspecialchars($v, ENT_QUOTES, "UTF-8");
        $newfileHtml = htmlspecialchars($newfile, ENT_QUOTES, "UTF-8");
        $vKey = htmlspecialchars(FilterOn($v), ENT_QUOTES, "UTF-8");
        $displayOld = htmlspecialchars(ChopName(DropProfile($v, $dup->Profile['path'])), ENT_QUOTES, "UTF-8");
        $displayNew = htmlspecialchars(ChopName(DropProfile($newfile, $dup->Profile['mpath'])), ENT_QUOTES, "UTF-8");

        echo " <TR class=\"nowrap\">\n";
        echo " <TD class=\"nowrap\">" . ($k + 1) . "</TD>\n";
        echo " <TD class=\"nowrap\" align=\"center\"><span title=\"" . $typeTitle . "\">" . $icon . "</span></TD>\n";
        echo " <TD class=\"nowrap\" align=\"center\"><input type=\"checkbox\" name=\"oldname[" . $vKey . "]\" value=\"" . $newfileHtml . "\" id=\"sel\" CHECKED></TD>\n";
        echo " <TD><span title=\"" . $vHtml . "\"> " . $displayOld . "</span></TD>\n";
        echo " <TD><span title=\"" . $newfileHtml . "\"> " . $displayNew . "</span></TD>\n";
        echo " </TR>\n\n";
    }
}

unset($Files);

?>
 </TBODY> 
 <TFOOT>
  <TR>
   <TH rowSpan=1 colSpan=1></TH>
   <TH rowSpan=1 colSpan=1></TH>
   <TH rowSpan=1 colSpan=1><input type=Button Value=Inverse OnClick="checkedAll('move')"></TH>
   <TH rowSpan=1 colSpan=1></TH>
   <TH rowSpan=1 colSpan=1><input type=submit value=Move></TH>
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
	    document.getElementById(val).elements[i].checked = checked;  
    } 
} 
</script> 
</HTML> 

<?php
//----------------------------------------------------------------------------
function DropProfile($full, $part)
{
	//echo strpos($full, $part)."<BR>";
	
	if(strpos($full, $part)==0)
		return substr($full, strlen($part), strlen($full));
	else
		return $full;
}
//----------------------------------------------------------------------------
function CleanUpName($name)
{
    global $dup;

    $nameparts = pathinfo($name);

    $base = isset($nameparts['filename']) ? $nameparts['filename'] : $name;
    $extension = isset($nameparts['extension']) && $nameparts['extension'] != "" ? "." . $nameparts['extension'] : "";

    $catches = explode(",", $dup->Settings['cleanupfilter']);

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
function NewLoc($name)
{
    global $dup;

    $scanFolder = rtrim($dup->Profile['path'], "/");
    $newFolder = rtrim($dup->Profile['mpath'], "/");

    if($dup->Settings['cpfolder'])
        $newFolder .= "/" . StripPath($scanFolder);

    $nameparts = pathinfo($name);
    $sourceDir = isset($nameparts['dirname']) ? $nameparts['dirname'] : "";
    $basename = isset($nameparts['basename']) ? $nameparts['basename'] : basename($name);

    if($dup->Settings['flatfolders'] == 0)
    {
        if(strpos($sourceDir, $scanFolder) === 0)
        {
            $relativeDir = trim(substr($sourceDir, strlen($scanFolder)), "/");

            if($relativeDir != "")
                $newFolder .= "/" . $relativeDir;
        }
    }

    return $newFolder . "/" . CleanUpName($basename);
}
//----------------------------------------------------------------------------
function StripPath($path)
{
	$path_parts = pathinfo($path);
	
	return $path_parts['filename'];
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
//----------------------------------------------------------------------------
?>