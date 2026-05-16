<?php
include("dup.php");
$dup    = new dup($profile);
LogStr("Action $action '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);

if($dup->Profile['scan']==1)
{	
	LogStr("action-files-thread: Profile Scan already running? Exiting!", 2, __FILE__, __LINE__, __FUNCTION__);
	Exit;
}

$max                  = 0;
$num                  = 1;
$FilesToAction        = array();
$dup->Profile['scan'] = 1;

try	{	
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=".$dup->Profile['id'].";";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt   = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}  
	
try
{
	$query = "SELECT * FROM files_".strtolower($action)." where profile=$profile;";// dont move all!!
	LogStr("MySQL: $query", 2, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	$FilesToAction = $sdt->fetchAll();// will always be under 1000 so no memory worries ;)
	unset($FilesToAction['count']);
	$max = count($FilesToAction);
	
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}  
	
// each block should control their own errors, taken out of above try catch to continue if one file fails.	
foreach($FilesToAction as $key => $row)
{			
	switch($action)
	{
		case "Rename": Rename_File($row['file'], $row['new'], $dup->Settings['renametype']); break;
		case "Delete": Delete_File($row['file']);             								 break;
		case "Move"  : Move_File(  $row['file'], $row['new'], $dup->Settings['renametype']); break;
		case "Link"  : Link_File(  $row['file'], $row['new']); 							     break;
		default      : continue;
	}
		
	if($num%20==0)
	{				
		try	{
			$query = "UPDATE status SET value='$action... (".number_format($num)."/".number_format($max).")', data='".SafeSQLValue($oldname)."' WHERE profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}  
	}			

	$num++;
}

//----------------------------------------------------------------------------	
ResetProfileStatus();
// reset table we just worked on
//----------------------------------------------------------------------------
// Table cleanups
// reset profile conunts?
try { // set to refresh
	$query = "OPTIMIZE TABLE files_".strtolower($action).";";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}
//----------------------------------------------------------------------------
try { // set to refresh - force main page refresh
	$query = "UPDATE status SET value='Refreshing', data='Refreshing' WHERE profile=".$dup->Profile['id'].";";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}
//----------------------------------------------------------------------------
function ResetProfileStatus()
{
	global $profile;
	global $dup;
	global $dblink;
	global $action;
	
	try { // clean up scan status
		$dup->Profile['scan'] = 0;
		$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=".$dup->Profile['id'].";";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		$query = "DELETE FROM files_".strtolower($action)." where profile=$profile;";// don't remove all!!
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		$type = ($dup->Profile['crc32']==0)?"size":"crc32";		
		$dups = GetTotalDupsGroupsByType($profile, $type, 0); // number of files found - total, not dup sets		
		$query = "UPDATE profiles SET files = (SELECT COUNT(id) FROM files WHERE files.profile = $profile), dups=$dups WHERE id=$profile;";
		
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);		
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	} 
}
//----------------------------------------------------------------------------
function Rename_File($old, $new, $type)
{
	LogStr("Rename_File() renames file using shell_exec mv cmd based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $profile;
	global $dblink;
	
	$finfo = pathinfo($old); 	
	$new = $finfo['dirname']."/".$new;	
	
	if($type==0 and is_file($new)) // fail	
	{
		LogStr("Rename Failed - File already exists!", 3, __FILE__, __LINE__, __FUNCTION__);
		return;
	}
	else if($type==1 and is_file($new)) // auto rename (number)
	{
		$new = GetNewName($new);
	}
	else if($type==2 and is_file($new)) // overwrite	
	{
		if(NewDelete($new))
		{
			$query = "DELETE FROM files WHERE file='".SafeSQLValue($new)."' AND profile=$profile;"; // needs to be removed if deleted!
			$sdt = $dblink->query($query);	
		}	
	}

	$trgFile = escapeshellarg($new);
	$srcFile = escapeshellarg($old);
	$res     = shell_exec("mv $srcFile $trgFile");
	LogStr("mv $srcFile $trgFile", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(is_file($new))
	{
		LogStr("Rename Succeeded!", 2, __FILE__, __LINE__, __FUNCTION__);
				
		try	{
			$query = "UPDATE files SET file='".SafeSQLValue($new)."' WHERE file='".SafeSQLValue($old)."' and profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			$query = "DELETE FROM files_rename WHERE file='".SafeSQLValue($old)."' AND profile=$profile;";
			$sdt = $dblink->query($query);				
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			continue;
		}		
	}
	else
		LogStr("Rename Failed! - ".print_r($res, true), 2, __FILE__, __LINE__, __FUNCTION__);
}
//----------------------------------------------------------------------------
function GetNewName($name)
{
	LogStr("GetNewName() Gets name for file that doesn't exists based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	$num        = 1;	
	
	if(!preg_match("/\(\d+\)/", $name))
	{
		$name_parts = pathinfo($name);
		$name       = $name_parts['dirname']."/".$name_parts['filename']." (".$num.").".$name_parts['extension'];
	}
	
	while(is_file($name))
	{
		$name = preg_replace("/\(\d+\)/", "(".$num.")", $name);			
		$num++;
	}
	
	return $name;
}
//----------------------------------------------------------------------------
function Move_File($old, $new, $type)
{
	LogStr("Move_File() moves a file using shell_exec mv cmd based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $dblink;
	global $profile;	
	
	if($type==0 and is_file($new)) // fail	
	{
		LogStr("Move Failed - File already exists!", 3, __FILE__, __LINE__, __FUNCTION__);
		return;
	}
	else if($type==1 and is_file($new)) // auto rename (number)
	{
		$new = GetNewName($new);
	}
	else if($type==2 and is_file($new)) // overwrite	
	{
		if(NewDelete($new))
		{
			$query = "DELETE FROM files WHERE file='".SafeSQLValue($new)."' AND profile=$profile;"; // needs to be removed if deleted!
			$sdt = $dblink->query($query);	
		}	
	}
	
	$trgFile = escapeshellarg($new);
	$srcFile = escapeshellarg($old);	
	$nameparts = pathinfo($new);
				
	if(is_dir($nameparts['dirname'])==false) // dir not there?
	{
		if(mkdir($nameparts['dirname'], 0777, true)) // required for moving and no folder flattening
			LogStr("mkdir() Suscess: ".$nameparts['dirname'], 2, __FILE__, __LINE__, __FUNCTION__);
		else
			LogStr("mkdir() Failed: ".$nameparts['dirname'], 2, __FILE__, __LINE__, __FUNCTION__);
	}	

	$res = shell_exec("mv $srcFile $trgFile");
	LogStr("mv $srcFile $trgFile", 2, __FILE__, __LINE__, __FUNCTION__);

	if(is_file($new))
	{
		LogStr("Move Succeeded!", 2, __FILE__, __LINE__, __FUNCTION__);
		
		try	{
			$query = "UPDATE files SET file='".SafeSQLValue($new)."' WHERE file='".SafeSQLValue($old)."' and profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			$query = "DELETE FROM files_move WHERE file='".SafeSQLValue($old)."' AND profile=$profile;";
			$sdt = $dblink->query($query);				
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}	
	}
	else
		LogStr("Move Failed! - ".print_r($res, true), 2, __FILE__, __LINE__, __FUNCTION__);
}
//----------------------------------------------------------------------------
function Link_File($old, $new)
{
	LogStr("Link_File() Symlinks a file using shell_exec ln -s cmd based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $profile;
	global $dup;
	global $dblink;
	
	if(NewDelete($new))// always with Linking!!
	{
	}
	
	$trgFile = escapeshellarg($new);
	$srcFile = escapeshellarg($old);
	$res     = shell_exec("ln -s $srcFile $trgFile");
	LogStr("ln -s $srcFile $trgFile", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(is_link($new))
	{
		LogStr("Link Succeeded!", 2, __FILE__, __LINE__, __FUNCTION__);
		
		try	{
			//$query = "UPDATE files SET link='".$row['file']."' WHERE file='".$row['link']."' and profile=$profile;";			
			$query = "UPDATE files SET link='".SafeSQLValue($old)."' WHERE file='".SafeSQLValue($new)."' and profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			$nsize = $dup->fsize($new);								
			$query = "UPDATE files SET size=$nsize WHERE link='".SafeSQLValue($old)."' and file='".SafeSQLValue($new)."' and profile=$profile;";	
			$sdt   = $dblink->query($query);

			$query = "DELETE FROM files_link WHERE file='".SafeSQLValue($old)."' AND profile=$profile;";
			$sdt = $dblink->query($query);				
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}
	}
	else
		LogStr("Link Failed! - ".print_r($res, true), 2, __FILE__, __LINE__, __FUNCTION__);
}
//----------------------------------------------------------------------------
function NewDelete($file)
{
	$res = shell_exec("rm -f \"$file\"");
	LogStr("shell_exec: rm -f \"$file\"", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(is_file($file))
		return false;
	else
		return true;
}
//----------------------------------------------------------------------------
function Delete_File($old)
{
	LogStr("Delete_File() deletes a file using NewDelete function based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $profile;
	global $dblink;
	
	if(NewDelete($old))
	{
		LogStr("Delete Succeeded!", 2, __FILE__, __LINE__, __FUNCTION__);
		
		try	{
			$query = "DELETE FROM files WHERE file='".SafeSQLValue($old)."' AND profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);		

			$query = "DELETE FROM files_delete WHERE file='".SafeSQLValue($old)."' AND profile=$profile;";
			$sdt = $dblink->query($query);				
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}		
	}
	else
		LogStr("Delete Failed! - $old", 2, __FILE__, __LINE__, __FUNCTION__);
}
//----------------------------------------------------------------------------
?>