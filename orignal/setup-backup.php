<?php 

$refresh = "backup.php";

if(isset($_GET["action"]))
	$action = $_GET["action"];	
	
	if($action=="import")
	{
		shell_exec("php -q import.php >/dev/null 2>/dev/null &");// imports old data
		sleep(2);
	}
	
	$edata  = @file_get_contents("backup/backup.data");
	$edataa = explode("\n", $edata);
  
	if(in_array("Import Finnished", $edataa))
	{
		@unlink("backup/backup.data");
		$refresh="index.php";	
	}
		
	unset($edataa);
?>
<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Backup</TITLE>
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
  <SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVsystem").addClass("active");
        });
    //-->
  </SCRIPT>	
  <META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
<BODY>
	<HEADER>
   <DIV class=navbar>
    <DIV class=navbar-inner>
     <DIV class=container>
      <A class="btn btn-navbar" data-target=".nav-collapse" data-toggle="collapse">
       <SPAN class=icon-bar></SPAN>
       <SPAN class=icon-bar></SPAN>
       <SPAN class=icon-bar></SPAN>
      </A>
      <DIV class=nav-collapse>
       <UL class=nav>	   
        <LI class=divider-vertical></LI>		
       <LI id=NAVsystem class=dropdown><A class=dropdown-toggle  onclick="return false;" href="#" data-toggle="dropdown"><IMG alt="" src="images/system18.png" width=18 height=18> <B class=caret></B></A>
        <UL class=dropdown-menu>
         <LI><A href="updatecheck.php"><I class=icon-check></I> Force Version Check</A></LI>
          <LI class=divider></LI>
          <LI><A href="setup.php"><I class=icon-off></I> Setup</A></LI>   
		  <LI class=divider></LI>
		  <LI><A href="error.php"><I class=icon-off></I> Error Report</A></LI>
        </UL>
       </LI>  
        <LI class=divider-vertical></LI>
        <LI id=NAVhome class=dropdown><A class="dropdown-toggle disabled" href="index.php" data-toggle="dropdown"> Home<B class=caret></B></A>
         <UL class=dropdown-menu>
          <LI><A href="profile-add.php"><I class=icon-plus-sign></I> Add Profile</A></LI>
         </UL>
       </LI>	  
        <LI class=divider-vertical></LI>
        <LI id=NAVconfig class=dropdown><A class="dropdown-toggle disabled" href="config-general.php" data-toggle="dropdown">Config<B class=caret></B></A>
         <UL class=dropdown-menu>
   	  <LI><A href="config-help.php"><I class=icon-question-sign></I> Help &amp; Info</A></LI>
   	  <LI class=divider></LI>
   	  <LI><A href="config-general.php"><I class=icon-wrench></I> General</A></LI>
   	  <LI><A href="config-profiletypes.php"><I class=icon-cog></I> Profile Types</A></LI>
        </UL>
       </LI>	
   	<LI class=divider-vertical></LI>
        <LI id=NAVrename class=dropdown><A class="dropdown-toggle disabled" href="renaming.php" data-toggle="dropdown"> Renaming</A></LI>			
        <LI class=divider-vertical></LI>
		
		
        <LI id="NAVsearch" class=dropdown><A class="dropdown-toggle disabled" href="search-general.php" data-toggle="dropdown">Search<B class=caret></B></A>
          <UL class=dropdown-menu>
   	       <LI><A href="search-general.php"><I class=icon-question-sign></I> General Search</A></LI>
   	       <LI><A href="search-dup.php"><I class=icon-question-sign></I> Dup Search</A></LI>
          </UL>
         </LI>  
		  
        <LI class=divider-vertical></LI>
        <LI id=NAVerrorlogs class=dropdown><A class="dropdown-toggle disabled" href="logs.php" data-toggle="dropdown"> Logs</A>
       </LI>	
        <LI class=divider-vertical></LI> 	  
      </UL>
       <UL class="nav pull-right">
        <LI><A id=navDonate onclick="window.open(this.href); return false;" href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=7RVSGJHGCYCUW"><IMG alt=[donate] src="images/btn_donate_LG.gif" width=92 height=26></A></LI>
       </UL>
      </DIV><!-- /nav-collapse -->
     </DIV><!-- /container -->
    </DIV><!-- /navbar-inner -->
   </DIV><!-- /navbar -->
</HEADER>

<div id="contentWrapper">
 <div id="content">
  <h1 class="title">Backup Report<h1>
  <div id="config">
   <div id="config-content">
     <div id="config-components">

      <DIV id="summary" class="align-left">
	  <?php	  
		echo "<b>Backup Status</b><BR><BR>\n";	
		echo "<pre>\n";
		print_r($edata);
		echo "</pre>\n";
		?>
	  </div> 
     </div><!-- /config-components -->

   </div><!-- /config-content -->
  </div><!-- /config -->
 </DIV><!-- /content -->
</DIV><!-- /contentWrapper -->
  <FOOTER>
   <DIV class="container footer"><B>dup Backup Report</B></DIV><!-- /container footer -->
  </FOOTER>	
 </BODY>
  	<SCRIPT TYPE="text/javascript" CHARSET="utf-8">
	   sleep(5000);
	   window.open('<?php echo $refresh; ?>','_self');
	   
	   
	function sleep(milliseconds) {
	  var start = new Date().getTime();
	  for (var i = 0; i < 1e7; i++) {
		if ((new Date().getTime() - start) > milliseconds){
		  break;
		}
	  }
	}
	</SCRIPT>
</HTML> 