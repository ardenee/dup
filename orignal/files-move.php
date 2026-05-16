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
   <input type="hidden" name="profile" value="<?php echo $profile ?>">
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

if(isset($_POST['files']))
{
	sort($_POST['files']);

	foreach($_POST['files'] as $key => $val)
	{
		$query = "SELECT file, profile FROM files WHERE id=$val;";
				
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$pro           = $row['profile'];
				$Files[$pro][] = $row['file'];
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			continue;
		}	
	}
	
	$icon  = "";	
	$psett = array();
	
	foreach($Files as $key=>$value)
	{		
		if($psett['id']!=$key)
		{
			$psett = $dup->GetProfile($key);
			$dup->SetProfileSettings($psett);
			
			echo "<TR class=nowrap>\n";
			echo " <td colspan=3>&nbsp;</td>\n";
			echo " <td class=nowrap><b><a href=\"profile-view.php?profile=$key\">".$psett['path']."</a></b></td>\n";
			echo " <td class=nowrap><b><a href=\"profile-view.php?profile=$key\">".$psett['mpath']."</a></b></td>\n";
			echo "</TR>\n";				
		}
		
		foreach($value as $k=>$v)
		{	
			$icon = "<I class=icon-file></I>";//"<img src='images/file16.png'>";
					
			if(is_link($v))
				$icon = "<I class=icon-magnet></I>";//"<img src='images/sfile16.png'>";		
									
			
			$newfile = NewLoc($v);
			echo " <TR class=nowrap>\n";		
			echo "  <TD class=nowrap>".($k+1)."</TD>\n";
			echo "  <TD class=nowrap align=center><span title='".(($icon=="<I class=icon-magnet></I>")?"Symlink":"File")."' >$icon</span></TD>\n";
			echo "  <TD class=nowrap align=center><input type=\"checkbox\" name=\"oldname[".FilterOn($v)."]\" value=\"$newfile\" id=\"sel\" CHECKED></TD>\n";
			echo "  <TD><span title=\"".$v."\"> ".ChopName(DropProfile($v, $dup->Profile['path']))."</span></TD>\n";
			echo "  <TD><span title=\"".$newfile."\"> ".ChopName(DropProfile($newfile, $dup->Profile['mpath']))."</span></TD>\n";
			echo " </TR>\n\n";
		}
	}
}

unset($Files); // free up memory		

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
function NewLoc($name)
{
	global $dup;
	$ScanFolder = $dup->Profile['path'];
	$NewFolder  = $dup->Profile['mpath'];	
	
	if($dup->Settings['cpfolder']) // add profile folder
		$NewFolder.="/".StripPath($dup->Profile['path']);	

	// Flatten folders		
	
	$nameparts  = pathinfo($name);
	
	if($dup->Settings['flatfolders']==0) // flatten the folders!!
		$NewFolder.= rtrim(substr($nameparts['dirname'], strlen($dup->Profile['path']), strlen($nameparts['dirname'])), "/");
	
	return $NewFolder."/".$nameparts['basename'];
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