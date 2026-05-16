<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Logs", 1, __FILE__, __LINE__, __FUNCTION__);

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

$show = 200;
$level = 1;

if(isset($_POST['level']))
    $level = intval($_POST['level']);

if(isset($_GET['level']))
    $level = intval($_GET['level']);

if(isset($_POST['show']))
    $show = intval($_POST['show']);

if(isset($_GET['show']))
    $show = intval($_GET['show']);

if($level < 1 || $level > 4)
    $level = 1;

$allowedShow = array(200, 500, 2000, 5000);

if(!in_array($show, $allowedShow))
    $show = 200;
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Logs</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]-->  
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">  
  <LINK rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <LINK rel="stylesheet" type="text/css" href="files/jquery.qtip.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">  

<SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>  
<SCRIPT type="text/javascript" src="files/bootstrap.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.cookie.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.cookiejar.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.json-2.2.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.selectboxes.min.js"></SCRIPT>
<SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>  
<SCRIPT type="text/javascript" src="files/jquery.tablesorter.widgets.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.qtip-2012-04-26.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.expand-1.3.8.js"></SCRIPT>
<SCRIPT type="text/javascript" src="files/jquery.form-2.92.js"></SCRIPT>
<SCRIPT SRC="files/script.js"></SCRIPT>  

  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
        sbRoot = "/";
		top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>  

<SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVlogs").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
 
 <BODY>
  <?php echo PageHeader(); ?>
   <DIV id=SubMenu><SPAN><A href="clear-logs.php">Clear Logs</A></SPAN></DIV>
  </HEADER>
  <div id="contentWrapper">
   <div id="content">
    <h1 class="title">Logs - Level <?php echo $level. " / ".$show;?></h1>
	
	
<SCRIPT type="text/javascript">
<!--
$(document).ready(function() 
{ 
    $("#historyTable:has(tbody tr)").tablesorter({
        widgets: ['zebra', 'filter'],
        sortList: [[1,1]]
    });
});
//-->
</SCRIPT>

     <form name=lofs id=logs action=logs.php method=post>
	  <div class="h2footer align-right"><b>Minimum logging level to display:</b> 
	   <select name="level" id="level" onchange="this.form.submit()">
	    <option value="1" <?php echo (($level==1)?" SELECTED":"");?>>Level 1 - Default</option>
	    <option value="2" <?php echo (($level==2)?" SELECTED":"");?>>Level 2 - More Information</option>
	    <option value="3" <?php echo (($level==3)?" SELECTED":"");?>>Level 3 - Debug</option>
		<option value="4" <?php echo (($level==4)?" SELECTED":"");?>>Level 4 - All</option>
	   </select>
	  </div>
	  <BR>
	  <BR>
	  <BR>
	  <div class="h2footer align-right"><b>Number of Logs to Show:</b> 
	   <select name="show" id="show" onchange="this.form.submit()">
	    <option value="200"  <?php echo (($show==200)?"  SELECTED":"");?>>200</option>
	    <option value="500"  <?php echo (($show==500)?"  SELECTED":"");?>>500</option>
	    <option value="2000" <?php echo (($show==2000)?" SELECTED":"");?>>2000</option>
	    <option value="5000" <?php echo (($show==5000)?" SELECTED":"");?>>5000</option>
	   </select>
	  </div> 
	  <br>
	 </form>

<?php
$c = "Green";
$value = 0;

echo "<TABLE id=\"historyTable\" class=\"tablesorter\" border=\"0\" cellSpacing=\"1\" cellPadding=\"0\">\n";
echo " <thead>\n";
echo " <tr class=\"tablesorter-header\">\n";
echo " <th class=\"nowrap tablesorter-header tablesorter-headerSortUp\"><div class=\"tablesorter-header-inner\">Num (id)</div></th>\n";
echo " <th class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Time</div></th>\n";
echo " <th class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Type</div></th>\n";
echo " <th class=\"tablesorter-header\"><div class=\"tablesorter-header-inner\">Info</div></th>\n";
echo " <th class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">Function</div></th>\n";
echo " <th class=\"nowrap tablesorter-header\"><div class=\"tablesorter-header-inner\">File (Line)</div></th>\n";
echo " </tr>\n";
echo " </thead>\n";
echo " <TBODY>\n";

try
{
    $query = "SELECT id, stamp, type, str, function, page, line FROM logs WHERE type<=:level ORDER BY id DESC LIMIT :show;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":level", $level, PDO::PARAM_INT);
    $sdt->bindValue(":show", $show, PDO::PARAM_INT);
    $sdt->execute();

    $logs = $sdt->fetchAll(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    $num = 1;
	
	if(count($logs) == 0)
	{
		echo " <tr>\n";
		echo " <td colspan=\"6\" align=\"center\"><b>No log entries found.</b></td>\n";
		echo " </tr>\n";
	}

    foreach($logs as $key => $row)
    {
        $TYPE = LType(isset($row['type']) ? intval($row['type']) : 0);

        $id = isset($row['id']) ? intval($row['id']) : 0;
        $stamp = isset($row['stamp']) ? $row['stamp'] : "";
        $str = isset($row['str']) ? FromSafeSQLValue($row['str']) : "";
        $function = isset($row['function']) ? $row['function'] : "";
        $page = isset($row['page']) ? $row['page'] : "";
        $line = isset($row['line']) ? intval($row['line']) : 0;

        echo " <tr>\n";
        echo " <td style=\"width:50px\"><span title=\"id: " . number_format($id) . "\"> " . number_format($num) . " </span></td>\n";
        echo " <td class=\"nowrap\" style=\"width:150px\"> " . DupHtml($stamp) . " </td>\n";
        echo " <td style=\"width:50px\"><B><font color=\"" . DupHtml($c) . "\"> " . DupHtml($TYPE) . " </font></b></td>\n";
        echo " <td> " . DupHtml($str) . " </td>\n";
        echo " <td style=\"width:150px\"> " . DupHtml($function) . " </td>\n";
        echo " <td style=\"width:150px\"><B><font color=\"brown\"><span title=\"Line: " . $line . "\">" . DupHtml($page) . "</span></font></b></td>\n";
        echo " </tr>\n";

        $num++;
    }
}
catch(PDOException $ex) {
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}
	
	//echo "      <tr>\n";
	//echo "       <td colspan=\"6\">&nbsp;</td>\n";			
	//echo "      </tr>\n";		
	//echo "      <tr>\n";
	//echo "       <td colspan=\"6\" align=center><b> ".number_format($num)." </b>Total Log Entries</td>\n";			
	//echo "      </tr>\n";	
	echo "</TBODY>\n";
	echo "     </table>";
?>


  
  
    </div><!-- /config-components -->
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->  
 <?php echo PageFooter(); ?>
 </BODY>
</HTML> 

<?php
function LType($t)
{
	global $c;
	
	switch($t)
	{
		case  '1' : $t = "Logs";       $c="Green"; break;
		case  '2' : $t = "Info";       $c="Blue";  break;
		case  '3' : $t = "Debug";      $c="Brown"; break;
		case  '4' : $t = "Error";      $c="Red";   break;
		default   : $t = "Unknown";    $c="black"; break;
	}
	
	return $t;
}
?>