<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Help", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DupJs($value)
{
    return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}
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
		<tr><td class="infoTableHeader">dup Version: </td><td><?php echo DupHtml(isset($dup->Settings['version']) ? $dup->Settings['version'] : ""); ?></td></tr>
		<tr><td class="infoTableHeader">Database: </td><td><?php echo DupHtml(($dbfile == 0) ? "(MariaDB) dup" : "(SQLite) " . $dbdir . "/dup.db"); ?></td></tr>
	   
	   <?php
		$value = array();	   
	   
		try
		{
		
			if($dbfile==0)
			{
				$query = "SELECT table_schema, (sum( data_length + index_length )) As size FROM information_schema.TABLES WHERE table_schema = 'dup' GROUP BY table_schema;";
					
				LogStr("MariaDB: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);
				
				while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				{
					$value = $row; 
					break;
				}
			}
			else
			{
				if(file_exists($dbdir . "/dup.db"))
					$value['size'] = intval($dup->fsize($dbdir . "/dup.db"));
				else
					$value['size'] = 0;
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}
		
$dbSize = isset($value['size']) && is_numeric($value['size']) ? intval($value['size']) : 0;
$dbSizeTitle = $dbSize > 0 ? number_format($dbSize) . " bytes" : "Unknown";
$dbSizeText = $dbSize > 0 ? $dup->format_bytes($dbSize) : "Unknown";

echo "<tr><td class=\"infoTableHeader\">dup Database size: </td><td><span title=\"" . DupHtml($dbSizeTitle) . "\">" . DupHtml($dbSizeText) . "</span></td></tr>\n";    
	   ?>
	   
		<tr><td class="infoTableHeader">PDO SQL Version: </td><td><?php echo DupHtml($dblink->getAttribute(PDO::ATTR_SERVER_VERSION)); ?></td></tr>
		<tr><td class="infoTableHeader">PHP Version: </td><td><?php echo DupHtml(phpversion()); ?></td></tr>
       <tr class="infoTableSeperator"><td class="infoTableHeader"><i class="icon16-sb"></i> Homepage </td><td><a HREF="http://www.arden.ie/dup" target="_blank">http://www.arden.ie/dup</a></td></tr>
       <tr><td class="infoTableHeader"><i class="icon16-web"></i> Forums </td><td><a href="http://www.arden.ie/forums/viewforum.php?f=1" target="_blank">http://www.arden.ie/forums/viewforum.php?f=1</a></td></tr>
	   
	   
	   <tr class="infoTableSeperator"><td class="infoTableHeader">Backups</td>&nbsp;<td></td></tr>
	   <tr><td class="infoTableHeader"><td>
	   <?php
		$backupDir = __DIR__ . "/backup";
		$impdata = "";

		if(is_dir($backupDir))
		{
			$dir = dir($backupDir);

			if($dir)
			{
				while(($file = $dir->read()) !== false)
				{
					if(strlen($file) < 5 || substr($file, -4) != '.txt')
						continue;

					$safeName = basename($file);
					//$fullPath = $backupDir . "/" . $safeName;

					echo "<B>backup/" . DupHtml($safeName) . "</B> ";
					echo "(<a href=\"javascript:DeleteBackup(" . DupJs($safeName) . ");\">Delete</a>)<BR>";

					//$impdata = "<BR><a href=\"backup.php?action=import\">Import Above Files</a>\n";
					$impdata = "<BR><a href=\"setup-backup.php?action=import\">Import Above Files</a>\n";
				}

				$dir->close();
			}
		}

		echo $impdata;
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
function DeleteBackup(file)
{
    var answer = confirm("Are you sure you want to delete this backup file?\n'" + file + "'");

    if(answer == true)
        window.open("setup-backup.php?action=delete&file=" + encodeURIComponent(file), "_self");
}
</script>




