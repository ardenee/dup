<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Rename Files  '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Rename Files</TITLE>
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
  <H1 class=title>Files for Renaming</H1>
  
<SCRIPT type=text/javascript charset=utf-8>
<!--

$(document).ready(function(){ 

    $("#showListTable:has(tbody tr)").tablesorter({
        sortList: [[2,1]],
        widgets: ['saveSort', 'zebra', 'stickyHeaders'],
        headers: {
            0: { sorter: 'eps' },
            1: { sorter: 'loadingNames' },
            2: { sorter: 'loadingNames' },
        }
    });
});
//-->
</SCRIPT>
  <form id="rename" name="rename" method="POST" action="action-list-files.php">
   <input type="hidden" name="profile" value="<?php echo $profile ?>">
   <input type="hidden" name="action" value="Rename">

<TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
<THEAD>
  <TR>
   <TH class=nowrap>Num</TH>
   <TH>Type</TH>
   <TH>Select</TH>
   <TH>Current Name</TH>
   <TH>New Name</TH>
  </TR>
  </THEAD>
  <TBODY>
  
<?php
$Files = array();

if(isset($_POST['files']))
{
	sort($_POST['files']);

	foreach($_POST['files'] as $key => $val)
	{
		$query = "SELECT file, profile FROM files WHERE id=$val;";	
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		
		try
		{
			$sdt = $dblink->query($query);
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				array_push($Files, $row['file']);
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
	}
	
	$icon = "";

	foreach($Files as $key=>$value)
	{			
		$icon = "<I class=icon-file></I>";//"<img src='images/file16.png'>";
				
		if(is_link($v))
			$icon = "<I class=icon-magnet></I>";//"<img src='images/sfile16.png'>";			
	
		echo "<TR>\n";
		echo "  <TD class=nowrap>".($key+1)."</TD>\n";		  
		echo "  <TD class=nowrap align=center><span title='".(($icon=="<I class=icon-magnet></I>")?"Symlink":"File")."' >$icon</span></TD>\n";
		echo "  <TD class=nowrap align=center><input type=\"checkbox\" name=\"oldname[".FilterOn($value)."]\" value=\"$value\" id=\"sel\" CHECKED></TD>\n";		  
		echo "  <TD class=nowrap><span title=\"$value\"> ".ChopName(NameOnly($value))."</span></TD>\n";
		echo "  <TD class=nowrap><input type=\"text\"     name=\"newname[".FilterOn($value)."]\" value=\"".NameOnly($value)."\" size=50></TD>\n";
		echo "</TR>\n";
	}
}

unset($Files); // free up memory		

?>
 </TBODY> 
 <TFOOT>
 <TR>
   <TH rowSpan="1" colSpan="1"></TH>
   <TH rowSpan="1" colSpan="1"></TH>
   <TH rowSpan="1" colSpan="1"><input type="Button" Value="Inverse" OnClick="checkedAll('rename')"></TH>
   <TH rowSpan="1" colSpan="1"></TH>
   <TH rowSpan="1" colSpan="1"><input type="submit" value="Rename"></TH>
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
	$catches   = explode(",", $dup->Settings['cleanupfilter']);
	$name      = $nameparts['filename'];
	
	foreach($catches as $key => $val)
		$name = str_ireplace($val, "", $name);	

	if($dup->Settings['dot']>0) 
		$name = str_ireplace(".", (($dup->Settings['dot']==1)?" ":"_"), $name);
	
	if($dup->Settings['under']>0) 
		$name = str_ireplace("_", (($dup->Settings['under']==1)?" ":"."), $name);

	if($dup->Settings['doubleb']>0) 
	{
		while(strpos($name, "  ")>0)
			$name = str_ireplace("  ", " ", $name);
	}
	
	return trim($name).".".$nameparts['extension'];
}
//----------------------------------------------------------------------------
function NameOnly($name)
{
	$path_parts = pathinfo($name);
	$nname = $path_parts['basename'];
	
	return $nname;
	
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