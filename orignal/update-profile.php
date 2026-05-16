<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Save Profile Settings '$profile'", 1, __FILE__, __LINE__, __FUNCTION__);

$path      = "/";
$mpath     = "/";
$crc32     = 0;
$filetypes = "avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv,wma,mpc,mp3,flac,wav,ogg,exe,msi,pdf,iso,rar,gz,zip,tgz,bmp,png,gif,jpg,jpeg,pdf,rtf,doc,docx,html,txt,db,log,nds";
$msize     = 0;
$type      = 1;

$path      = rtrim($_POST['main_dir'], '/');
$mpath     = rtrim($_POST['move_dir'], '/');
$crc32     = $_POST['crc32'];
$filetypes = $_POST['filetypes'];
$msize     = $_POST['msize'];
$type      = $_POST['type'];

if($msize<0)
	$msize = 0;
	
p($_POST);

/*
Array
(
    [profile] => 3
    [main_dir] => /volume1/Films
    [move_dir] => /volume1/Data/Moved
    [crc32] => 1
    [filetypes] => avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv
    [msize] => 0
    [type] => 4
    [resetruns] => on
    [resetscan] => on
)
*/

if(isset($_POST['resetruns']))
{
	$query = "UPDATE profiles SET runs=0 WHERE id=$profile;";
	
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
}
	
if(isset($_POST['resetscan']))
{
	$query = "UPDATE profiles SET scan=0 WHERE id=$profile;";
			
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
	
	$query = "UPDATE status SET data='Complete', value='Idel' WHERE profile=$profile;";
			
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
	
	// force a delete profile dir listing file
	@unlink($profile.".txt");
}

if(is_dir($path) and is_dir($mpath))
{
	$query = "UPDATE profiles SET msize=$msize, path='".SafeSQLValue($path)."', mpath='".SafeSQLValue($mpath)."', crc32=$crc32, filetypes='$filetypes', type=$type WHERE id=$profile;";
	    
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
}
?>