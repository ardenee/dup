<?php
include("dup.php");
$dup    = new dup($profile);
$action = "Move";
LogStr($action."ing Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

if(($dup->Profile['scan']==0 and $dbfile==0) or (ScanRunning()==false and $dbfile==1))
{				
	try	{
		$query = "UPDATE status SET value='Saving $action list...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt   = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true)); //$ex->getMessage()
		continue;
	}  	
	
	$dblink->beginTransaction();
	
	foreach($_POST["oldname"] as $key=>$val)
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
function ScanRunning()
{
	global $dblink;
	global $profile;
		
	try	{
		$query = "SELECT scan FROM profiles where id=$profile;";
		LogStr("MySQL: $query", 2, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			if($row['scan']==1)
				return true;
		}	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}  
	
	return false;
}
//--------------------------------------------------------------------------------------------------------

/*<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Move Files '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

if(($dup->Profile['scan']==0 and $dbfile==0) or (ScanRunning()==false and $dbfile==1))
{		
	$query = "UPDATE status SET value='Saving Move...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		
	try	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}  	
	
	foreach($_POST["oldname"] as $key=>$val)
	{	
		$query = "INSERT $sqliteOr IGNORE INTO files_move (old, new, profile) VALUES('".SafeSQLValue($key)."', '".SafeSQLValue($_POST["newname"][$key])."', $profile);";			
			
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

	$dup->Profile['scan'] = 1;
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=".$dup->Profile['id'].";";
		
	try	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	} 		
	
	$query = "UPDATE status SET value='Starting Move...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		
	try	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}  
	
	$ScriptParts = pathinfo(__FILE__);
	
	try	{
		LogStr("Scan: shell_exec(\"php -q action-files-thread.php $profile Move >/dev/null 2>/dev/null &\");", 3, __FILE__, __LINE__, __FUNCTION__);
		               shell_exec("php -q action-files-thread.php $profile Move >/dev/null 2>/dev/null &");
	}
	catch(Exception $ex) {
		LogStr("shell_exec: ".$e->getMessage(), 2, __FILE__, __LINE__, __FUNCTION__);		
		$LastPHPError = error_get_last();	
		LogStr("shell_exec: ".$LastPHPError['message'], 2, __FILE__, __LINE__, __FUNCTION__);		
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
function ScanRunning()
{
	global $dblink;
	$query = "SELECT scan FROM profiles;";
		
	try	{
		LogStr("MySQL: $query", 2, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			if($row['scan']==1)
				return true;
		}	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}  
	
	return false;
}
//--------------------------------------------------------------------------------------------------------
?>
*/