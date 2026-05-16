<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Update Exts '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

if(isset($_POST['delpt']))
{
	$query = "DELETE FROM profiletypes WHERE id=".$_POST['profiletype'].";";	
		
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	
		if($dbfile==0)
			$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to MySQL - deleting, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";	
		else
			$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to sqlite - deleting, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";		
		
		SetMessage($query);
		Exit;
	}		 
}

if(isset($_POST['ptname']) and strlen($_POST['ptname'])>0)
{
	$query = "INSERT $sqliteOr IGNORE INTO profiletypes (exts, name) VALUES('', '".$_POST['ptname']."');";	
	
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		
		if($dbfile==0)
			$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to MySQL - adding, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";	
		else
			$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to sqlite - adding, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";		
		
		SetMessage($query);
		Exit;
	}	 
}

if(isset($_POST['profiletype']))
{	
	if(isset($_POST['exts']) and strlen($_POST['exts'])>0 and !isset($_POST['delpt']))
	{
		$query = "UPDATE profiletypes SET exts='".$_POST['exts']."' WHERE id=".$_POST['profiletype'].";";	
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	
			$sdt = $dblink->query($query);
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			
			if($dbfile==0)
				$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to MySQL - updating 1, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";	
			else
				$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to sqlite- updating 1, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";		
			
			SetMessage($query);
			Exit;
		}	 
	}	
	else if(strlen($_POST['exts'])==0 and !isset($_POST['delpt']))
	{
		$query = "UPDATE profiletypes SET exts='".$dup->Profile['filetypes']."' WHERE id=".$_POST['profiletype'].";";	
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));

			if($dbfile==0)
				$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to MySQL - updating 2, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";	
			else
				$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to sqlite - updating 2, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";		
			
			SetMessage($query);
			Exit;
		}	
	}
}

if($dbfile==0)
    $query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"MySQL database updated\", \"type\": \"notice\", \"title\": \"Profile Types Saved\"}}';";	
else
    $query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"sqlite database updated\", \"type\": \"notice\", \"title\": \"Profile Types Saved\"}}';";		

SetMessage($query);

//-------------------------------------------------------------------------------
function SetMessage($query)
{
	global $dblink;
	
	try
	{		
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}
}
?>