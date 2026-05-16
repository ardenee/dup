<?php
include("dup.php");
$dup      = new dup($profile);
$Accounts = $dup->GetAccounts(); 
LogStr("Home", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Accounts</TITLE>
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
 <BODY>
 
  <?php echo PageHeader(); ?>  
   <DIV ID=SubMenu><span><a href="javascript:AddAccount();"><I class=icon-plus-sign></I> <b>Add Account</b></A></span></DIV>
  </HEADER>

  <DIV id=contentWrapper>
   <DIV id=content>
    <H1 class=title>Accounts</H1>
    <TABLE id=showListTable class=tablesorter border=0 cellSpacing=1 cellPadding=0>
     <THEAD>
      <TR>
       <TH class=nowrap>id</TH>
       <TH>Name</TH>
       <TH>Level</TH>
       <TH>Options</TH>
      </TR>
     </THEAD>
	 

	 
     <TBODY>
 
<?php
foreach($Accounts as $key => $value)
{
	$type  = "User";
		
	switch($value['level'])
	{
		case   0: $type = "User";       break;
		case   1: $type = "Admin";      break;
		default : $type = "User";
	}
	
	echo "  <TR>\n";
	echo "    <TD class=nowrap align=middle>".$value['id']."</TD>\n";
	echo "    <TD class=nowrap align=middle>".$value['name']."</TD>\n";
	echo "    <TD class=nowrap align=middle>".$type."</TD>\n";
	
	if($value['name']=="admin")
	{
		echo "    <TD align=middle><a href=\"javascript:EditAccount('".$value['id']."', '".$value['name']."')\"><I class=icon-edit title=\"Edit\"></I></TD>\n";
	}
	else
	{	
		echo "    <TD align=middle><a href=\"javascript:DeleteAccount('".$value['id']."', '".SafeJavaValue($value['name'])."')\"><I class=icon-trash title=\"Delete\"></I></a> /";
		echo "	   <a href=\"javascript:EditAccount('".$value['id']."', '".$value['name']."')\"><I class=icon-edit title=\"Rename\"></I></a></b></TD>\n";
	}		
	
	echo "  </TR>\n";
}
?> 

     </TBODY> 
    </TABLE> 
	

	
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  
<?php echo PageFooter(); ?>
    <script type="text/javascript">  
	function DeleteAccount(id, account)
	{
		var answer=confirm('Are you sure you want to Delete account \''+account+'\' ?');
		
		if (answer==true)
		  window.open('delete-accounts.php?id='+id, '_self');
	}
    </SCRIPT>  
	
  <SCRIPT type="text/javascript">
  
	function AddAccount()
	{
		var uname=prompt("Enter account Name",     "newuser");
		var  pass=prompt("Enter account Password", "newpassword");
		
		if (uname!=null && uname!="" && pass!=null && pass!="")
		{
			window.location = "add-accounts.php?uname="+uname+"&pass="+pass;
		}		
	}	
  </SCRIPT>
  
  <SCRIPT type="text/javascript">
  
	function EditAccount(id, u)
	{
		var uname=prompt("Enter new account Name", u);
		var  pass=prompt("Enter new account Password", "");
		
		if (uname!=null && uname!="" && pass!=null && pass!="")
		{
			window.location = "edit-accounts.php?id="+id+"&uname="+uname+"&pass="+pass;
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