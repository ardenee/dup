<?php
include("dup.php");
$dup      = new dup($profile);
LogStr("Add New Profile", 1, __FILE__, __LINE__, __FUNCTION__);

$path      = $_POST['main_dir'];
$mpath     = $_POST['move_dir'];
$crc32     = $_POST['crc32'];
$filetypes = $_POST['filetypes'];
$msize     = $_POST['msize'];
$type      = $_POST['type'];

if(is_dir($path) and is_dir($mpath))
{
	$query = "INSERT $sqliteOr IGNORE INTO profiles (msize, path, mpath, crc32,  filetypes, scan, type, files, dups, runs) VALUES($msize, '".SafeSQLValue($path)."', '".SafeSQLValue($mpath)."', $crc32, '".SafeSQLValue($filetypes)."', 0, $type, 0, 0, 0)";
	
	try	
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		//echo $query."<HR>";
		$sdt = $dblink->query($query);	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}

	LogStr("LASTID: $id", 3, __FILE__, __LINE__, __FUNCTION__);
	$id = LastProfileID($path);
	
	$query = "INSERT $sqliteOr IGNORE INTO status (profile, value, data) VALUES($id, 'Idel', 'Complete');";	

	try	
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	

	$query = "INSERT $sqliteOr IGNORE INTO viewpages (data, profile) VALUES('', $id);";
	
	try	
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
		
	header('Location: index.php');
	Exit; 	
}
else
{
	if(!is_dir($path))
		LogStr("Profile path does not exists: $path", 2, __FILE__, __LINE__, __FUNCTION__);
		
	if(!is_dir($mpath))
		LogStr("Profile move path does not exists: $mpath", 2, __FILE__, __LINE__, __FUNCTION__);
}

header('Location: profile-add.php'); // remove and add field checking instead!!
//-------------------------------------------------------------------
function LastProfileID($path) // might be abel to delete if above works!
{
	global $dblink;
	$id = 1;	
	LogStr("LastProfileID() Gets profile id of just added profiled via it's path via pram", 2, __FILE__, __LINE__, __FUNCTION__);
	$query = "SELECT id FROM profiles WHERE path='".SafeSQLValue($path)."' ORDER BY id DESC LIMIT 1;";
		
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt3 = $dblink->query($query);
		
		while($row = $sdt3->fetch(PDO::FETCH_ASSOC))
			$id = $row["id"];
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $id;
}
//-------------------------------------------------------------------
?>