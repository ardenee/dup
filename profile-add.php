<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Add Profile", 1, __FILE__, __LINE__, __FUNCTION__);

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
  <TITLE>dup - Add Profile</TITLE>
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

            $("a.confirm").bind("click",function(e) {
                e.preventDefault();
                var target = $( this ).attr('href');
                if ( confirm("Are you sure you want to " + $(this).text() + "?") )
                    location.href = target;
                return false;
            });

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

	<META name=GENERATOR content="MSHTML 8.00.7601.17940">
 </HEAD>
 <BODY onload="UpdateExts()"> 
  <?php echo PageHeader(); ?>
  </HEADER>
  
<?php
$extSel = GetExtSel();
$id = 0;

foreach($extSel as $key => $val)
{
    $id = $key;
    break;
}

if(count($extSel) == 0)
{
    echo "<DIV id=contentWrapper><DIV id=content>";
    echo "<H1 class=title>Add Profile</H1>";
    echo "<p>No profile types were found. Setup did not import the required profiletypes seed data.</p>";
    echo "<p>Run setup again and check the database import validation output.</p>";
    echo "</DIV></DIV>";
    echo PageFooter();
    echo "</BODY></HTML>";
    exit;
}
?>

  <DIV id=contentWrapper>
   <DIV id=content>
    <H1 class=title>Add Profile</H1>

    <div id="config">
     <div id="config-content">
      <form id="configForm" action="add-profile.php" method="post">
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
           <input type="text" name="main_dir" id="main_dir" value="" size="35" readonly>
          </label>
         </div>		
		 
         <div class="field-pair">
          <label class="nocheck clearfix" for="move_dir">
           <span class="component-title">Move Folder</span>
           <input type="text" name="move_dir" id="move_dir" value="" size="35" readonly>
          </label>
         </div>		 
        </div><!-- /component-group1 //-->
        <div id="core-component-group2" class="component-group clearfix">

         </fieldset>
		 <div class="component-group-desc">
          <h3>Misc</h3>
          <p>Hashing can slow things down quite a bit if scanning large folders!</p>
         </div>		 
		 
         <fieldset class="component-group-list">

         <div class="field-pair">
          <label class="nocheck clearfix" for="name_presets">
           <span class="component-title">Hashing:</span>
           <span class="component-desc">
            <select name="crc32" id="crc32" class="input-medium" >
             <option value="0">File Size</option>
             <option value="1" SELECTED>CRC32 3 Part</option>
             <option value="2">CRC32 Full</option>
            </select>
           </span>
          </label>
         </div>

         <div class="field-pair">
          <label class="nocheck clearfix" for="name_presets">
           <span class="component-title">Type:</span>
           <span class="component-desc">
            <select name="type" id="type" class="input-medium" OnChange="UpdateExts()">			
			<?php				
foreach($extSel as $key => $val)
{
    $id = intval($key);
    $nameHtml = DupHtml($val);

    echo "<option value=\"" . $id . "\">" . $nameHtml . "</option>\n";
}
			?>		 
            </select>
           </span>
          </label>
          <label class="nocheck clearfix">
           <span class="component-title">&nbsp;</span>
           <span class="component-desc">Only add these types of files</span>
          </label>
         </div>
		 
         <div class="field-pair">
          <label class="nocheck clearfix">
           <span class="component-title">File types</span>
           <input type="text" id="filetypes" name="filetypes" value="<?php echo DupHtml(GetExt($id)); ?>" size="35" />
          </label>
          <label class="nocheck clearfix">
           <span class="component-title">&nbsp;</span>
           <span class="component-desc">Types of Files to Scan</span>
          </label>
         </div>

         <div class="field-pair">
          <label class="nocheck clearfix">
           <span class="component-title">File Size</span>
           <input type="number" name="msize" value="0" min="0" step="1" size="35" />
          </label>
          <label class="nocheck clearfix">
           <span class="component-title">&nbsp;</span>
           <span class="component-desc">Minamum size of files to be scanned (MB). 0 scans all files</span>
          </label>
         </div>
        </fieldset>
       </div><!-- /component-group2 //-->
       <div class="component-group-save">
        <input type="submit" class="btn config_submitter" value="Add Profile" />
       </div>
	   <br />
      </div><!-- /config-components -->
     </form>
    </div>
   </div>
   
  <?php $exts = GetExts(); ?>
   
	<script type="text/javascript" charset="utf-8">
	<!--
	$(document).ready(function(){
		$('#main_dir').fileBrowser({ title: 'Select Main Directory' });
		$('#move_dir').fileBrowser({ title: 'Select Move Directory' });
	});
	//-->
	</script>
	
	<script type="text/javascript" charset="utf-8">
	<!--	
	function UpdateExts()
	{  
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

   </DIV><!-- /content -->
  </DIV><!-- /contentWrapper -->
  
  <?php echo PageFooter(); ?>
  
 </BODY>
</HTML> 
