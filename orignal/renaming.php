<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Renaming '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

$filters = $dup->Settings['cleanupfilter'];

if($profile!=0 and $profile!=$dup->Profile['id'])
	$profile = $dup->Profile['id']; // force update
	
if(strlen($_POST['filters'])>0)
	$filters = $_POST['filters']; // force update
else if(strlen($_GET['filters'])>0)
	$filters = $_GET['filters']; // force update
	
$submitted=false;

if(isset($_POST['submitted']))
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
    <!--
        $(document).ready(function(){ 
            $("#NAVrename").addClass("active");
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

 <BODY onload="displayAll()"> 
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
	echo " <option value=0".(($profile==0)?" SELECTED":"")."> Global - All Profiles</option>\n";
	
	foreach($Profiles as $key => $val)
		echo " <option value=".$val['id'].(($profile==$val['id'])?" SELECTED":"")."> (".$val['id'].") ".$val['path']." </option>\n";
	
	echo "</select>\n";		
	echo " &nbsp;&nbsp; Filters: <input type=text name=filters id=filters value=\"$filters\" size=50>";
	
 ?> 
  &nbsp;
  <input type="Submit" value="Search"></B></H1>
 </form> 
<SCRIPT type="text/javascript" charset"=utf-8">
<!--
$(document).ready();
//-->
</SCRIPT>
  <form id="rename" name="rename" method="post" action="renaming-rename.php">
   <input type="hidden" name="profile" value=<?php echo $profile; ?>> 
   <input type="hidden" name="filters" id="filters" value="<?php echo $filters; ?>">

	<TABLE id="showListTable" class="tablesorter" border="0" cellSpacing="1" cellPadding="0">
	<THEAD>
	  <TR>
	   <TH class="nowrap">Num</TH>
	   <TH>Type</TH>
	   <TH>Current Name</TH>
	   <TH>New Name</TH>
	  </TR>
	  </THEAD>
	  <TBODY>  
  
<?php  
if($submitted)
{
	$query        = "SELECT * FROM files ORDER BY profile;";
	$MovedFolders = $dup->GetMovedFolders($profile);
	
	if($profile!=0)
		$query = "SELECT * FROM files WHERE profile=$profile ORDER BY file;";

	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$num = 1;
	
	try
	{
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{			
			if(IsMovedFile($row['file'], $MovedFolders) and $dup->Settings['displaymoved']==0)
				continue;			
			
			$old = ChopName(trim(NameOnly($row['file'])));
			$new = ChopName(trim(CleanNameOnly($row['file'])));
			
			$icon = "<I class=icon-file></I>";//"<img src='images/file16.png'>";
			$type = "File";
			
			if(strlen($row['link'])>0)
			{
				$icon = "<I class=icon-magnet></I>";	
				$type = "Link";
			}
			
			if($old!=$new)
			{			
				echo "<TR>\n";
				echo "  <TD class=nowrap>$num</TD>\n";
				echo "  <TD class=nowrap title=\"$type\" align=middle>$icon</TD>\n";
				echo "  <TD class=nowrap title=\"".$row['file']."\">$old</TD>\n";
				echo "  <TD class=nowrap title=\"".$row['file']."\">$new</TD>\n";
				echo "</TR>\n";
				$num++;
			}
			
			if($num>995)
				break;
		}
			
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
}	

?>
  </TBODY>
  </form> 
  <TFOOT>
 <TR>
   <TH rowSpan="1" colSpan="1" align="middle"><input type="Button" Value="Rename" OnClick="handleClick('rename','Rename')"></TH>
   <TH rowSpan="1" colSpan="1" align="middle"></TH>
   <TH rowSpan="1" colSpan="1" align="middle"></TH>
   <TH rowSpan="1" colSpan="1" align="left"></TH>
</TR>
 </TFOOT>

 </TABLE> 
 </form> 
 </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  <?php echo PageFooter(); ?>
 </BODY>



 
<script type="text/javascript">  
function handleClick(form, act){
  var answer=confirm('Are you sure you want to Mass'+act+'?');
  if (answer==true)
  {
    document.getElementById(form).submit();
  }
}
</script>	
</HTML> 
<?php
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
function DropProfile($full, $part)
{
	return substr($full, strlen($part), strlen($full));
}
//----------------------------------------------------------------------------
function CleanUpName($name)
{
	global $dup;
	global $filters;

	$nameparts = pathinfo($name);
	$catches   = explode(",", $filters);
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
	
	if(strlen($nameparts['extension'])>0)
		return trim($name).".".$nameparts['extension'];
	else
		return trim($name);
}
//----------------------------------------------------------------------------
function CleanUpNameFiltersOnly($name)
{
	global $dup;
	global $filters;

	$nameparts = pathinfo($name);
	$catches   = explode(",", $filters);
	$name      = $nameparts['filename'];
	
	foreach($catches as $key => $val)
		$name = str_ireplace($val, "", $name);	

	if(strlen($nameparts['extension'])>0)
		return $name.".".$nameparts['extension'];
	else
		return $name;
}
//----------------------------------------------------------------------------
function NameOnly($name)
{
	$nameParts = pathinfo($name);

	return $nameParts['basename'];
}
//----------------------------------------------------------------------------
function CleanNameOnly($name)
{
	global $dup;	

	$nameParts = pathinfo($name);

	if($dup->Settings['namecleanup']==1)	
		return CleanUpName($nameParts['basename']);	
	else
		return CleanUpNameFiltersOnly($nameParts['basename']);
}
//----------------------------------------------------------------------------
?>