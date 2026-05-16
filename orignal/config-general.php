<?php
include("dup.php");
$dup = new dup($profile);
LogStr("General Settings", 1, __FILE__, __LINE__, __FUNCTION__);

?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - General</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]--> 
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css"> 
  <link rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css">  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <link rel="stylesheet" type="text/css" href="files/jquery.qtip.css"/>
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css"> 
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css"> 
  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
    <script type="text/javascript" src="files/bootstrap.min.js"></script>
    <script type="text/javascript" src="files/jquery.cookie.js"></script>
    <script type="text/javascript" src="files/jquery.cookiejar.js"></script>
    <script type="text/javascript" src="files/jquery.json-2.2.min.js"></script>
    <script type="text/javascript" src="files/jquery.selectboxes.min.js"></script>
	<SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
    <script type="text/javascript" src="files/jquery.tablesorter.widgets.min.js"></script>
    <script type="text/javascript" src="files/jquery.qtip-2012-04-26.min.js"></script>
    <script type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></script>
    <script type="text/javascript" src="files/jquery.expand-1.3.8.js"></script>  
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.form-2.92.js"></SCRIPT>  
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
	    sbRoot = '/'; 
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>  
   <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
     <script type="text/javascript" src="files/ajaxNotifications.js"></script>
  <SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>  

   
  <SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVconfig").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>

<BODY>
  <?php echo PageHeader(); ?>
   <DIV id=SubMenu><SPAN><A HREF="config-help.php">Help</A> | <A HREF="config-general.php">General</A> | <A HREF="config-filetypes.php">Profile Types</A></SPAN></DIV>
  </HEADER>

<div id="contentWrapper">
<div id="content">
<h1 class="title">General Configuration</h1>

<script type="text/javascript" src="/dup/files/config.js"></script>

<div id="config">
 <div id="config-content">
  <form id="configForm" action="update-config.php" method="post">
   <div id="config-components">
    <div id="core-component-group1" class="component-group clearfix">
    <div class="component-group-desc">
 	 <h3>Actions</h3>
 	 <p>Action options.</p>
 	 <p><b>What Should dup do if a file already exists?</b></p>
 	</div> <!--component-group-desc-->

<fieldset class="component-group-list">

                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">If Files Exists</span>
                                <span class="component-desc">
                                    <select name="renametype" id="renametype" class="input-medium" >
                                      <option value="0"<?php echo (($dup->Settings['renametype']==0)?" SELECTED":"") ?>>Fail</option>
                                      <option value="1"<?php echo (($dup->Settings['renametype']==1)?" SELECTED":"") ?>>Append Number</option>
									  <option value="2"<?php echo (($dup->Settings['renametype']==2)?" SELECTED":"") ?>>Overwrite !!</option>
                                    </select>
									<span class="component-desc">Renaming/Moving/Linking</span>
                                </span>
                            </label>
                        </div>

<div class="field-pair">
<input type="checkbox" name="namecleanup" id="namecleanup" <?php echo (($dup->Settings['namecleanup']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="namecleanup">
<span class="component-title">Name Cleanup</span>
<span class="component-desc">Clean up File Names?</span>
</label>
</div>

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">Clean Up Filters</span>
<input type="text" name="cleanupfilter" value="<?php echo $dup->Settings['cleanupfilter'] ?>" size="50" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Parts to remove from files names. Comma seperated</span>
</label>
</div>


                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">Underscore _ Replacement:</span>
                                <span class="component-desc">
                                    <select name="under" id="under" class="input-medium" >
                                    <option value="0"<?php echo (($dup->Settings['under']==0)?" SELECTED":"") ?>>Leave</option>
									<option value="1"<?php echo (($dup->Settings['under']==1)?" SELECTED":"") ?>>Blank Space</option>
                                    <option value="2"<?php echo (($dup->Settings['under']==2)?" SELECTED":"") ?>>Dot .</option>
                                    </select>
                                </span>
                            </label>
                        </div>

                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">Dot . Replacement:</span>
                                <span class="component-desc">
                                    <select name="dot" id="dot" class="input-medium" >
									<option value="0"<?php echo (($dup->Settings['dot']==0)?" SELECTED":"") ?>>Leave</option>
                                    <option value="1"<?php echo (($dup->Settings['dot']==1)?" SELECTED":"") ?>>Blank Space</option>
                                    <option value="2"<?php echo (($dup->Settings['dot']==2)?" SELECTED":"") ?>>Underscore _</option>
                                    </select>
                                </span>
                            </label>
                        </div>
						
                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">Double Blanks Replacement:</span>
                                <span class="component-desc">
                                    <select name="doubleb" id="doubleb" class="input-medium" >
                                    <option value="0"<?php echo (($dup->Settings['doubleb']==0)?" SELECTED":"") ?>>Leave</option>
									<option value="1"<?php echo (($dup->Settings['doubleb']==1)?" SELECTED":"") ?>>Blank Space</option>
                                    </select>
                                </span>
                            </label>
                        </div>
						

<div class="field-pair">
<input type="checkbox" name="flatfolders" id="flatfolders" <?php echo (($dup->Settings['flatfolders']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="flatfolders">
<span class="component-title">Flatten Folders</span>
<span class="component-desc">When Moving files only</span>
</label>
</div>
 <div class="component-group-save">
  <input type="submit" class="btn config_submitter" value="Save Changes" />
  </div><br />
</fieldset>
</div>

<div id="core-component-group2" class="component-group clearfix">
<div class="component-group-desc">
<h3>Misc</h3>
<p>Other options that may improve speed.</p>
</div>

<fieldset class="component-group-list">

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">Folders To Ignore</span>
<input type="text" name="ifolders" value="<?php echo $dup->Settings['ifolders'] ?>" size="50" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Dont process files from these folders. Comma seperated.</span>
</label>
</div>

<div class="field-pair">
<input type="checkbox" name="logs" id="logs" <?php echo (($dup->Settings['logs']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="logs">
<span class="component-title">Keep Logs</span>
<span class="component-desc">Handy to have!!</span>
</label>
</div>

<div class="field-pair">
<input type="checkbox" name="displaymoved" id="displaymoved" <?php echo (($dup->Settings['displaymoved']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="displaymoved">
<span class="component-title">Display Moved Files</span>
<span class="component-desc">Display Moved files in profiles as Dups still?</span>
</label>
</div>

<div class="field-pair">
<input type="checkbox" name="download" id="download" <?php echo (($dup->Settings['download']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="download">
<span class="component-title">Enable Downloads</span>
<span class="component-desc">Download listed Files</span>
</label>
</div>

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">Short File Names</span>
<input type="text" name="shortfilenames" value="<?php echo $dup->Settings['shortfilenames']; ?>" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Number of characters to show in File Names (0 = no limit)</span>
</label>
</div>

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">Script Run Time</span>
<input type="text" name="runtime" value="<?php echo $dup->Settings['runtime']; ?>" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Number of seconds a php script should run for.<BR>Scanning large folders on 'CRC32 Full' may take some time</span>
</label>
</div>

<div class="field-pair">
<input type="checkbox" name="cpfolder" id="cpfolder" <?php echo (($dup->Settings['cpfolder']==1)?" CHECKED":"") ?>/>
<label class="clearfix" for="cpfolder">
<span class="component-title">Create Profile Folder on Move</span>
<span class="component-desc">When moving Files if using the same Move forlder for different Porfiles you can keep all moved files in their own profile id folder.</span>
</label>
</div>

 <div class="component-group-save">
  <input type="submit" class="btn config_submitter" value="Save Changes" />
  </div>

</fieldset>
</div><!-- /component-group1 //-->
 </div><!-- /config-components -->
 </form>
 </div>
</div>

   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  <?php echo PageFooter(); ?>
 </BODY>
</HTML> 
