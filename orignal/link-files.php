<?php
include("dup.php");
$dup    = new dup($profile);
$action = "Link";
LogStr($action."ing Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);





if(($dup->Profile['scan']==0 and $dbfile==0) or (ScanRunning()==false and $dbfile==1))
{			
	try	{
		$query = "UPDATE status SET value='Saving $action list...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt   = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		continue;
	}  	
	
		
	$dblink->beginTransaction();
	
	foreach($_POST["oldname"] as $key=>$val)
	{			
		foreach($val as $linkkey=>$link)
		{			
			if($_POST[$key] != $link and strlen($link)>0 and strlen($_POST[$key])>0)// not the same!!
			{					
				try	{
					$query = "INSERT $sqliteOr IGNORE INTO files_".strtolower($action)." (file, new, profile) VALUES('".SafeSQLValue($key)."', '".SafeSQLValue($_POST["newname"][$key])."', $profile);";			
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
					$sdt = $dblink->query($query);			
				}
				catch(PDOException $ex) {
					LogError(print_r($ex, true));
					continue;
				} 				
			}
			else
				LogStr("Linking Failed: File's the same/links invalid?  ".$_POST[$key]." -> $link", 2, __FILE__, __LINE__, __FUNCTION__);
		}
	}

	$dblink->commit();
		
	try	{
		$dup->Profile['scan'] = 1;
		$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=".$dup->Profile['id'].";";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt   = $dblink->query($query);

		$query = "UPDATE status SET value='Starting $action...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt   = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}  
	
	try	{
		LogStr("Scan: shell_exec(\"php -q action-files-thread.php $profile Rename >/dev/null 2>/dev/null &\");", 3, __FILE__, __LINE__, __FUNCTION__);
		               shell_exec("php -q action-files-thread.php $profile Rename >/dev/null 2>/dev/null &");
	}
	catch(Exception $ex) {
		LogError(print_r($ex, true));
	} 
}
else
{
	if($dbfile==0)
		LogStr("Scan: Running, wait till it's stopped!!", 1, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("Scan: sqlite can only allow 1 thread access at a time for large updates!!", 1, __FILE__, __LINE__, __FUNCTION__);
}
	
header("Location: index.php");
//--------------------------------------------------------------------------------------------------------
/*
<?php


if(count($_POST["oldname"])>0)
{				
	foreach($_POST["oldname"] as $hash=>$mainfile)
	{			
		foreach($mainfile as $linkkey=>$link)
		{			
			if($_POST[$hash] != $link and strlen($link)>0 and strlen($_POST[$hash])>0)// not the same!!
			{							
				if(is_file($_POST[$hash])) // we have a valid file to work form
				{
					$query = "INSERT $sqliteOr IGNORE INTO files_link (old, new, profile) VALUES('".SafeSQLValue($_POST[$hash])."', '".SafeSQLValue($link)."', $profile);";			
				
					try	{
						LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
						$sdt = $dblink->query($query);
						
					}
					catch(PDOException $ex) {
						LogError(print_r($ex, true));
						header("Location: error.php");	
						Exit;
					} 				
				}
			}
			else
				LogStr("Linking Failed: File's the same/links invalid?  ".$_POST[$hash]." -> $link", 2, __FILE__, __LINE__, __FUNCTION__);
		}
	}
	
	try	{
		LogStr("Scan: shell_exec(\"php -q action-files-thread.php $profile Link >/dev/null 2>/dev/null &\");", 3, __FILE__, __LINE__, __FUNCTION__);
		               shell_exec("php -q action-files-thread.php $profile Link >/dev/null 2>/dev/null &");
	}
	catch(Exception $ex) {
		LogStr("shell_exec: ".$e->getMessage(), 2, __FILE__, __LINE__, __FUNCTION__);		
		$LastPHPError = error_get_last();	
		LogStr("shell_exec: ".$LastPHPError['message'], 2, __FILE__, __LINE__, __FUNCTION__);		
		LogError(print_r($ex, true));
	} 
}

header("Location: index.php");

?>
*/