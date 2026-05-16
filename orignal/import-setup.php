<?php
include("dup.php");
$dup = new dup($profile);
$dir = dir("backup");
@unlink("backup/backup.data");// remove old log file with backup data in it

while(($file = $dir->read()) !== false)
{
	if(strlen($file) < 5 || substr($file, -4) != '.txt')
		continue;
		
	Import("backup/".$file);	
	//@unlink("backup/".$file);
}
$dir->close();
StatUpdate("Import Finnished\n");

//------------------------------------------------------------------------------------
function Import($file)
{
	global $dblink;
	global $dup;
	global $sqliteOr;
	
	$profileid = 1;	
	$max = shell_exec("wc -l '$file'");	// line count
	StatUpdate("Importing from file '$file' with ".number_format($max)." entries inside.\n");		

	try
	{	
		$handle    = fopen($file, "r");
		$filenum   = 1;	
		$Profile   = array();	
		$Settings  = array();		
		$Settings  = json_decode(fgets($handle), true);
		$Profile   = json_decode(fgets($handle), true);
		$profileid = AddProfile($Profile);
		AddSettings($Settings);
		
		//$sdt = $dblink->query("BEGIN TRANSACTION;");
		$active = true;

		while(($value = fgets($handle)) !== false) 
		{		
			$value = json_decode($value, true);
			
			if(strlen($value['file'])>0)
			{
				$query = "INSERT $sqliteOr IGNORE INTO files (file,size,fdate,crc32,profile) VALUES('".SafeSQLValue($value['file'])."', ".$value['size'].", ".$value['fdate'].", '".$value['crc32']."', $profileid);";
				$sdt   = $dblink->query($query);

				if($filenum%200==0)
					StatUpdate("Importing from file '$file' - Entry ".number_format($filenum)." of ".number_format($max)."\n");
			}
			
			$filenum++;			
		}
		
		//$sdt = $dblink->query("COMMIT;");
		fclose($handle);	
	}
	catch(PDOException $ex) {	
		LogError(print_r($ex, true));
		//header("Location: error.php");	
		Exit;
	}	
}
//------------------------------------------------------------------------------------
function AddProfile($Pro)
{
	global $dblink;
	global $sqliteOr;
	$id = 0;
	
	try	
	{
		$query = "INSERT $sqliteOr IGNORE INTO profiles (msize, path, mpath, crc32, filetypes, scan, type, files, dups, runs, lastrun) VALUES(".$Pro['msize'].", '".SafeSQLValue($Pro['path'])."', '".SafeSQLValue($Pro['mpath'])."', ".$Pro['crc32'].", '".SafeSQLValue($Pro['filetypes'])."', 0, ".$Pro['type'].", ".$Pro['files'].", ".$Pro['dups'].", ".$Pro['runs'].", '".$Pro['lastrun']."');";
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		//header("Location: error.php");	
		Exit;
	}
	
	$id = LastProfileID($Pro['path']);
	
	try	
	{
		$query = "INSERT $sqliteOr IGNORE INTO status (profile, value, data) VALUES($id, 'Idel', 'Complete');";	
		$sdt = $dblink->query($query);

		$query = "INSERT $sqliteOr IGNORE INTO viewpages (data, profile) VALUES('', $id);";
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		//header("Location: error.php");	
		Exit;
	}
	
	return $id;
}
//------------------------------------------------------------------------------------
function AddSettings($settingsData)
{
	global $dblink;
	
	$query = "UPDATE settings SET renametype=".$settingsData['renametype'].
								", namecleanup=".$settingsData['namecleanup'].
								", cleanupfilter='".$settingsData['cleanupfilter'].
								"', dot=".$settingsData['dot'].
								", under=".$settingsData['under'].
								", doubleb=".$settingsData['doubleb'].
								", flatfolders=".$settingsData['flatfolders'].
								", logs=".$settingsData['logs'].
								", displaymoved=".$settingsData['displaymoved'].
								", cpfolder=".$settingsData['cpfolder'].
								", shortfilenames=".$settingsData['shortfilenames'].
								", runtime=".$settingsData['runtime'].
								", ifolders='".$settingsData['ifolders'].
								"', download=0;";
													  
	try
	{
		$sdt = $dblink->query($query);	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		LogError(print_r($ex, true));
		//header("Location: error.php");	
		Exit;
	}
}
//------------------------------------------------------------------------------------
function LastProfileID($path) // might be abel to delete if above works!
{
	global $dblink;
	$id = 1;
		
	try
	{
		$query = "SELECT id FROM profiles WHERE path='".SafeSQLValue($path)."' ORDER BY id DESC LIMIT 1;";
		//echo $query."\n";
		$sdt3 = $dblink->query($query);
		
		while($row = $sdt3->fetch(PDO::FETCH_ASSOC))
			$id = $row["id"];
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		//header("Location: error.php");	
		Exit;
	}	
	
	return $id;
}
//------------------------------------------------------------------------------------
function StatUpdate($line)
{
	$handle2 = fopen("backup/backup.data", "a+");
	fputs($handle2, $line);
	fclose($handle2);
}
//------------------------------------------------------------------------------------
?>