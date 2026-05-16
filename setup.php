<?php
$setupChecks = array();

$setupChecks[] = array(
    "name" => "PHP version",
    "ok" => version_compare(PHP_VERSION, "8.2.0", ">="),
    "details" => PHP_VERSION,
    "fix" => "Use a Web Station PHP profile with PHP 8.2 or higher."
);

$setupChecks[] = array(
    "name" => "PDO",
    "ok" => extension_loaded("pdo"),
    "details" => extension_loaded("pdo") ? "loaded" : "missing",
    "fix" => "Enable PDO in the Web Station PHP profile used by DUP."
);

$setupChecks[] = array(
    "name" => "pdo_mysql",
    "ok" => extension_loaded("pdo_mysql"),
    "details" => extension_loaded("pdo_mysql") ? "loaded" : "missing",
    "fix" => "Enable pdo_mysql in the Web Station PHP profile used by DUP."
);

$setupChecks[] = array(
    "name" => "curl",
    "ok" => extension_loaded("curl"),
    "details" => extension_loaded("curl") ? "loaded" : "missing",
    "fix" => "Recommended for update checks. DUP can still run without it, but update-check.php may fail if allow_url_fopen is disabled."
);

$setupChecks[] = array(
    "name" => "mysqli",
    "ok" => extension_loaded("mysqli"),
    "details" => extension_loaded("mysqli") ? "loaded" : "missing",
    "fix" => "Enable mysqli if you want phpMyAdmin-style compatibility. DUP setup mainly requires pdo_mysql."
);

$setupChecks[] = array(
    "name" => "old mysql extension",
    "ok" => !extension_loaded("mysql"),
    "details" => extension_loaded("mysql") ? "loaded" : "not loaded",
    "fix" => "Do not enable the old mysql extension. DUP uses PDO/pdo_mysql."
);

$setupChecks[] = array(
    "name" => "mcrypt",
    "ok" => !extension_loaded("mcrypt"),
    "details" => extension_loaded("mcrypt") ? "loaded" : "not loaded",
    "fix" => "Do not enable mcrypt. It is obsolete and not required by DUP."
);

$setupChecks[] = array(
    "name" => "logs folder writable",
    "ok" => is_dir(__DIR__ . "/logs") && is_writable(__DIR__ . "/logs"),
    "details" => __DIR__ . "/logs",
    "fix" => "Run: sudo chown -R dup:http /volume1/web_packages/dup/logs && sudo chmod -R 775 /volume1/web_packages/dup/logs"
);

$setupChecks[] = array(
    "name" => "db.php writable",
    "ok" => is_writable(__DIR__ . "/db.php"),
    "details" => __DIR__ . "/db.php",
    "fix" => "Run: sudo chown dup:http /volume1/web_packages/dup/db.php && sudo chmod 664 /volume1/web_packages/dup/db.php"
);

$setupHasBlockingIssues = false;

foreach($setupChecks as $check)
{
    if(!$check["ok"] && ($check["name"] == "PHP version" || $check["name"] == "PDO" || $check["name"] == "pdo_mysql" || $check["name"] == "db.php writable" || $check["name"] == "old mysql extension" || $check["name"] == "mcrypt"))
        $setupHasBlockingIssues = true;
}
?>

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
function handleClick()
{
    var answer = confirm(
        "DUP setup will connect to MariaDB 10, recreate the dup database, import the required tables, and write the database settings.\n\n" +
        "Before continuing, make sure:\n" +
        "- Web Station is installed and running\n" +
        "- PHP 8.2 or higher is selected for DUP\n" +
        "- pdo_mysql is enabled in DUP's PHP profile\n" +
        "- MariaDB 10 is installed and running\n" +
        "- The configured MariaDB user/password is correct\n" +
        "- The configured MariaDB user can create/drop the dup database\n\n" +
        "This will reset the dup database unless an upgrade/import path is being used.\n\n" +
        "Continue?"
    );

    if(answer == true)
        document.getElementById("configForm").submit();
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
         <LI id="NAVaccounts" class=dropdown><A class="dropdown-toggle disabled" href="accounts.php" data-toggle="dropdown">Accounts<B class=caret></B></A>
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
<h1 class="title"><B>Setup</B></h1>

<div class="alert <?php echo $setupHasBlockingIssues ? "alert-error" : "alert-success"; ?>">
    <h3>DSM 7 / PHP 8.2 setup checks</h3>

    <p>DUP requires:</p>

    <ul>
        <li>Web Station installed and running</li>
        <li>PHP 8.2 or higher</li>
        <li>MariaDB 10 installed and running</li>
        <li><b>pdo_mysql</b> enabled in the Web Station PHP profile used by DUP</li>
        <li>The old <b>mysql</b> extension must not be enabled; use <b>pdo_mysql</b> instead</li>
        <li><b>mcrypt</b> must not be enabled; it is obsolete/removed on modern PHP</li>
    </ul>

    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>Check</th>
                <th>Status</th>
                <th>Details</th>
                <th>Fix if failed</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($setupChecks as $check) { ?>
            <tr>
                <td><?php echo htmlspecialchars($check["name"], ENT_QUOTES, "UTF-8"); ?></td>
                <td><?php echo $check["ok"] ? "<b style=\"color: green;\">OK</b>" : "<b style=\"color: red;\">FAILED</b>"; ?></td>
                <td><?php echo htmlspecialchars($check["details"], ENT_QUOTES, "UTF-8"); ?></td>
                <td><?php echo htmlspecialchars($check["fix"], ENT_QUOTES, "UTF-8"); ?></td>
            </tr>
            <?php } ?>
        </tbody>
    </table>

    <?php if($setupHasBlockingIssues) { ?>
        <p><b>Fix the failed required checks before running setup.</b></p>
    <?php } else { ?>
        <p><b>Required PHP checks passed. You can now enter the MariaDB 10 username and password and run setup.</b></p>
    <?php } ?>
</div>


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
         <label class="nocheck clearfix" for="db_user">
          <span class="component-title">MySQL User</span>
          <input type="text" name="db_user" id="db_user" value="root" size="35" maxlength="64" autocomplete="username" required>
          <span class="component-desc">MariaDB user with permission to create/drop the dup database. Default: root.</span>
         </label>
        </div>
        <div class="field-pair">
         <label class="nocheck clearfix" for="password">
          <span class="component-title">Password</span>
          <input type="password" name="password" id="password" value="" size="35" maxlength="256" autocomplete="current-password" required> <a href="https://www.synology.com/en-us/support/faq/319" target="_blank" rel="noopener noreferrer"><b>?</b></a>
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
          <input type="text" name="db_dir" id="db_dir" value="<?php echo htmlspecialchars(getcwd(), ENT_QUOTES, "UTF-8"); ?>" size="35" readonly>
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
         <input type="button" class="btn config_submitter" value="Run Setup" OnClick="javascript:handleClick();" <?php echo $setupHasBlockingIssues ? "disabled" : ""; ?>/>
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
            <B>Required Packages / Settings for DSM 7</B><BR><BR>

            Web Station must be installed and running.<BR>
            PHP 8.2 or higher must be installed.<BR>
            MariaDB 10 must be installed and running, with TCP/IP enabled.<BR>
            The Web Station PHP profile used by DUP must have <B>pdo_mysql</B> enabled.<BR><BR>

            Optional but useful for debugging: phpMyAdmin.<BR><BR>

            Do not enable the old <B>mysql</B> PHP extension. It is removed on modern PHP.<BR>
            Do not enable <B>mcrypt</B>. It is obsolete and not required by DUP.<BR>
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
