<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Renaming '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__); 

if($profile!=0 and $profile!=$dup->Profile['id'])
	$profile = $dup->Profile['id']; // force update
	
if(strlen($_POST['filters'])>0)
	$filters = $_POST['filters']; // force update
else
	$filters = $dup->Settings['cleanupfilter'];
	
$query = "SELECT file FROM files ORDER BY profile LIMIT 995;";

if($profile!=0)
	$query = "SELECT file FROM files WHERE profile=$profile ORDER BY file LIMIT 995;";
	
$MovedFolders = $dup->GetMovedFolders($profile);

try
{
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
		
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))
	{
		if(IsMovedFile($row['file'], $MovedFolders) and $dup->Settings['displaymoved']==0)
			continue;			
					
		$path_parts = pathinfo($row['file']);		
		$old = trim($row['file']);
		$new = trim($path_parts['dirname']."/".CleanName($path_parts['basename']));
				
		if(is_file($new) and $old!=$new) 
			$new = trim(MultiFile($new));	

		if(is_file($old))
		{// php bug saying file not found, this verifys it
		}
			
		if($old!=$new and !is_file($new))
		{		
			if(renameFile($old, $new))
			{
				LogStr("Rename Success: $old -> $new", 2, __FILE__, __LINE__, __FUNCTION__); // info level
				$query = "UPDATE files SET file='".SafeSQLValue($new)."' WHERE file='".SafeSQLValue($old)."'";					

				try {
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	// debug level
					$sdt2 = $dblink->query($query);
				}
				catch(PDOException $ex) {
					LogError(print_r($ex, true));
					header("Location: error.php");	
				}
			}	
			else
			{
				LogStr("Rename Failed: $old -> $new", 2, __FILE__, __LINE__, __FUNCTION__); // error level	
			}
		}
	}
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}	

header("Location: renaming.php?profile=".$profile."&filters=".$filters."");	
//----------------------------------------------------------------------------	
function MultiFile($name)
{
	LogStr("MultiFile() Gets profile ordered by passed pram", 2, __FILE__, __LINE__, __FUNCTION__);	
	$path_parts = pathinfo($name);
	$num = 1;
	
	while(is_file($name)) 
	{
		$path_parts = pathinfo($name);
		$path_parts['filename'] = str_replace(" (".($num-1).")", "", $path_parts['filename']);		
		$name = $path_parts['dirname']."/".$path_parts['filename']." (".($num).").".$path_parts['extension'];
		$num++;
	}
	
	return $name;
}
//----------------------------------------------------------------------------
function CleanName($name)
{
	LogStr("CleanName() Gets profile ordered by passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $dup;	
	$nameparts = pathinfo($name);	
	
	if($dup->Settings['namecleanup']==1)	
		return CleanUpName($nameparts['basename']);	
	else
		return $nameparts['basename']; // has ext
}
//----------------------------------------------------------------------------
function CleanUpName($name)
{
	LogStr("CleanUpName() Gets profile ordered by passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	global $dup;
	global $filters;
	
	$nameparts = pathinfo($name);
	$catches   = explode(",", $filters);
	$name      = $nameparts['filename'];
	
	foreach($catches as $key => $val)
		$name = str_ireplace($val, "", $name);	

	if($dup->Settings['dot']>0) 
		$name = str_ireplace(".", (($dup->Settings['dot']==1)?" ":"_"), $name);
	
	if($dup->Settings['under']>0) 
		$name = str_ireplace("_", (($dup->Settings['under']==1)?" ":"."), $name);

	if($dup->Settings['doubleb']>0) 
	{
		while(strpos($name, "  ")>0)
			$name = str_ireplace("  ", " ", $name);
	}
	
	if(strlen($nameparts['extension'])>0)	
		return trim($name).".".$nameparts['extension'];
	else
		return trim($name);
}
//----------------------------------------------------------------------------
?>