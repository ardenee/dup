<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Update Check", 1, __FILE__, __LINE__, __FUNCTION__);
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Update Check</TITLE>
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
            $("#NAVsystem").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY>
  <?php echo PageHeader(); ?> 
  </HEADER>
  <DIV id="contentWrapper">
   <DIV id="content">
    <h1 class="title">Update Check</h1>
     <DIV id="summary" class="align-left">
      <table class="infoTable">
       <tr><td class="infoTableHeader">Connecting....</td><td>Current Released Version is <b><a href="http://www.arden.ie/dup"><?php echo file_get_contents('http://www.arden.ie/dup/check'); ?></a></b></td></tr>       
       <tr><td class="infoTableHeader">Local Check...</td><td>Your Installed Version is <b><?php echo $dup->Settings['version']; ?></b></td></tr>       
	   <tr><td class="infoTableSeperator"></td><td class="infoTableSeperator"></td></tr>       
       <tr><td colspan=2><?php echo @file_get_contents('http://www.arden.ie/dup/news'); ?></td></tr>       
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





