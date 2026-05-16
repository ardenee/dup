<?php
include("dup.php");
$dup    = new dup($profile);
LogStr($action."ing Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

//p($_POST);

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
	
	if($action=="Link")// slight format difference
	{
		foreach($_POST["oldname"] as $key=>$val)
		{					
			foreach($val as $linkkey=>$link)
			{				
				if($_POST[$key] != $link and strlen($link)>0 and strlen($_POST[$key])>0)// not the same!!
				{		
					try	{
						$query = "INSERT $sqliteOr IGNORE INTO files_".strtolower($action)." (file, new, profile) VALUES('".SafeSQLValue($_POST[$key])."', '".SafeSQLValue($link)."', $profile);";			
						LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
						$sdt = $dblink->query($query);			
					}
					catch(PDOException $ex) {
						LogError(print_r($ex, true));
						continue;
					} 				
				}
			}
		}
	}
	else
	{			
		foreach($_POST["oldname"] as $key=>$val)
		{			
			if(isset($_POST["newname"]))
				$newname = $_POST["newname"][$key];
			else
				$newname = $_POST["oldname"][$key];		
		
			try	{
				$query = "INSERT $sqliteOr IGNORE INTO files_".strtolower($action)." (file, new, profile) VALUES('".SafeSQLValue(FilterOff($key))."', '".SafeSQLValue($newname)."', $profile);";			
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);			
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				continue;
			} 		
		}
	}

	$dblink->commit();
	//Exit;

	try	{
		$query = "UPDATE status SET value='Starting ".$action."ing...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt   = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}  
	
	try	{
		LogStr("Scan: shell_exec(\"php -q action-files-thread.php $profile $action >/dev/null 2>/dev/null &\");", 3, __FILE__, __LINE__, __FUNCTION__);
		               shell_exec("php -q action-files-thread.php $profile $action >/dev/null 2>/dev/null &");
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
?>