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
   <input type="hidden" name="profile" value="<?php echo $profile ?>">
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

if(isset($_POST['files']))
{
	sort($_POST['files']);

	foreach($_POST['files'] as $key => $val)
	{	// get crc32 of file
		$query = "SELECT crc32 FROM files WHERE id=$val AND profile=$profile;";	
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		
		try	{
			$sdt = $dblink->query($query);
			// add to db
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$crc32 = $row['crc32'];
				@array_push($Files[$crc32], $crc32);
			}
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
		$query = "SELECT file, id FROM files WHERE crc32='$key' AND profile=$profile;";	
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		
		try	{
			$sdt = $dblink->query($query);

			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$id =  $row['id'];
				$Files[$key][$id] = $row['file'];
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			//header("Location: error.php");	
			Exit;
		}	
	}
	
	//p($Files);
	
	foreach($Files as $key=>$value)
	{	
		$tmplinkarray = array();
		$tmpfilearray = array();
		
		foreach($value as $k=>$v)
		{
			//echo $k."<br>";
			if(in_array($k, $_POST['files']))
			{
				$tmplinkarray[] = $v;
				unset($value[$k]);
			}
			else
				$tmpfilearray[] = $v;
				
		}	
		
		// display file selection if above 2 otherwise had set to only entry
		
		foreach($tmpfilearray as $k=>$v)
		{
			//if(is_file($v))
			//	$icon = "<img src='images/file16.png'>";
				
			//if(is_link($v))
			//	$icon = "<img src='images/sfile16.png'>";	


			$icon = "<I class=icon-file></I>";//"<img src='images/file16.png'>";
				
			if(is_link($v))
				$icon = "<I class=icon-magnet></I>";//"<img src='images/sfile16.png'>";			
		
			echo "<TR>\n";
			echo "  <TD class=nowrap>".($k+1)."</TD>\n";		  
			echo "  <TD class=nowrap align=center><span title='".(($icon=="<img src='images/sfile16.png'>")?"Symlink":"File")."' >$icon</span></TD>\n";	  
			echo "  <TD class=nowrap align=center><span title=\"$v\"><input id=$key type=\"radio\" name=\"$key\" value=\"$v\" ".(($k==0)?'checked':'')."></span></TD>\n";
            echo "  <TD class=nowrap><span title=\"$v\"><B>$v</b></span></TD>\n";
			echo "</TR>\n";
		}
		// display fiels to convert to links
		
		foreach($tmplinkarray as $k=>$v)
		{				
			$icon = "<I class=icon-file></I>";//"<img src='images/file16.png'>";
				
			if(is_link($v))
				$icon = "<I class=icon-magnet></I>";//"<img src='images/sfile16.png'>";			
		
			echo "<TR>\n";
			echo "  <TD class=nowrap>".($k+1)."</TD>\n";		  
			echo "  <TD class=nowrap align=center><span title='".(($icon=="<I class=icon-magnet></I>")?"Symlink":"File")."' >$icon</span></TD>\n";
			echo "  <TD class=nowrap align=center><input type=\"checkbox\" name=\"oldname[$key][]\" value=\"$v\" id=\"sel\" CHECKED></TD>\n";		  
			echo "  <TD class=nowrap><span title=\"$v\">$v</span></TD>\n";
			echo "</TR>\n";
		}
	}
}

unset($Files); // free up memory		

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