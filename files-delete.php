<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Delete Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
?>


<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Delete Files</TITLE>
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
    <!--
	$.tablesorter.addParser({
		id: 'loadingNames',
	   is: function(s) {
			return false;
	   },
	   format: function(s) {
		   if (s.indexOf('Loading...') == 0)
			   return s.replace('Loading...','000');
		   return (s || '').replace(/^(The|A)\s/i,'');
	   },
	   type: 'text'
	});


	$(document).ready(function(){ 

		$("#showListTable:has(tbody tr)").tablesorter({
			sortList: [[1,1],[1,0]],
			widgets: ['saveSort', 'zebra', 'stickyHeaders'],
		});

	});
	//-->
    </SCRIPT>
    
	<META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>
 
 <BODY> 
   <?php echo PageHeader(); ?>
   <DIV id="SubMenu"><SPAN><A href="profile-add.php">Add Profile</A></SPAN></DIV>
  </HEADER>

<DIV id="contentWrapper">
 <DIV id="content">
  <H1 class="title">Files for Deleteing</H1>
  

  <form id="delete" name="delete" method="POST" action="action-list-files.php">
   <input type="hidden" name="profile" value="<?php echo intval($profile); ?>">
   <input type="hidden" name="action" value="Delete">
<TABLE id="showListTable" class="tablesorter" border="0" cellSpacing="1" cellPadding="0">
<THEAD>
  <TR>
   <TH class="nowrap">Num</TH>
   <TH>Type</TH>
   <TH>Select</TH>
   <TH>File Name</TH>

  </TR>
  </THEAD>
  <TBODY>
  
<?php
$Files = array();
$profileId = intval($profile);

if(isset($_POST['files']) && is_array($_POST['files']))
{
    $ids = array();

    foreach($_POST['files'] as $val)
    {
        $val = intval($val);

        if($val > 0)
            $ids[] = $val;
    }

    $ids = array_values(array_unique($ids));
    sort($ids);

    try
    {
        $query = "SELECT id, file FROM files WHERE profile=:profile AND id=:id LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);

        foreach($ids as $id)
        {
            $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
            $sdt->bindValue(":id", $id, PDO::PARAM_INT);
            $sdt->execute();

            $row = $sdt->fetch(PDO::FETCH_ASSOC);

            if($row && isset($row["file"]))
                $Files[] = $row["file"];

            $sdt->closeCursor();
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    foreach($Files as $key => $value)
    {
        $icon = is_link($value) ? "<I class=icon-magnet></I>" : "<I class=icon-file></I>";
        $typeTitle = is_link($value) ? "Symlink" : "File";

        $valueHtml = htmlspecialchars($value, ENT_QUOTES, "UTF-8");
        $shortNameHtml = htmlspecialchars(ChopName($value), ENT_QUOTES, "UTF-8");
        $valueKey = htmlspecialchars(FilterOn($value), ENT_QUOTES, "UTF-8");

        echo "<TR>\n";
        echo " <TD class=\"nowrap\">" . ($key + 1) . "</TD>\n";
        echo " <TD class=\"nowrap\" align=\"center\"><span title=\"" . $typeTitle . "\">" . $icon . "</span></TD>\n";
        echo " <TD class=\"nowrap\" align=\"center\"><input type=\"checkbox\" name=\"oldname[" . $valueKey . "]\" value=\"" . $valueHtml . "\" id=\"sel\" CHECKED></TD>\n";
        echo " <TD><span title=\"" . $valueHtml . "\"> " . $shortNameHtml . "</span></TD>\n";
        echo "</TR>\n";
    }
}

unset($Files);	

?>
 </TBODY> 
 <TFOOT>
 <TR>
	<TH rowSpan="1" colSpan="1"></TH>
	<TH rowSpan="1" colSpan="1"></TH>
    <TH rowSpan="1" colSpan="1"><input type="Button" Value="Inverse" OnClick="checkedAll('delete')"></TH>
    <TH rowSpan="1" colSpan="1"><input type="submit" value="Delete"></TH>
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
?>