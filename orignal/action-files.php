<?php
require("dup.php");
$dup       = new dup($profile);
LogStr("Action '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
$arrayData = ((isset($_POST['act'])==true)?$_POST:$_GET);
$form      = "error.php";
//---------------------------------------------------------------------------------------------------
switch($arrayData["act"])
{
	case "Delete" : $form = "files-delete.php"; break;
	case "Rename" : $form = "files-rename.php"; break;
	case "Move"   : $form = "files-move.php";   break;
	case "Link"   : $form = "files-link.php";   break;
	default       : unset($arrayData["act"]);   break;
}
//---------------------------------------------------------------------------------------------------
if(isset($arrayData["act"]))
{
	LogStr($arrayData["act"]." Action", 3, __FILE__, __LINE__, __FUNCTION__);
	echo "<body onload=\"handleClick()\">\n";
	echo "<form action=$form id=".$arrayData["act"]." name=".$arrayData["act"]." method=post>\n";
		
	if(is_array($arrayData["files"])) // put into array
	{		
		foreach($arrayData["files"] as $key => $val)
			echo " <input type=hidden name=\"files[]\" value=$val>\n";
	}
	else
		echo " <input type=hidden name=\"files[]\" value=".$arrayData["files"].">\n";
			
	echo " <input type=hidden name=\"from\" value=\"profile-view.php?profile=$profile\">\n";
	echo " <input type=hidden name=\"profile\" value=".$arrayData["profile"].">\n";	
	echo "</form>\n";	
	
	echo "<script type=\"text/javascript\">\n";  
	echo "function handleClick() {\n";
	echo "    document.getElementById('".$arrayData["act"]."').submit();\n";
	echo "}\n";
	echo "</script>\n";  
}
else
	header("Location: index.php");
?>