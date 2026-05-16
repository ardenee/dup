<?php
include("dup.php");
$dup = new dup($profile);
LogStr("View Profile '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
$tsize   = 0;
$page    = 1;

if(isset($_GET['page']))
	$page=$_GET['page'];	
?>
<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - View Profile</TITLE>
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
  <SCRIPT type="text/javascript" src="files/tableClick.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>
    <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
	
  <SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVhome").addClass("active");

            $("a.confirm").bind("click",function(e) {
                e.preventDefault();
                var target = $( this ).attr('href');
                if ( confirm("Are you sure you want to " + $(this).text() + "?") )
                    location.href = target;
                return false;
            });

        });
    //-->
    </SCRIPT>

  <META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>

 <BODY onload="displayAll()"> 
  <?php echo PageHeader(); ?>
   <DIV id=SubMenu>
    <SPAN>
	<A href="profile-add.php">Add Profile</A> | 
	<A href="profile-settings.php?profile=<?php echo $profile; ?>" >Profile Settings</A> | 
	<A href="<?php echo "javascript:ScanProfile('$profile', 'Scan', '".SafeJavaValue($dup->Profile['path'])."');"; ?>">Rescan Profile</A> | 
	<A href="<?php echo "javascript:DeleteProfile('$profile', 'Delete', '".SafeJavaValue($dup->Profile['path'])."');";?>">Delete Profile</A>
	</SPAN>
   </DIV>
  </HEADER>

<DIV id=contentWrapper>
 <DIV id=content>
  <H1 class=title>Files for <B><?php echo $dup->Profile['path'] ?></b></H1>
  
<SCRIPT type="text/javascript" charset="utf-8">
<!--
$(document).ready(function(){ 
    $("#showListTable").tablesorter();
});
//-->
</SCRIPT>

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
 <form id=dups name=dups method=post action="action-files.php">
  <input type=hidden name=profile value=<?php echo $profile; ?>>
  <input type=hidden name=from value="profile-view.php?profile=<?php echo $profile; ?>">
  <input type=hidden id=act name=act value=none>
  <input type=hidden id=sel name=sel value=1>
 <TBODY>
  
<?php  
 // php stuff here
$tstart	= $dup->GetPage($page);

if(strlen($tstart["start"])>0)
	$start  = $tstart["start"];
else 
{
	$tstart	= $dup->GetPage($page-1);
	
	if(strlen($tstart["start"])>0 and strlen($tstart["end"])>0)
		$start = $tstart["end"];	
}
	
if(strlen($start<=0)) // bad read reset to page 1
	$start = 0;
	
$parent     = $start;
$parent++;
$DUPS       = $dup->GetDups($profile, $start); 
$TBODYNum   = 1;	
$ChildNum   = 0;
$pid        = 0;
$stats      = $DUPS['stats'];
$dupsize    = 0;
$TotalItems = 0;
//p($stats);

foreach($DUPS as $key=>$value)
{				
	if($key=="stats")
		continue;
		
	$ChildNum  = 0;
	$data      = "";	
	$class     = (($parent%2==0)?"subparent":"mainparent");	
	$value     = sortByValue($value, 'link', 'file');		

	foreach($value as $k=>$v) // for each match in block of hash
	{	
		if((IsMovedFile($v['file'], array($dup->Profile['mpath'])) or count($value)==1) and $dup->Settings['displaymoved']==0)
		{	
			unset($value[$k]);
			sort($value);

			if(count($value)==1)
				$data = "";
		}	
	}
	
	foreach($value as $k=>$v) // for each match in block of hash
	{					
		// verify sqlite db has been updated and now uses this format!
		$date = new DateTime("@".$v['fdate']."");	
		$icon  = (strlen($v['link'])>0)?"<I class=icon-magnet></I>":"<I class=icon-file></I>";
		(($k!=0)?$dupsize+=$v['size']:$dupsize+=0);// add up children only			
		$tsize += $v['size'];	// total size			

		if($k==0) // main parent
		{
			$b1 = "<b>";
			$b2 = "</b>";
			$count = " &nbsp;&nbsp (".(count($value)-1).")";
		}
		else
		{
			$b1 = "";
			$b2 = "";
			$class = "subchild";
			$count = "";
		}
		
		if($ChildNum%2==0 and $ChildNum!=0 and $k!=0) // child
			$class = "child";	

		if($k==1) // child
			$data.=" <TBODY id='$TBODYNum' class='$class'>\n";	

		$file = DropProfile($v['file'], $dup->Profile['path']);		

		$pid = (($k==0)?($parent):$ChildNum); // parent number, change to $start+$num? // fix this needs total object from above function		
		$data.="  <TR class=$class>\n";
		$data.="   <TD class=$class align=\"right\" nowrap>$b1$pid$b2$count</TD>\n";
		
		if($dup->Settings['download']==1)		
			$data.="   <TD class=$class nowrap><span title='".(($icon=="<I class=icon-file></I>")?"File":"Symlink")."' >$icon</span> &nbsp; <input type=checkbox id=".(($k==0)?"pri":"sec")." name=\"files[]\" value=".$v['id'].(($k==0)?"":" CHECKED")."> <a id=\"Download\" href=\"javascript:Download('".$v['id']."');\" alt=\"Download\" title=\"Download\">".$v['id']."</a></TD>\n";
		else
			$data.="   <TD class=$class nowrap><span title='".(($icon=="<I class=icon-file></I>")?"File":"Symlink")."' >$icon</span> &nbsp; <input type=checkbox id=".(($k==0)?"pri":"sec")." name=\"files[]\" value=".$v['id'].(($k==0)?"":" CHECKED")."> ".$v['id']."</TD>\n";
		
		if(($k==0))
		{
			if($dup->Settings['download']==1)
				$data.="   <TD class=tvShow><a title=\"".$v['file']."\" alt=\"".$v['file']."\" id=\"Expand\" onclick=\"displayRow('$TBODYNum');\" style=\"cursor:hand; cursor:pointer\"><B>".ChopName($file)."</B></a></TD>\n";
			else
				$data.="   <TD class=tvShow><a title=\"".$v['file']."\" alt=\"".$v['file']."\" id=\"Expand\" onclick=\"displayRow('$TBODYNum');\" style=\"cursor:hand; cursor:pointer\"><B>".ChopName($file)."</B></a></TD>\n";
		}
		else
			$data.="   <TD class=tvShowchild><span title=\"".$v['file']."\">".ChopName($file)."</span></TD>\n";
		
		$data.="   <TD class=$class align=right nowrap><span title=\"".number_format($v['size'])." bytes\">".$dup->format_bytes($v['size'])."</span></TD>\n";	
		$data.="   <TD class=$class align=center nowrap><span title=\"".$v['fdate']."\">".$date->format('Y-m-d H:i:s')."</TD>\n";	
		$data.="   <TD class=$class align=center nowrap>".(($v['crc32']=="0 Filled")?"<font color=red><b>".$v['crc32']."</b></font>":$v['crc32'])."</TD>\n";
		$data.="   <TD class=$class nowrap><center><a href=\"javascript:DeleteFile('$profile', '".$v['id']."', '".JavaScriptSafeString($v['file'])."');\"><I class=icon-trash title=\"Delete '$file'\"></I></a> <b>/";
		$data.=" <a href=\"action-files.php?profile=$profile&act=Rename&files=".$v['id']."\"> <I class=icon-edit title=\"Rename '".DropProfile($file, $dup->Profile['path'])."'\"></I></a> /";
		$data.=" <a href=\"action-files.php?profile=$profile&act=Move&files=".$v['id']."\"> <I class=icon-share title=\"Move '".DropProfile($file, $dup->Profile['path'])."'\"></I></a> /</b>";
		$data.=" <a href=\"action-files.php?profile=$profile&act=Link&files=".$v['id']."\"> <I class=icon-magnet title=\"Link '".DropProfile($file, $dup->Profile['path'])."'\"></I></a></center></TD>\n";
		$data.="  </TR>\n";
	
		$ChildNum++; // number of childer per parent	
		$TotalItems++;
	}
	
	$data.="  </TBODY>\n";
	$TBODYNum++;
	
	if(count($value)==1) // only parent, drop
		$data = "";
	else  	
		$parent++; // number of parents
		
	echo $data;
}

$dup->UpdatePages($page, $start, ($parent-1));

function sortByValue($array, $sortOn, $sortOn2, $direction = SORT_ASC) 
{
    $sort  = array();
    $sort2 = array();
        
    foreach ($array as $key => $value)  {
        $sort[$key]  = $value[$sortOn];
        $sort2[$key] = $value[$sortOn2];
    }

    array_multisort($sort, $direction, $sort2, $direction, $array);

    return $array;
}

?>
  </form> 
  </TBODY>
 <TFOOT>
 <TR>
 
 <!--#contentWrapper { background: url("/dup/images/bg.png") repeat fixed 0 0 transparent; }-->
 
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1 align=middle><a href="javascript:displayAll()"><font color=Black>Expand</font></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1 align=middle><a href="javascript:checkedAll('dups')"><font color=Black>Inverse</font></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1 align=left>Possible listed amount of data to clean up :</TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1 nowrap><?php echo $dup->format_bytes($dupsize) ?></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1>Total Profile Dups:</TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1><span title="Profile Dups"><?php echo $dup->Profile['dups'] ?></span></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 colSpan=1 nowrap><center><a href="javascript:handleClick('dups', 'Delete')"><I class=icon-trash title="Delete Selected"></I></a> / <a href="javascript:handleClick('dups', 'Rename')"><I class=icon-edit title="Rename Selected"></I></a> / <a href="javascript:handleClick('dups', 'Move')"><I class=icon-share title="Move Selected"></I></a> / <a href="javascript:handleClick('dups', 'Link')"><I class=icon-magnet title="Link Selected"></I></a></center></TH>
 </TR>
 <TR>
 <TR>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 nowrap><?php echo (($page>1)?" <a title=Preivous href=\"profile-view.php?profile=$profile&page=".($page-1)."\"><I class=icon-chevron-left title=\"Previous\"></I></a>":""); ?></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1><span title="Displayed Dups"><?php echo count($DUPS)-1; ?></span></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1>Total listed data size:</TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 nowrap><?php echo $dup->format_bytes($tsize); ?></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1>Matched on: <?php echo ($dup->Profile['crc32']==0?"Size":"CRC32"); ?></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1><span title="Displayed Vars"><?php echo $TotalItems; ?></span></TH>
   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;" rowSpan=1 nowrap><?php echo (($parent < $dup->Profile['dups'])?"<a title=Next href=\"profile-view.php?profile=".$profile."&page=".($page+1)."\"><I class=icon-chevron-right title=\"Next\"></I></a>":""); ?></TH>
 </TR>
 </TR>
 </TFOOT>
  
 </TABLE> 
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  <?php echo PageFooter(); ?>
 </BODY>

<script type="text/javascript">   
function displayRow(num){ 
  var row = document.getElementById(num);  
    if (row.style.display == '')
      row.style.display = 'none';
    else
      row.style.display = '';
}

function displayAll()
{  
  var i=1;
  var row;
  for(i=0;i<=<?php echo $parent; ?>;i++)
  {
    row = document.getElementById(i); 
	
	if(row!=null)
	{	
    if (row.style.display == '')   
      row.style.display = 'none'; 	
    else  
      row.style.display = '';   
	  }
  }
} 

var checked = true;  
function checkedAll(val) 
{  
	var type = document.getElementById('sel').value;	
	
	if(type==1)// dups off/on
	{
		for (var i = 0; i < document.getElementById(val).elements.length; i++)  
		{
			if(document.getElementById(val).elements[i].id == 'pri')
				document.getElementById(val).elements[i].checked = checked;
		}	
		
		document.getElementById('sel').value = 2;	
	}	
	else if(type==2)// all off/on
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
		
		document.getElementById('sel').value = 3;
	}	
	else if(type==3)// pri off/on
	{
		for (var i = 0; i < document.getElementById(val).elements.length; i++)  
		{
			if(document.getElementById(val).elements[i].id == 'pri')
				document.getElementById(val).elements[i].checked = checked;
		}	
		
		document.getElementById('sel').value = 4;
	}
	else if(type==4)// all off/on
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
		
		document.getElementById('sel').value = 1; 	
	}
} 

function DeleteFile(profile, fileid, file){
  var answer=confirm('Are you sure you want to Delete...\n\n'+file);
  if (answer==true)
  {
    window.open('action-files.php?act=Delete&profile='+profile+'&files='+fileid, '_self')
  }
}

function handleClick(form, act){
  var answer=confirm('Are you sure you want to \''+act+'\' all the selected files?');
  if (answer==true)
  {
    document.getElementById('act').value = act;
    document.getElementById(form).submit();
  }
}
</script> 

    <script type="text/javascript">  
	function DeleteProfile(id, act, title){
	  if(act=='Delete')
	  {
		var answer=confirm('Are you sure you want to \''+act+'\' profile \n\''+title+'\' ?');
		if (answer==true)
		  window.open('delete-profile.php?profile='+id, '_self');
	  }
	  else
		window.open('profile-settings.php?profile='+id, '_self');
	}
    </SCRIPT>  
	
    <script type="text/javascript">  
	function ScanProfile(id, act, title){
	  if(act=='Scan')
	  {
		var answer=confirm('Are you sure you want to \''+act+'\' profile \n\n\''+title+'\' ?\n\nThis may take some time but will run in the background.');
		if (answer==true)
		  window.open('scan-files.php?profile='+id, '_self');
	  }
	}
    </SCRIPT> 
	
    <script type="text/javascript">  
	function Download(file) 
	{
       newwindow=window.open("download-files.php?file="+file,"Download File",'height=50,width=100,menubar=no,resizable=no,scrollbars=no,titlebar=no,toolbar=no');
    }
    </SCRIPT> 	

</HTML> 
<?php
//--------------------------------------------------------------------------
function DropProfile($full, $part)
{	
	if(strpos($full, $part)===0)
		return trim(substr($full, strlen($part), strlen($full)), "//");
	else
		return $full;
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
//--------------------------------------------------------------------------
?>