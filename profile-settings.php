<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Profile Settings '" . (isset($dup->Profile['path']) ? $dup->Profile['path'] : "") . "'", 1, __FILE__, __LINE__, __FUNCTION__);
$set=false;

$profileId = intval($profile);
$profilePath = isset($dup->Profile['path']) ? $dup->Profile['path'] : "";
$profileMovePath = isset($dup->Profile['mpath']) ? $dup->Profile['mpath'] : "";
$profileFileTypes = isset($dup->Profile['filetypes']) ? $dup->Profile['filetypes'] : "";
$profileMsize = isset($dup->Profile['msize']) ? intval($dup->Profile['msize']) : 0;
$profileType = isset($dup->Profile['type']) ? intval($dup->Profile['type']) : 1;
$profileCrc32 = isset($dup->Profile['crc32']) ? intval($dup->Profile['crc32']) : 0;

$profilePathHtml = htmlspecialchars($profilePath, ENT_QUOTES, "UTF-8");
$profileMovePathHtml = htmlspecialchars($profileMovePath, ENT_QUOTES, "UTF-8");
$profileFileTypesHtml = htmlspecialchars($profileFileTypes, ENT_QUOTES, "UTF-8");

function DupJsString($value)
{
    return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}
?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Profile Settings</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]-->
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery-ui-1.8.24.custom.css">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/imgs.css">  
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/jquery.qtip.css"/>
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css"> 
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-1.7.2.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery-ui-1.8.24.custom.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.tablesorter-2.1.19.min.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" SRC="files/script.js"></SCRIPT>  
  <SCRIPT type="text/javascript" src="files/tableClick.js"></SCRIPT>
  <SCRIPT TYPE="text/javascript" CHARSET="utf-8">
		sbRoot = "/";
        top_image_html = '<img src="images/top.gif" style="width:31px; height:11px" alt="Jump to top" />'; 
  </SCRIPT>
    <SCRIPT TYPE="text/javascript" SRC="files/jquery.scrolltopcontrol-1.1.js"></SCRIPT>
  
 <SCRIPT type="text/javascript">
            $.Browser = {
                defaults: {
                    title:             'Choose Directory',
                    url:               'browser-files.php',
                    autocompleteURL:   'browser-files.php'
                }
            };
        </SCRIPT>
  <SCRIPT type="text/javascript" src="files/browser2.js"></SCRIPT>
  <SCRIPT type=text/javascript>
    <!--
        $(document).ready(function(){ 
            $("#NAVconfig").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>

<BODY onload="UpdateExts()">
    <?php echo PageHeader(); ?>
   <DIV id=SubMenu>
    <SPAN><A href="profile-add.php">Add Profile</A> | <A href="profile-view.php?profile=<?php echo $profileId; ?>">View Profile</A> | <a href="<?php echo "javascript:DeleteProfile('" . $profileId . "', 'Delete','" . SafeJavaValue($profilePath) . "');";?>">Delete Profile</a></SPAN>
   </DIV>
  </HEADER>

<div id="contentWrapper">
<div id="content">
<h1 class="title">Profile Configuration</h1>

<div id="config">
<div id="config-content">

<form id="configForm" action="update-profile.php" method="post">
<input type="hidden" name="profile" value="<?php echo $profileId; ?>">

<div id="config-components">

<div id="core-component-group1" class="component-group clearfix">

<div class="component-group-desc">
<h3>Folders</h3>
<p>Working Folders</p>
</div>

<fieldset class="component-group-list">

<div class="field-pair">
<label class="nocheck clearfix" for="main_dir">
<span class="component-title">Main Folder</span>
<input type="text" name="main_dir" id="main_dir" value="<?php echo $profilePathHtml; ?>" size="35" readonly/>
</label>
</div>

<div class="field-pair">
<label class="nocheck clearfix" for="move_dir">
<span class="component-title">Move Folder</span>
<input type="text" name="move_dir" id="move_dir" value="<?php echo $profileMovePathHtml; ?>" size="35" readonly/>
</label>
</div>

<input type="submit" class="btn config_submitter" value="Save Changes" />
</fieldset>
</div><!-- /component-group1 //-->


<div id="core-component-group2" class="component-group clearfix">

<div class="component-group-desc">
<h3>Misc</h3>
<p>Hashing can slow things down quite a bit if scannign large folders!</p>
</div>

<fieldset class="component-group-list">

                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">Hashing:</span>
                                <span class="component-desc">
                                    <select name="crc32" id="crc32" class="input-medium" OnChange="ScanType()">
										<option value="0"<?php echo (($profileCrc32 == 0) ? " SELECTED" : ""); ?>>File Size</option>
										<option value="1"<?php echo (($profileCrc32 == 1) ? " SELECTED" : ""); ?>>CRC32 3 Part</option>
										<option value="2"<?php echo (($profileCrc32 == 2) ? " SELECTED" : ""); ?>>CRC32 Full</option>
                                    </select>
                                </span>
                            </label>
                        </div>

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">File types</span>
<input type="text" id="filetypes" name="filetypes" value="<?php echo $profileFileTypesHtml; ?>" size="35" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Types of Files to Scan. Comma seperated</span>
</label>
</div>

<div class="field-pair">
<label class="nocheck clearfix">
<span class="component-title">File Size</span>
<input type="number" name="msize" value="<?php echo $profileMsize; ?>" min="0" step="1" size="35" />
</label>
<label class="nocheck clearfix">
<span class="component-title">&nbsp;</span>
<span class="component-desc">Minamum size of files to be scanned (MB)</span>
</label>
</div>

                        <div class="field-pair">
                            <label class="nocheck clearfix" for="name_presets">
                                <span class="component-title">Type:</span>
                                <span class="component-desc">
                                    <select name="type" id="type" class="input-medium" OnChange="UpdateExts()">
									<?php
										$extSel = GetExtSel();
										
										foreach($extSel as $key => $val)
										{
											$keyInt = intval($key);
											$valHtml = htmlspecialchars($val, ENT_QUOTES, "UTF-8");

											if($keyInt == $profileType)
											{
												echo "<option value=\"" . $keyInt . "\" SELECTED>" . $valHtml . "</option>\n";
												$set=true;
											}
											else
											{
												echo "<option value=\"" . $keyInt . "\">" . $valHtml . "</option>\n";
											}
										}
									?>	
                                    </select>
                                </span>
                            </label>
                        </div>


<input type="submit" class="btn config_submitter" value="Save Changes" />
</fieldset>
</div><!-- /component-group2 //-->

<div id="core-component-group3" class="component-group clearfix">

<div class="component-group-desc">
<h3>Admin</h3>
<p>Options that get reset on next load, used for resetting only!</p>
</div>

<fieldset class="component-group-list">
 
	<div class="field-pair">
	 <input type="checkbox" name="resetruns" id="resetruns"/>
	  <label class="clearfix" for="resetruns">
	  <span class="component-title">Reset Runs</span>
	  <span class="component-desc">Handy for tracking your Scans</span>
	 </label>
	</div>
	

	<div class="field-pair">
	 <input type="checkbox" name="resetscan" id="resetscan"/>
	  <label class="clearfix" for="resetscan">
	  <span class="component-title">Reset Status</span>
	  <span class="component-desc">Handy if an error accured while scanning and the status is stuck on active, Logs will always tell you it cannot re-run if thsi si the case or an active scan is running</span>
	 </label>
	</div>
	
	
  </fieldset>
</div><!-- /config-components -->

<div class="component-group-save">
<input type="submit" class="btn config_submitter" value="Save Changes" />
</div>
<br />

  </div><!-- /config-components -->
 </form>
 </div>
</div>

<?php $exts = GetExts(); ?>

	<script type="text/javascript" charset="utf-8">
	<!--
	jQuery('#main_dir').fileBrowser({ title: 'Select Main Directory' });
	jQuery('#move_dir').fileBrowser({ title: 'Select Move Directory' });
	//-->
	</script>
	
    <script type="text/javascript">  

function DeleteProfile(id, act, title)
{
    if(act == "Delete")
    {
        var answer = confirm("Are you sure you want to delete profile:\n" + title + "\n\nThis cannot be undone.");

        if(answer == true)
        {
            var form = document.createElement("form");
            form.method = "POST";
            form.action = "delete-profile.php";

            var input = document.createElement("input");
            input.type = "hidden";
            input.name = "profile";
            input.value = id;

            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
    else
        window.location.href = "profile-settings.php?profile=" + encodeURIComponent(id);
}
    </SCRIPT> 
	
	<script type="text/javascript" charset="utf-8">
	<!--	
	function UpdateExts()
	{  
		var set = <?php echo ($set==true)?"true":"false"; ?>;
		
		if(set==false)
			alert("'Profile Type' is not set, selecting first found.\nClick 'Save Changes' to set.");
			
		var exts = "";
		var item = document.getElementById('type');		

		switch(item.options[item.selectedIndex].value)
		{	
			<?php			
foreach($exts as $k => $v)
{
    $id = (string)$k;
    $extValue = isset($exts[$k]) ? $exts[$k] : "";

    echo "case " . DupJsString($id) . " : exts = " . DupJsString($extValue) . "; break;\n";
}
			?>			
			
			default : exts="Unknown"; break;
		}

		document.getElementById('filetypes').value = exts;
	} 
	//-->
	</script>
	
	<script type="text/javascript" charset="utf-8">
	<!--	
	function ScanType()
	{  
		var item = document.getElementById('crc32');		

		if(item.options[item.selectedIndex].value==2)
		{	
			alert('Full CRC32 scans can take a lot of time!\nMake sure you set the script run time to a high value and be prepeared to wait!'); 
		}
	} 
	//-->
	</script>

   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  
  <?php echo PageFooter(); ?>
  
 </BODY>
</HTML> 