<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Help", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Help</TITLE>
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
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.form-2.92.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVconfig").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY>
  <?php echo PageHeader(); ?> 
   <DIV id=SubMenu><SPAN><A HREF="config-help.php">Help</A> | <A HREF="config-general.php">General</A> | <A HREF="config-profiletypes.php">Profile Types</A></SPAN></DIV>
  </HEADER>
  <DIV id="contentWrapper">
   <DIV id="content">
    <h1 class="title">Configuration</h1>
     <DIV id="summary" class="align-left">
      <table class="infoTable">
       <tr><td class="infoTableHeader">dup Version: </td><td><?php echo $dup->Settings['version']; ?></td></tr>
       <tr><td class="infoTableHeader">Database: </td><td><?php echo ($dbfile==0)?"(MySQL) dup":"(SQLite) ".$dbdir."/dup.db"; ?></td></tr>
	   
	   <?php
		$value = array();	   
	   
		try
		{
		
			if($dbfile==0)
			{
				$query = "SELECT table_schema, (sum( data_length + index_length )) As size FROM information_schema.TABLES WHERE table_schema = 'dup' GROUP BY table_schema;";
					
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);
				
				while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				{
					$value = $row; 
					break;
				}
			}
			else
			{
				if(file_exists($dbdir."/dup.db"))
					$value['size'] = $dup->format_bytes($dup->fsize($dbdir."/dup.db"));//filesize($dbdir."/dup.db") / 1204 / 1024;
				else
					$value['size'] = "Unknown";
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}
		
		echo "<tr><td class=\"infoTableHeader\">dup Database size: </td><td><span title=\"".number_format($value['size'])." bytes\">".$dup->format_bytes($value['size'])."</span></td></tr>\n";	     
	   ?>
	   
	   <tr><td class="infoTableHeader">PDO SQL Version: </td><td><?php echo $dblink->getAttribute(PDO::ATTR_SERVER_VERSION); ?></td></tr>
       <tr><td class="infoTableHeader">PHP Version: </td><td><?php echo phpversion(); ?></td></tr>
       <tr class="infoTableSeperator"><td class="infoTableHeader"><i class="icon16-sb"></i> Homepage </td><td><a HREF="http://www.arden.ie/dup" target="_blank">http://www.arden.ie/dup</a></td></tr>
       <tr><td class="infoTableHeader"><i class="icon16-web"></i> Forums </td><td><a href="http://www.arden.ie/forums/viewforum.php?f=1" target="_blank">http://www.arden.ie/forums/viewforum.php?f=1</a></td></tr>
	   
	   
	   <tr class="infoTableSeperator"><td class="infoTableHeader">Backups</td>&nbsp;<td></td></tr>
	   <tr><td class="infoTableHeader"><td>
	   <?php
	    $dir     = dir("backup");
		$impdata = "";
	   
	    while(($file = $dir->read()) !== false)
		{
			if(strlen($file) < 5 || substr($file, -4) != '.txt')
				continue;
				
			$cwd = getcwd();
				
			echo "<B>backup/$file</B> (<a href=\"javascript:DeleteFile('$cwd/backup/$file');\">Delete</a>)<BR>";
			
			$impdata = "<BR><a href=\"backup.php?action=import\">Import Above Files</a>\n";
		}
		
		echo $impdata;		
		$dir->close();
	   ?>
	   </td></td></tr>
	   
      </table>
     </DIV>
     <DIV class="container padding" style="width: 600px;">
      <table class="infoTable">
       <tr>
        <td><a HREF="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=7RVSGJHGCYCUW" onclick="window.open(this.href); return false;"><img SRC="images/btn_donate_LG.gif" alt="[donate]" /></a></td>
        <td>dup is free, but you can contribute by giving a <b><a HREF="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=7RVSGJHGCYCUW" onclick="window.open(this.href); return false;">donation</a></b>.</td>
       </tr>
      </table>
     </DIV>
    </DIV> <!-- /content -->
   </DIV> <!-- /contentWrapper -->
   <?php echo PageFooter(); ?>
 </BODY>
</HTML> 

<script type="text/javascript">  
function DeleteFile(file)
{
	var answer=confirm('Are you sure you want to delete the file?\n\''+file+'\'');
	
	if (answer==true)
		window.open('delete.php?file='+file, '_self');
}
</script>




