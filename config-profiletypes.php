<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Profile Types", 1, __FILE__, __LINE__, __FUNCTION__);
$exts = GetExts(); 

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function DupJsString($value)
{
    return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}

?>

<!DOCTYPE HTML>
<HTML>
 <HEAD>
  <TITLE>dup - Profile Types</TITLE>
  <META CHARSET="utf-8">
  <META content="IE=edge,chrome=1" http-equiv="X-UA-Compatible">
  <META name="robots" content="noindex">
  <!--[if lt IE 9]>
  <SCRIPT SRC="files/html5.js"></SCRIPT>
  <![endif]--> 
  <LINK REL="shortcut icon" HREF="images/dup.png">
  <LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css"> 
  <link rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css" />
  
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
  <SCRIPT TYPE="text/javascript" SRC="files/jquery.form-2.92.js"></SCRIPT>
  
  
    <script type="text/javascript" src="files/jquery-1.7.2.min.js"></script>
    <script type="text/javascript" src="files/jquery-ui-1.8.24.custom.min.js"></script>
    <script type="text/javascript" src="files/bootstrap.min.js"></script>
    <script type="text/javascript" src="files/jquery.cookie.js"></script>
    <script type="text/javascript" src="files/jquery.cookiejar.js"></script>
    <script type="text/javascript" src="files/jquery.json-2.2.min.js"></script>
    <script type="text/javascript" src="files/jquery.selectboxes.min.js"></script>
    <script type="text/javascript" src="files/jquery.tablesorter-2.1.19.min.js"></script>
    <script type="text/javascript" src="files/jquery.tablesorter.widgets.min.js"></script>
    <script type="text/javascript" src="files/jquery.qtip-2012-04-26.min.js"></script>
    <script type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></script>
    <script type="text/javascript" src="files/jquery.expand-1.3.8.js"></script>
    <script type="text/javascript" src="files/jquery.form-2.92.js"></script>

  
   <link rel="stylesheet" type="text/css" href="files/jquery.pnotify.default.css" />
   <script type="text/javascript" src="files/jquery.pnotify-1.2.0.min.js"></script>
   <script type="text/javascript" src="files/ajaxNotifications.js"></script>



  <SCRIPT type="text/javascript">
    <!--
        $(document).ready(function(){ 
            $("#NAVconfig").addClass("active");
        });
    //-->
    </SCRIPT>

  <META name="GENERATOR" content="MSHTML 8.00.7601.17940">
 </HEAD>
 
<BODY onload='UpdateExts()'>
  <?php echo PageHeader(); ?> 
  <DIV id=SubMenu><SPAN><A HREF="config-help.php">Help</A> | <A HREF="config-general.php">General</A> | <A HREF="config-profiletypes.php">Profile Types</A></SPAN></DIV>
</HEADER>

<div id="contentWrapper">
 <div id="content">
  <h1 class="title">Profile Types</h1>
  
  <script type="text/javascript" src="/dup/files/config.js"></script>

  <div id="config">
   <div id="config-content">
    <form id="configForm" action="update-config-profiletypes.php" method="post">
     <input type="hidden" name="profile" value="<?php echo intval($profile); ?>">
     <div id="config-components">
      <div id="core-component-group1" class="component-group clearfix">
	   <div class="component-group-desc">
	    <h3>Edit Profile Types</h3>
	    <p></p>
	   </div> <!--component-group-desc-->

      <fieldset class="component-group-list">

       <div class="field-pair">
        <label class="nocheck clearfix">

  <?php
	$Profiles = $dup->GetProfileTypes("path"); 
	
    echo "         <select name=\"profiletype\" id=\"profiletype\" class=\"input-medium\" style=\"width: 400px\" OnChange=\"UpdateExts()\">\n";
	
	foreach($Profiles as $key => $val)
	{
		$id = intval($val['id']);
		$nameHtml = DupHtml(isset($val['name']) ? $val['name'] : "");
		$selected = ($id == intval($dup->Profile['type'])) ? " SELECTED" : "";

		echo " <option value=\"" . $id . "\"" . $selected . "> (" . $id . ") " . $nameHtml . " </option>\n";
	}

	echo "         </select>\n";	
 ?> 
 
        </label>
       </div>
	   
       <div class="field-pair">
	    <label class="nocheck clearfix">
		<input type="text" id="exts" name="exts" value="<?php echo DupHtml(isset($dup->Profile['filetypes']) ? $dup->Profile['filetypes'] : ""); ?>" size="60" />
	    </label>
	   </div>
	   
	   <div class="field-pair">
		<input type="checkbox" name="delpt" id="delpt" />
		<label class="clearfix" for="delpt">
		 <span class="component-desc">Delete Selected Profile (above)</span>
		</label>
	   </div>
	   
	   </fieldset>
      </div><!-- /component-group1 //-->
      <div id="core-component-group2" class="component-group clearfix">
	  
	   <div class="component-group-desc">
	    <h3>Add Profile Type</h3>
	    <p></p>
	   </div>
	  
       <fieldset class="component-group-list">
	    <div class="field-pair">
	     <label class="nocheck clearfix">
	      <input type="text" name="ptname" value="" size="35" />
		 </label>
		 <label class="nocheck clearfix">
		  <span class="component-desc">Enter name only, like 'Xbox 360 Games'</span>
		  </label>
	    </div>
		
     <div class="component-group-save">
      <input type="submit" class="btn config_submitter" value="Save Changes" />
	 </div>
	  
     </fieldset>
    </div><!-- /config-components -->
  </div>
  </form>
 </div>
 </div>
 
   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  <?php echo PageFooter(); ?>
 </BODY>
</HTML> 

	<script type="text/javascript" charset="utf-8">
	<!--	
	function UpdateExts()
	{  
		var exts = "";
		var item = document.getElementById('profiletype');		

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

		document.getElementById('exts').value = exts;
	} 
	//-->
	</script>
	