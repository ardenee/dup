<?php
include("dup.php");
$dup      = new dup($profile);
$Profiles = $dup->GetProfiles("id"); 
LogStr("Home", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Home</TITLE>
  <META CHARSET="utf-8">
  <META CONTENT="IE=edge,chrome=1" HTTP-EQUIV="X-UA-Compatible">
  <META NAME="robots" CONTENT="noindex">
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
  <SCRIPT TYPE="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVhome").addClass("active");
        });
    //-->
    </SCRIPT>	
    <script TYPE="text/javascript">  
	function handleClick(id, act, title){
	  if(act=='Delete')
	  {
		var answer=confirm('Are you sure you want to \''+act+'\' profile \n\''+title+'\' ?');
		if (answer==true)
		{
		  window.open('delete-profile.php?profile='+id, '_self');
		}
	  }
	  else if(act=='Edit')
	  {
		window.open('profile-settings.php?profile='+id, '_self');
	  }
	  else if(act=='Scan')
	  {
		var answer=confirm('Are you sure you want to \''+act+'\' profile \n\n\''+title+'\' ?\n\nThis may take some time but will run in the background.');
		if (answer==true)
		  window.open('scan-files.php?profile='+id, '_self');
	  }
	}
    </SCRIPT>   
    <SCRIPT TYPE="text/javascript" charset="utf-8">
    <!--
	$.tablesorter.addParser({ 
		id: 'eps',
		is: function(s) {
			return false; 
		},
		format: function(s) {
			match = s.match(/^(.*)/);

			if (match == null || match[1] == "?")
				return -10;

			var nums = match[1].split(" / ");			
			nums[0]=parseInt(nums[0].replace(/\,/g,''),10);
			nums[1]=parseInt(nums[1].replace(/\,/g,''),10);

			if (parseInt(nums[0]) === 0)
				return parseInt(nums[1]);

			var finalNum = parseInt((nums[0]/nums[1])*1000)*100;
			if (finalNum > 0)
				finalNum += parseInt(nums[0]);

			return finalNum;
		},
		type: 'numeric'
	});

	$(document).ready(function(){ 

		$("#showListTable:has(tbody tr)").tablesorter({
			sortList: [[1,1]],
			textExtraction: {
				4: function(node) { return $(node).find("div").text(); },
			},
			widgets: ['saveSort', 'zebra', 'stickyHeaders'],
			headers: {
				0: { sorter: 'isoDate' },
				4: { sorter: 'eps' }
			}
		});

	});
	//-->
    </SCRIPT>
	<META NAME=GENERATOR CONTENT="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY ONLOAD="autoupdate();">
 
  <?php echo PageHeader(); ?>  
   <DIV ID=SubMenu><span><A href="profile-add.php"><b>Add Profile</b></A></span></DIV>
  </HEADER>

  <DIV id=contentWrapper>
   <DIV id=content>
    <H1 class=title>Profiles</H1>
    <TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
     <THEAD>
      <TR>
       <TH class=nowrap>Last Run</TH>
       <TH>Profile</TH>
       <TH>Type</TH>
       <TH>Hash</TH>
       <TH>Dups/Files</TH>
       <TH>Options</TH>
       <TH>Status</TH>
      </TR>
     </THEAD>
	 
     <TFOOT>
      <TR>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Last Run</TH>
       <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Profile</TH>
	   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Type</TH>
	   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Hash</TH>
	   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Dups/Files</TH>
	   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Options</TH>
	   <TH style="color: black; background-color:#dfdacf; border-bottom: 1pt solid black; border-top: 1pt solid black; border-left: 1pt solid black; border-right: 1pt solid black;">Status</TH>
      </TR>
     </TFOOT>
	 
     <TBODY>
 
<?php
foreach($Profiles as $key => $value)
{
	$hash  = "Size";
	$newval = $value['files'] - $value['dups'];
	
	switch($value['crc32'])
	{
		case   0: $hash = "Size";       break;
		case   1: $hash = "CRC32";      break;
		case   2: $hash = "CRC32 Full"; break;
		default : $hash = "Size";
	}
	
	echo "  <TR>\n";
	echo "    <TD class=nowrap align=middle>".$value['lastrun']."</TD>\n";
	echo "    <TD class=tvShow><A href=\"profile-view.php?profile=".$value['id']."\">".$value['path']."</A></TD>\n";
	echo "    <TD class=nowrap align=middle>".GetProfileType($value['type'])."</TD>\n";
	echo "    <TD align=middle>$hash</TD>\n";	
	echo "    <TD align=middle> <div style=\"DISPLAY: none\">".$value['files']." / ".$value['dups']."</div>\n";
	echo "     <DIV style=\"POSITION: relative\" id=progressbar".$value['id']."></DIV>\n";
	echo "     <SCRIPT type=text/javascript>\n";
	echo "        <!--\n";
	echo "            $(function() {\n";
	echo "              $(\"#progressbar".$value['id']."\").progressbar({\n";
	echo "                   value: parseInt($newval) * 100 / parseInt(".$value['files'].")\n";
	echo "               });\n";
	echo "              $(\"#progressbar".$value['id']."\").append( \"<div class='progressbarText'>".number_format($value['dups'])." / ".number_format($value['files'])."</div>\" )\n";
	echo "            });\n";
	echo "        //-->\n";
	echo "        </SCRIPT>\n";
	echo "    </TD>\n";
	echo "    <TD align=middle>".$value['runs']." / <a href=\"javascript:handleClick('".$value['id']."', 'Delete', '".SafeJavaValue($value['path'])."')\"><I class=icon-trash title=\"Delete\"></I></a> / <a href=\"javascript:handleClick('".$value['id']."', 'Edit', '')\"><I class=icon-edit title=\"Edit\"></I></a> / <a href=\"javascript:handleClick('".$value['id']."', 'Scan', '".SafeJavaValue($value['path'])."')\"><I class=icon-eye-close title=\"Scan\"></I></a></b></TD>\n";
	$stats = GetStat($value['id']);
	echo "    <TD align=middle><span id=\"profile".$value['id']."\" title=\"".$stats['data']."\">".$stats['value']."</span></TD>\n";// get profile status from here, add field?
	echo "  </TR>\n";
}
?> 

     </TBODY> 
    </TABLE> 
	

	
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  
<?php echo PageFooter(); ?>
  
  <SCRIPT type="text/javascript">
  function update()
{
    $.ajax(
        {
            type: "POST",
            url:  "status-update.php",
            cache: false,

            success: function(response)
            {
			  response = $.parseJSON(response);
			  //alert(response);
			  
			  for (var i = 0; i < response.length; i++) 
			  {
			  	 document.getElementById('profile'+response[i]['profile']).innerHTML = response[i]['value'];
				 document.getElementById('profile'+response[i]['profile']).setAttribute('title', response[i]['data']);
				 
				if(document.getElementById('profile'+response[i]['profile']).innerHTML=="Refreshing")
				{
					window.location = "scan-reset.php?profile="+response[i]['profile'];			
				}				 
			  }			  			
            },
			error: function (xhr, ajaxOptions, thrownError, response) {
				//alert(xhr.status);
				//alert(thrownError);
				//alert(response);
			  }
         });
}
</SCRIPT>

  <SCRIPT type="text/javascript">
  function update()
{
	if(document.getElementById('profile'+response[i]['profile']).innerHTML = response[i]['value'];
}
</SCRIPT>
  <SCRIPT type="text/javascript">
function autoupdate()
{
	var myDiv = document.getElementById("showListTable");
	var nodes = myDiv.getElementsByTagName("span");
	
	for (var index = 0; index < nodes.length; index++) 
	{
		var node = nodes[index];
		
		if (node.id.length>0) 
		{
			if(document.getElementById(node.id).innerHTML!="Idel" && document.getElementById(node.id).innerHTML!="")
			{
				//alert('updating');
				update();
				setTimeout(autoupdate, 3000);
				break;
			}
		}
	}
}
</SCRIPT>
<?php
function GetProfileType($type)
{
	global $dblink;
	$value = "Unknown";
	
	try
	{
		$query = "SELECT name FROM profiletypes where id=$type;";
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value = $row['name'];
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
	
	return $value;
}
?> 
 </BODY>
</HTML> 