<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Files Scan '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

if(($dup->Profile['scan']==0 and $dbfile==0) or (ScanRunning()==false and $dbfile==1))
{	
	$dup->Profile['scan'] = 1;
	
	try	{
		$query = "UPDATE status SET value='Starting Scan...', data='".SafeSQLValue($dup->Profile['path'])."' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {			
		LogError(print_r($ex, true));
		header("Location: error.php");
		Exit;
	}   
	
	//$ScriptParts = pathinfo(__FILE__);
	
	try	{
		LogStr("Scan: shell_exec(\"php -q scan-files-thread.php $profile >/dev/null 2>/dev/null &\");", 3, __FILE__, __LINE__, __FUNCTION__);
		               shell_exec("php -q scan-files-thread.php $profile >/dev/null 2>/dev/null &");
	}
	catch(Exception $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");
		Exit;
	} 
}
else
{
	if($dbfile==0)
		LogStr("Scan: Already Running, will not re-run!!", 4, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("Scan: SQLite can only run 1 scan at a time!!", 4, __FILE__, __LINE__, __FUNCTION__);
}
	
header("Location: index.php");
//--------------------------------------------------------------------------------------------------------	
?>