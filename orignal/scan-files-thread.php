<?php
include("dup.php");
$dup = new dup($profile);
ini_set("max_execution_time", $dup->settings['runtime']); 
LogStr("Scan Files", 1, __FILE__, __LINE__, __FUNCTION__);

$dup->Profile['scan'] = 1;
$skips      = explode(",", $dup->Settings['ifolders']);
$skips      = array_unique($skips);
$DATA       = "";
$mfs        = ((1024*1024)*$dup->Profile['msize']); // min file size
$folder     = $dup->Profile['path'];
$filetypesa = explode(",", strtolower($dup->Profile['filetypes'])); // files types array
$num        = 1;
$NumofFiles = 0;
$max        = 0;

$datafile   = "logs/".$dup->Profile['id'].".txt";

/* 4818CFEA  = 0 filled files */
if(strlen(trim($filetypesa[0]))==0)
{
	$filetypesa = null;
	$filetypesa = array(); // reset empty arrayo"empty\n";
}

echo "Scanning...\n";

try
{
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$sdt->closeCursor();
	
	$query = "UPDATE status SET value='Scanning Files', data='ls -lRe \"".SafeSQLValue($dup->Profile['path'])."\"' WHERE profile=$profile;"; 
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$sdt->closeCursor();
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}	

if(!is_file($datafile))
{
	try
	{
		@unlink($datafile);// delete old file, shouldnt be here!!
		    LogStr("ls -lRe \"".$dup->Profile['path']."\" >> $datafile", 2, __FILE__, __LINE__, __FUNCTION__);
		shell_exec("ls -lRe \"".$dup->Profile['path']."\" >> $datafile"); // ls to file instead	// ls -lReA1x
	}
	catch(Exception $ex) {
		LogError(print_r($ex, true));
		Exit;	
	} 
}

try
{
	           LogStr("wc -l $datafile", 2, __FILE__, __LINE__, __FUNCTION__);
	$max = shell_exec("wc -l $datafile");	// line count
	$max = GetWCValue($max);
}
catch(Exception $ex) {
	LogError(print_r($ex, true));
	Exit;	
}

try
{
	$query = "UPDATE status SET value='Processing Scan', data='Looping through $max entrys and sorting' WHERE profile=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	$dblink->beginTransaction();	
	$query = "DELETE FROM files WHERE profile=$profile;";		
	$sdt = $dblink->query($query);	
	$dblink->commit();
	
	//if($dbfile==1)
	//{
	//	$query = "BEGIN TRANSACTION;";
	//	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	//	$sdt = $dblink->query($query);
	//}
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}

echo "Inserting...\n";
$handle = fopen($datafile, "r");

if($handle) 
{
	$dblink->beginTransaction();
	
    while(($value = fgets($handle)) !== false and $dup->Profile['scan']!=0)
	{
		$l = namedexplode($value);
			
		if($l['size']=="Folder") // folder check
				$folder = $l['file']."/";
		else if(is_file($folder.$l['file']) or is_link($folder.$l['file']) ) // files only, links too
		{					
			if($l['size']>=$mfs and in_arrayi($l['ext'], $filetypesa) and FolderCheck($folder)==false and $l['size']>0) //size check and ext check
			{				
				try
				{			
					if($num%50==0) // every 100th record do a check
					{
						$query = "UPDATE status SET value='Processing Entry (".number_format($num)." / ".number_format($max).")',  data='File Added ".SafeSQLValue($folder.$l['file'])."' WHERE profile=$profile;";
						LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
						echo $query."\n";
						$sdt = $dblink->query($query);		
						$sdt->closeCursor();						
						$dup->Profile['scan'] = CheckStatus($profile); // less checks, only checks on file found, should be faster and less logs				
					}

					$l['link'] = SafeSQLValue($l['link']);					
					$query = "INSERT $sqliteOr IGNORE INTO files (file,size,fdate,crc32,profile, link) VALUES('".SafeSQLValue($folder.$l['file'])."', ".$l['size'].", ".$l['fdate'].", 'unknown', $profile,'".$l['link']."');";
					
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
					$sdt = $dblink->query($query);
					$sdt->closeCursor();
				}
				catch(PDOException $ex) {
					LogError(print_r($ex, true));
					Exit;
				}				    		
				
				$NumofFiles++;
			}
		}
		
		$num++;	
	}
	
	$dblink->commit();
	$sdt->closeCursor();
}

@unlink($datafile);// delete ls file

if($dup->Profile['crc32']>0)
	$max = HashFiles($dup);
else
	$max = GetTotalByCRC32($profile);

try
{
	//if($dbfile==1)
	//{
//		$query = "COMMIT;";
//		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
//		$sdtw = $dblink->query($query);
//	}
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}	

try
{
	$query = "UPDATE profiles SET dups=$max WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$sdt->closeCursor();
	
	$dup->Profile['scan'] = 0;
	$query = "UPDATE profiles SET scan=".$dup->Profile['scan']." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	
	$sdt = $dblink->query($query);
	$sdt->closeCursor();

	$query = "UPDATE profiles SET runs=".($dup->Profile['runs']+1)." WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$sdt->closeCursor();
	
	$query = "UPDATE profiles SET files = (SELECT COUNT(id) FROM files WHERE files.profile = $profile) WHERE id=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$sdt->closeCursor();
	
	$query = "UPDATE status SET value='Refreshing', data='Refreshing' WHERE profile=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	$sdt->closeCursor();
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}
//--------------------------------------------------------------------------------------------
function GetIDs(&$idarray, $link)
{
	global $dblink;	
	
	try{
		do{
			$query = "SELECT id, file, crc32, link FROM files WHERE file='$link';";
			$sdt   = $dblink->query($query);	
			$link  = ""; // stop loop!
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$idarray['ids'][] = $row['id'];
				$link             = $row['link']; // has to be set, if not its already blank!
				$idarray['crc32'] = $row['crc32'];// last entry will be the file crc32
			}
		}
		while(is_link($link));
	}
	catch(PDOException $ex) {
		return;
	}
}
//--------------------------------------------------------------------------------------------
function HashFiles($DUP)
{
	global $dblink;
	global $profile;
	global $mfs;
	global $max;
	global $num;
	$num = 1;
	echo "Hashing...\n";
		
	//stat update
	$query = "UPDATE status SET value='Hashing...', data='Looking for files to hash.' WHERE profile=$profile;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	$sdt->closeCursor();
	
	echo "size\n";
	$max = GetTotalDupsByType($profile, 'size', 1); // number of files found
	echo "max: ".$max."\n";
		
	// get matching files based on size
	$query = "SELECT id FROM files INNER JOIN (SELECT size, COUNT(size) FROM files WHERE size>=$mfs AND profile=$profile GROUP BY size HAVING COUNT(size)>1) as files2 ON files.size = files2.size WHERE profile=$profile;";
	//echo "size: ".$query."\n";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	

	while($row = $sdt->fetch(PDO::FETCH_ASSOC))
	{
		$file = GetFileFromFileID($row['id']);
		$hash = GetFileHash($file);	
		
		$query = "UPDATE files SET crc32='$hash' WHERE id=".$row['id'].";";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt2 = $dblink->query($query);
		$sdt2->closeCursor();
		
		if($num%20==0) // every 100th record do a check
		{
			$dup->Profile['scan'] = CheckStatus($profile); // less checks, only checks on file found, should be faster and less logs
				
			try
			{
				$query = "UPDATE status SET value='Hashing... (".number_format($num)." / ".number_format($max).")', data='".SafeSQLValue($file)."' WHERE profile=$profile;";
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				echo $query."\n";
				$sdt2 = $dblink->query($query);
				$sdt2->closeCursor();
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				Exit;
			}
			
			if($dup->Profile['scan']==0)
				break;			
		}
			 
		$num++;	
	}	
	
	$sdt->closeCursor();
	
	$num   = 1;
	$symlinks = array();
	
	try	{	// update symlink files
		$query = "SELECT id, file, link FROM files WHERE link!='' and profile=$profile;";		
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
			
		// could be quite large!! - posible change, get count first query!
		$symlinks = $sdt->fetchAll();// will always be under 1000 so no memory worries ;)
		$max      = $symlinks['count'];
		unset($symlinks['count']);
		$sdt->closeCursor();
	}	
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}	

	foreach($symlinks as $key => $row)	//while($row = $sdt->fetch(PDO::FETCH_ASSOC))
	{	
		$file = UpdateSymlink($row['id'], $row['link']);
			
		try	{
			$query = "UPDATE status SET value='Looking up Symlinks...($num/$max)', data='".SafeSQLValue($file)."' WHERE profile=$profile;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			$sdt->closeCursor();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
		}	
			 
		$num++;	
	}		

	return GetTotalByCRC32($profile); 
}
//--------------------------------------------------------------------------------------------
function GetFileHash($FILE)
{
	global $dup;
	$crc32 = "";
		
	if($dup->Profile['crc32']==2)
		$crc32 = $dup->full_file_crc($FILE);
	else
		$crc32 = $dup->file_crc($FILE);
			
	return $crc32;
}
//--------------------------------------------------------------------------------------------
function GetFileFromFileID($ID)
{
	global $dblink;	
	$value = "";
		
	try	{
		$query = "SELECT file FROM files WHERE id=$ID LIMIT 1";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value = FromSafeSQLValue($row['file']);
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));	
		Exit;
	}  
	
	return $value;
}
//--------------------------------------------------------------------------------------------
function UpdateSymlink($FILEID, $LINK)
{
	global $dblink;	
	global $profile;
	
	$query = "SELECT id, file, crc32, link FROM files WHERE file='$LINK' and profile=$profile;";
	//echo $query."\n";
	//LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	$ids = array();
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))
	{
		$file = $row['file'];
		$Hash = "unknown";
		
		GetIDS($ids, $file);
		
		if(count($ids['ids'])>0)// got a file/links, should have atleast 1
		{
			$Hash   = $ids['crc32'];
			$FileID = array_pop($ids['ids']); // last entry will be the file!!, get its crc and file to hash if need be!		
			$File   = GetFileFromFileID($FileID);			
			
			if($Hash=="unknown")
			{
				$Hash = GetFileHash($File);	
				$ids['ids'][] = $FileID;//re add file id to update it too if need be!
			}
		}
		
		$ids['ids'][] = $FILEID; // add main files id too
		
		foreach($ids['ids'] as $key => $val)
		{
			$query = "UPDATE files SET crc32='$Hash' WHERE id=$val;";
			$sdt2 = $dblink->query($query);	
			$sdt->closeCursor();
		}
	}	
}
//--------------------------------------------------------------------------------------
function GetTotalBySize($ID)
{
	global $dblink;
	$total = 0;
		
	$query = "SELECT COUNT(size) FROM files WHERE profile=$ID GROUP BY size HAVING COUNT(size)>1;";
	//echo $query."\n";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	$sdt->closeCursor();
		
	return $total;
}
//--------------------------------------------------------------------------------------
function GetTotalByCRC32($ID)
{
	global $dblink;
	$total = 0;
		
	$query = "SELECT crc32, COUNT(crc32) AS cnt FROM files WHERE crc32!='unknown' AND profile=$ID GROUP BY crc32 HAVING COUNT(crc32)>1 ORDER BY files.file;";			
	//echo $query."\n";	
	//$query = "SELECT COUNT(size) FROM files WHERE profile=$ID GROUP BY size HAVING COUNT(size)>1;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	$sdt->closeCursor();
		
	return $total;
}
//--------------------------------------------------------------------------------------
/*
function GetTotalDupsByType($ID, $TYPE, $SKIP)
{
	global $dblink;
	global $dup;
	$mfs        = ((1024*1024)*$dup->Profile['msize']); // min file size
	$total = 0;
	$additional = "";
	
	if($TYPE=="crc32" and $SKIP==0)
		$additional = "AND crc32!='unknown'";
		
	$query = "SELECT id FROM files INNER JOIN (SELECT $TYPE, COUNT($TYPE) FROM files WHERE size>=$mfs $additional AND profile=$ID GROUP BY $TYPE HAVING COUNT($TYPE)>1) as files2 ON files.$TYPE = files2.$TYPE WHERE profile=$ID;";
	//echo $query."\n";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	$sdt->closeCursor();
		
	return $total;
}
*/
//--------------------------------------------------------------------------------------
/*
function HashFile($id)
{
	global $dup;
	global $dblink;
	
	$file  = GetFile($id);	
	$crc32 = "";
		
	if($dup->Profile['crc32']==2)
		$crc32 = $dup->full_file_crc($file);
	else
		$crc32 = $dup->file_crc($file);
		
	try
	{
		$query = "UPDATE files SET crc32='$crc32' WHERE id=$id;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}	
	
	return $file;
}
*/
//--------------------------------------------------------------------------------------
function GetFile($ID)
{
	global $dblink;	
	$value = "";
		
	try
	{
		$query = "SELECT file FROM files WHERE id=$ID LIMIT 1";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value = FromSafeSQLValue($row['file']);
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));	
		Exit;
	}  
	
	return $value;
}	
//--------------------------------------------------------------------------------------
function CheckStatus($pro)
{
	global $dblink;
	LogStr("CheckStatus() Check a profiels status via passed pram - $pro", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = array();//"";
	
	try
	{
		$query = "SELECT scan FROM profiles WHERE id=$pro;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value = $row['scan'];

		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		Exit;
	}
	
	return $value;
}
//--------------------------------------------------------------------------------------
function FolderCheck($file)
{
	global $skips;
	LogStr("FolderCheck() Skip a file based on passed pram - $file", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(count($skips>0))
	{		
		$folders = explode("/", $file);
		$folders = array_unique($folders);
		
		foreach($skips as $key => $val)
		{
			if(in_array($val, $folders) and strlen($val)>0)
				return true;
		}
	}
	
	return false;	
}

//--------------------------------------------------------------------------------------
function in_arrayi($needle, $haystack) 
{
	@LogStr("in_arrayi() Check a file type via passed pram - $needle - ".implode(',', $haystack), 2, __FILE__, __LINE__, __FUNCTION__);
	$value = false;
	$ct    = count($haystack);
	
	if($ct==0) // no files types set
	{
		$value = true; // scan all file types
		LogStr("in_arrayi() 1 False $ct", 2, __FILE__, __LINE__, __FUNCTION__);
	}
	else if(strlen(trim($needle))>0 and $ct!=0) // file types set
	{
		$value = in_array(strtolower($needle), array_map('strtolower', $haystack));
		LogStr("in_arrayi() 2 True $ct", 2, __FILE__, __LINE__, __FUNCTION__);
	}
	else
		LogStr("in_arrayi() 3 False $ct", 2, __FILE__, __LINE__, __FUNCTION__);
	
	return $value;	
}
//--------------------------------------------------------------------------------------
function namedexplode($ArrayData) 
{
	$value    = array();	
	$tmpArray = explode("\t", rtrim(ProcesslsStr($ArrayData), '/'));
	
	$value['size']  = $tmpArray[0];
	$value['fdate'] = $tmpArray[1];
	$value['file']  = $tmpArray[2];
	$value['ext']   = $tmpArray[3];
	$value['link']  = $tmpArray[4];
	
	return $value;
}
//--------------------------------------------------------------------------------------
function specified_array_unique($array, $value) 
{ 
    $count = 0; 
    
    foreach($array as $array_key => $array_value) 
    { 
        if(($count>0) && ($array_value==$value)) 
            unset($array[$array_key]); 
        
        if($array_value == $value) 
			$count++; 
    } 
    
    return array_filter($array); 
} 
//--------------------------------------------------------------------------------------
function GetWCValue($data)
{
	$value = 0;
	
	if(strstr($data, " ")) 
		return strstr($data, " ", true);
		
	return $value;
}
//--------------------------------------------------------------------------------------
?>