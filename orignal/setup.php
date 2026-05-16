<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Setup</TITLE>
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
<SCRIPT type="text/javascript">
            $.Browser = {
                defaults: {
                    title:             'Choose Directory',
                    url:               'browser-files.php',
                    autocompleteURL:   'browser-files.php'
                }
            };
        </SCRIPT>
    <script type="text/javascript">  
	function handleClick(){
		var answer=confirm('Submitting this form will reset dup\'s database completely if the Upgrade is not selected!\nIf the DB Validate failes this will also reset the DB\n\nContinue?');
		if (answer==true)
		{
		  //window.open('setup-/saveupdate.php', _self);
		  document.getElementById('configForm').submit();
		}
	}
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
         <LI><A href="update-check.php"><I class=icon-check></I> Force Version Check</A></LI>
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
         <LI id="NAVaccounts" class=dropdown><A class="dropdown-toggle disabled\" href="accounts.php" data-toggle="dropdow\">Accounts<B class=caret></B></A>
          <UL class=dropdown-menu>   
	       <LI><A href="logout-accounts.php"><I class=icon-remove-circle></I> Logout</A></LI>
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
         <LI><A href="search-general.php"><I class=icon-search></I> General Search</A></LI>
         <LI><A href="search-dup.php"><I class=icon-search></I> Dup Search</A></LI>
        </UL>
       </LI>  
        <LI class=divider-vertical></LI>
        <LI id=NAVerrorlogs class=dropdown><A class="dropdown-toggle disabled" href="logs.php" data-toggle="dropdown"> Logs</A>
       </LI>	
	   <LI class=divider-vertical></LI>
       <LI id=NAVfeedback class=dropdown><A class="dropdown-toggle disabled" href="feedback.php" data-toggle="dropdown"> Feedback</A>
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
  <h1 class="title"><B>Setup</B><h1>
  <div id="config">
   <div id="config-content">
    <form id="configForm" action="setup-save.php" method="post">
     <div id="config-components">
      <div id="core-component-group" class="component-group clearfix">
	  
       <div class="component-group-desc">
        <h3>MySQL</h3>
        <p>Login details</p>
       </div>	   
       <fieldset class="component-group-list">
        <div class="field-pair">
		 <label class="nocheck clearfix" for="user">
		  <span class="component-title">MySQL User</span>
		  <input type="text" name="user" id="user" value="root" size="35" readonly>
		 </label>
		</div>
        <div class="field-pair">
		 <label class="nocheck clearfix" for="password">
		  <span class="component-title">Password</span>
		  <input type="password" name="password" id="password" value="" size="35"> <a href="http://www.synology.com/en-us/support/faq/319" target="_blank"><b>?</b></a>
		 </label>
		</div>
		<div class="field-pair">
		 <input type="checkbox" name="upgrade" id="upgrade"/>
		 <label class="clearfix" for="upgrade">
		  <span class="component-title">Keep Old Data</span>
		  <span class="component-desc">Exports and then Imports your old data to the new DB Structure (v0.6 onwards only!) May take some time!!</span>
		 </label>
		</div>
		</fieldset>	
		
		<div class="field-pair">
		 <label class="nocheck clearfix">
		  <span class="component-title">&nbsp;</span>
		 </label>
		</div>
		
		<div class="component-group-desc">
        <h3>SQLite</h3>
        <p>File details</p>
       </div>	
	   <fieldset class="component-group-list">
         <div class="field-pair">
          <label class="nocheck clearfix" for="db_dir">
		   <span class="component-title">Database Folder</span>
           <input type="text" name="db_dir" id="db_dir" value="<?php echo getcwd(); ?>" size="35" readonly>		   
          </label>
         </div>
		 
		<div class="field-pair">
		 <input type="checkbox" name="filebd" id="filebd" disabled/>
		 <label class="clearfix" for="filebd">
		  <span class="component-title">Use Database File</span>
		  <span class="component-desc">SQLite 3 local file instead of MySQL</span>
		 </label>
		</div>
	
		
        <div class="component-group-save">
         <input type="button" class="btn config_submitter" value="Save Changes" OnClick="javascript:handleClick();"/>
        </div>
		<br />
       </fieldset>	
	   
      
	  </div>
      <DIV id="summary" class="align-left">
	   <label class="nocheck clearfix">
	    <span class="component-title">Web Settings</span>
	    <img src="images/websettings1.png" width="98%" />
	   </label>
       <BR>
	   <label class="nocheck clearfix">
	    <span class="component-title">PHP Settings</span>
	    <img src="images/websettings2.png" width="98%" />
	   </label>
	   <BR>

	   	<label class="nocheck clearfix">
	    <B>Required Packages</B><BR><BR>
		Init_3rdparty <a href="http://www.cphub.net/index.php?id=40&pid=253" target="_blank">http://www.cphub.net/index.php?id=40&pid=253</a><BR>
		phpMyAdmin (only required for setting root MySQL password)<BR><BR>
	   </label>

	   <label class="nocheck clearfix">
	    <B>About the above settings</B><BR><BR>
		The above settings are the ones I used when developing and making dup and I had no issues with its functionality. Packages made with Python run with full permissions by default so why not your php packages too?<BR><BR>
	    PHP has a bit more security which will allow you to lock down directory's  and restrict functionality.<BR><BR>
	    By all means if you want this go ahead but it's up to you to figure out how! Enough said ;)
	   </label>
	  </div> 
     </div><!-- /config-components -->
	</form> 
   </div><!-- /config-content -->
  </div><!-- /config -->
 </DIV><!-- /content -->
</DIV><!-- /contentWrapper -->

	<script type="text/javascript" charset="utf-8">
	<!--
	$(document).ready(function(){
		$('#db_dir').fileBrowser({ title: 'Select Database Directory' });
	});
	//-->
	</script>


  <FOOTER>
   <DIV class="container footer"><B>dup Setup</B></DIV><!-- /container footer -->
  </FOOTER>	
 </BODY>
</HTML> 