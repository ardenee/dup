<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Dup Search", 1, __FILE__, __LINE__, __FUNCTION__);
$search  = "";
$global  = "";

$case = false;

if(isset($_POST['search']))
	$search = $_POST['search'];
	
if(isset($_POST['case']))
	$case = true;
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - dup Search</TITLE>
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

 <BODY onload="displayAll()"> 
  <?php echo PageHeader(); ?>
  </HEADER>

<DIV id=contentWrapper>
 <DIV id=content>
 <form id=searchform name=searchform method=post action=search-dup.php>
 <H1 class=title>dup Search</H1>
  <H1 class=title>Search : <input type=Edit name=search size=30 value="<?php echo $search ?>"> &nbsp; 
  Global Search :
  <?php
	$Profiles = $dup->GetProfiles("path"); 
	
    echo "<select name=\"profile\" id=\"profile\" class=\"input-medium\" style=\"width: 270px\">\n";
	echo " <option value=0".(($profile==0)?" SELECTED":"")."> Global - All Profiles</option>\n";
	
	foreach($Profiles as $key => $val)
		echo " <option value=".$val['id'].(($val['id']==$profile)?" SELECTED":"")."> (".$val['id'].") ".$val['path']." </option>\n";
	
	echo "</select>\n";	
	
	echo "&nbsp;Case Sensitive : <input type=checkbox name=case".(($case==true)?" CHECKED":"").">";
	
 ?> 
  &nbsp;
  <input type=Submit value=Search></B></H1>
 </form> 
<SCRIPT type="text/javascript" charset"=utf-8">
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
   <input type="hidden" name="profile" value="<?php echo $profile; ?>">
   <input type="hidden" id="act" name="act" value="none">
  <TBODY>
  
<?php  
 
if(strlen($search)>0)
{
	$ProfileSearcheSettings = array();	
	$ProfileSettings        = $dup->GetProfiles('path');
	
	foreach($ProfileSettings as $key => $val)
	{
		$id = $val['id'];
			
		if($profile == $id or $profile == 0)	
		{		
			$ProfileSearcheSettings[$id]['id'] = $val['id'];
			$ProfileSearcheSettings[$id]['crc32'] = $val['crc32'];
			$ProfileSearcheSettings[$id]['mpath'] = $val['mpath'];
		}
	}	

	$num   = 1;
	$tsize = 0;
	$total = 0;

	foreach($ProfileSearcheSettings as $key => $val)
	{
		$type       = ($val['crc32']==0)?"size":"crc32";
		$additional = "";
		
		$search2 = ($case==false)?strtolower($search):$search;		
		$sercol  = ($case==false)?" LOWER(file) ":" file ";
		
		if($dup->Settings['displaymoved']==0)
			$additional = " AND file NOT LIKE '%".SafeSQLValue($val['mpath'])."%'";
		
		if($val['crc32']==0)
			$query = "SELECT size, COUNT(size) FROM files WHERE profile=$key $additional AND $sercol LIKE '%".SafeSQLValue($search2)."%' GROUP BY size HAVING COUNT(size)>1 ORDER BY file ASC LIMIT $start, 500;";
		else
			$query = "SELECT crc32, COUNT(crc32) AS cnt FROM files WHERE crc32!='unknown' AND profile=$key $additional AND $sercol LIKE '%".SafeSQLValue($search2)."%' GROUP BY crc32 HAVING COUNT(crc32)>1 ORDER BY files.file ASC LIMIT $start, 500;";			
			
		echo "<TR class=nowrap>\n";
		echo " <td colspan=7><b><a href=\"profile-view.php?profile=$key\">".$ProfileSettings[$key]['path']."</a></td>\n";
		echo "</TR>\n";	
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt   = $dblink->query($query);
			$files = $sdt->fetchAll();				
			$total = count($files);
					
			foreach($files as $key2 => $row)
			{	
				$query = "SELECT * FROM files WHERE $type=".$row[$type]." AND profile=$key;";
				
				if($type="crc32") // string instead of number
					$query = str_replace($row[$type], "'".$row[$type]."'", $query);
			
				try
				{
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
					$sdt2 = $dblink->query($query);					
					
					while($row2 = $sdt2->fetch(PDO::FETCH_ASSOC))
					{
						$sfid = $row2['id'];
						$icon  = (strlen($row['link'])>0)?"<I class=icon-magnet></I>":"<I class=icon-file></I>";								
											
						echo "<TR class=nowrap>\n";
						echo " <TD class=nowrap>".$num."</TD>\n";					
						
						//echo " <TD class=nowrap><span title='".(($icon=="<img src='images/sfile16.png'>")?"Symlink":"File")."' >$icon</span> &nbsp; <input type=checkbox id=\"sec\" name=\"files[]\" value=\"".$row2['id']."\"> ".$row2['id']."</TD>\n";
						
						if($dup->Settings['download']==1)		
							echo " <TD class=nowrap><span title='".(($icon=="<I class=icon-file></I>")?"File":"Symlink")."' >$icon</span> &nbsp; <input type=checkbox id=\"sec\" name=\"files[]\" value=$sfid CHECKED> <a id=\"Download\" href=\"javascript:Download('$sfid');\" alt=\"Download\" title=\"Download\">$sfid</a></TD>\n";
						else
							echo " <TD class=nowrap><span title='".(($icon=="<I class=icon-file></I>")?"File":"Symlink")."' >$icon</span> &nbsp; <input type=checkbox id=\"sec\" name=\"files[]\" value=$sfid CHECKED> $sfid</TD>\n";
						
						echo " <TD class=nowrap><span title=\"".$row2['file']."\">".ChopName(highlightkeyword($row2['file'], $search2))."</span></TD>\n";
						echo " <TD class=nowrap><center><span title=\"".number_format($row2['size'])." bytes\">".$dup->format_bytes($row2['size'])."</span></center></TD>\n";
						$date = new DateTime("@".$row2['fdate']."");
						echo " <TD class=nowrap><span title=\"".$row2['fdate']."\">&nbsp;".$date->format('Y-m-d H:i:s')."</span></TD>\n";	
						echo " <TD class=nowrap>".$row2['crc32']."</TD>\n";
						echo "<TD align=middle><center><a href=\"javascript:DeleteFile('".$val['id']."', '".JavaScriptSafeString($row2['file'])."', '$sfid');\"><I class=icon-trash title=\"Delete '".$row2['file']."'\"></I></a> <b>/";
						echo "                         <a href=\"action-files.php?profile=".$val['id']."&act=Rename&files=$sfid\"> <I class=icon-edit title=\"Rename '".DropProfile($row2['file'], $val['path'])."'\"></I></a> /";
						echo "                         <a href=\"action-files.php?profile=".$val['id']."&act=Move&files=$sfid\"> <I class=icon-share title=\"Move '".DropProfile($row2['file'], $val['path'])."'\"></I></a></b>";
						echo "</TR>\n";
						
						$num++;
						$tsize+=$row2['size'];
						
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
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
	}

/*
    [0] => 16628
    [1] => /volume1/Films/DVD-Rip/T/the.tempest.cd2.avi
    [2] => 732076032
    [3] => 2011-08-12 22:45:15
    [4] => 231B12B0
    [5] => 1		
*/		
}
function highlightkeyword($str, $search) {
    $occurrences = substr_count(strtolower($str), strtolower($search));
    $newstring = $str;
    $match = array();
 
    for ($i=0;$i<$occurrences;$i++) {
        $match[$i] = stripos($str, $search, $i);
        $match[$i] = substr($str, $match[$i], strlen($search));
        $newstring = str_replace($match[$i], '<b>'.$match[$i].'</b>', strip_tags($newstring));
    }

    return $newstring; 
}
?>

  </TBODY>
  </form> 
  <TFOOT>
   <TR>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;"><input type=Button Value=Expand OnClick="displayAll()"></TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;"><input type=Button Value=Inverse OnClick="checkedAll('search')"></TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Listed Data size :</TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;"><?php echo $dup->format_bytes($tsize) ?></TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Total Found:</TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;"><?php echo $total ?></TH>
    <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;"><center>
   <?php 
	echo "<a href=\"javascript:handleClick('search', 'Delete')\"><I class=icon-trash title=\"Delete Selected\"></I></a> /";
	echo "<a href=\"javascript:handleClick('search', 'Rename')\"><I class=icon-edit title=\"Rename Selected\"></I></a> / ";
	echo "<a href=\"javascript:handleClick('search', 'Move')\"><I class=icon-share title=\"Move Selected\"></I></a></b>\n";
    ?></center></TH>
  </TR>
 </TFOOT>
  
 </TABLE> 
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
<?php echo PageFooter(); ?>
 </BODY>

<script type="text/javascript">   
var checked = true;  
function checkedAll(val) 
{  
	if (checked == false)  
	  {checked = true}  
	else  
	  {checked = false}  
	for (var i = 0; i < document.getElementById(val).elements.length; i++)  
  {
    if(document.getElementById(val).elements[i].id == 'sec')
	   document.getElementById(val).elements[i].checked = checked;  
   } 
} 
</script>
<script type="text/javascript">  
function Download(file) 
{
   newwindow=window.open("download-files.php?file="+file,"Download File",'height=50,width=100,menubar=no,resizable=no,scrollbars=no,titlebar=no,toolbar=no');
}
</script>
<script type="text/javascript">  
function DeleteFile(profile, file, fid){
  var answer=confirm('Are you sure you want to Delete...\n\n'+file+' ?');
  if (answer==true)
  {
    window.open('action-files.php?act=Delete&profile='+profile+'&files='+fid, '_self');
  }
}
</script>
<script type="text/javascript">  
function handleClick(form, act){
  var answer=confirm('Are you sure you want to \''+act+'\' all the selected files?');
  if (answer==true)
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
	return trim(substr($full, strlen($part), strlen($full)), "//");
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
//--------------------------------------------------------------------------
function JavaScriptSafeString($value)
{
	return str_replace("'", "\'", $value);
}
?>