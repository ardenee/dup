<?php
include("dup.php");
$dup = new dup($profile);
ini_set("max_execution_time", $dup->settings['runtime']); 
LogStr("Scan Update", 1, __FILE__, __LINE__, __FUNCTION__);

$mfs = ((1024*1024)*$dup->Profile['msize']); // min file size
$num = 1;
$dup->Profile['scan'] = 1;

try
{
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
} 
   
if($dup->Profile['crc32']>0)
{
	$num   = 1;
	
	try
	{
		$query = "UPDATE status SET value='Hashing...', data='Looking for files to hash.' WHERE profile=$profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		$max = GetTotal($profile, $mfs);
		echo $max."\n";
		
		$query = "SELECT id FROM files INNER JOIN (SELECT size, COUNT(size) FROM files WHERE size>=$mfs AND profile=$profile or size=17 AND profile=$profile GROUP BY size HAVING COUNT(size)>1 OR size=17) as files2 ON files.size = files2.size WHERE profile=".$profile.";";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);		
		//$sdt = $dblink->query("BEGIN TRANSACTION;");
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{		
			$file = HashFile($row['id']);
			$query = "UPDATE status SET value='Hashing... (".number_format($num)." / ".number_format($max).")', data='".$dup->SafeSQLString($dbfile, $file)."' WHERE profile=$profile;";
			
			try
			{
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt2 = $dblink->query($query);
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				Exit;
			}			
				 
			$num++;			
			$dup->Profile['scan'] = CheckStatus($profile);
			
			if($dup->Profile['scan']==0)// way to stop loops
				break;			
		}
		
		//$sdt = $dblink->query("COMMIT;");
		$query = "SELECT COUNT(crc32) FROM files WHERE crc32!='unknown' AND profile=$profile GROUP BY crc32 HAVING COUNT(crc32)>1;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt2 = $dblink->query($query);
		$max = count($sdt2->fetchAll());
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}	
}
else
{
	$query = "SELECT COUNT(size) FROM files WHERE profile=$profile GROUP BY size HAVING COUNT(size)>1;";
		
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt2 = $dblink->query($query);
		$max = count($sdt2->fetchAll());
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}
}
		
try
{
	$query = "UPDATE profiles SET dups=$max WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	$dup->Profile['scan'] = 0;
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	
	$sdt = $dblink->query($query);

	$query = "UPDATE profiles SET runs=".($dup->Profile['runs']+1)." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	$query = "UPDATE profiles SET files = (SELECT COUNT(id) FROM files WHERE files.profile = $profile) WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	$query = "UPDATE status SET value='Refreshing', data='Refreshing' WHERE profile=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}
//--------------------------------------------------------------------------------------
function GetTotal($ID, $SIZE)
{
	global $dblink;
	$total = 0;
	
	/*
	$query = "SELECT COUNT(id) AS total FROM files INNER JOIN (SELECT size, COUNT(size) FROM files WHERE size>=$SIZE AND profile=$ID or size=17 AND profile=$ID GROUP BY size HAVING COUNT(size)>1 OR size=17) as files2 ON files.size = files2.size WHERE profile=$ID;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total = $row['total'];	
	*/
		
	$query = "SELECT id FROM files INNER JOIN (SELECT size, COUNT(size) FROM files WHERE size>=$SIZE AND profile=$ID or size=17 AND profile=$ID GROUP BY size HAVING COUNT(size)>1 OR size=17) as files2 ON files.size = files2.size WHERE profile=$ID;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	return $total;
}

//--------------------------------------------------------------------------------------
function HashFile($id)
{
	global $dup;
	global $dblink;
	
	$file = GetFile($id);	
	$crc32 = "";
		
	if($dup->Profile['crc32']==2)
		$crc32 = $dup->full_file_crc($file);
	else
		$crc32 = $dup->file_crc($file);
				
	LogStr("Result: $crc32", 3, __FILE__, __LINE__, __FUNCTION__);
	$query = "UPDATE files SET crc32='$crc32' WHERE id=$id;";
			
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt2 = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}	
	
	return $file;
}
//--------------------------------------------------------------------------------------
function GetFile($ID)
{
	global $dblink;
	$query = "SELECT file FROM files WHERE id=$ID;";
	$value = "";
		
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$value = $row['file'];
				break;
		}	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}  
	
	return $value;
}	
//--------------------------------------------------------------------------------------
function CheckStatus($pro)
{
	global $dblink;
	LogStr("CheckStatus() Check a profiels status via passed pram - $pro", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$query = "SELECT scan FROM profiles WHERE id=$pro;";
	$value = array();//"";
	
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt3 = $dblink->query($query);
		
		while($row = $sdt3->fetch(PDO::FETCH_ASSOC))
			$value = $row['scan'];	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}
	
	return $value;
}
//--------------------------------------------------------------------------------------
?>