<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Settings - Update", 1, __FILE__, __LINE__, __FUNCTION__);	

if(isset($_POST["renametype"]))
	$dup->Settings['renametype'] = $_POST["renametype"];
	
if(isset($_POST["cleanupfilter"]))
	$dup->Settings['cleanupfilter'] = CleanFilters($_POST["cleanupfilter"]); // htmlsafecharacters() and htmlspecialchars_decode()
		
if(isset($_POST["dot"]))
	$dup->Settings['dot'] = $_POST["dot"];
	
if(isset($_POST["under"]))
	$dup->Settings['under'] = $_POST["under"];

if(isset($_POST["doubleb"]))
	$dup->Settings['doubleb'] = $_POST["doubleb"];
	
if(isset($_POST["shortfilenames"]))
	$dup->Settings['shortfilenames'] = $_POST["shortfilenames"];
	
if(isset($_POST["runtime"]))
	$dup->Settings['runtime'] = $_POST["runtime"];
	
if(isset($_POST["ifolders"]))
	$dup->Settings['ifolders'] = CleanFilters($_POST["ifolders"]); // htmlsafecharacters() and htmlspecialchars_decode()
	
$dup->Settings['namecleanup']   = ($_POST["namecleanup"]=="on")?1:0;	
$dup->Settings['flatfolders']   = ($_POST["flatfolders"]=="on")?1:0;
$dup->Settings['logs']          = ($_POST["logs"]=="on")?1:0;
$dup->Settings['displaymoved']  = ($_POST["displaymoved"]=="on")?1:0;
$dup->Settings['cpfolder']      = ($_POST["cpfolder"]=="on")?1:0;
$dup->Settings['autoupdate']    = ($_POST["autoupdate"]=="on")?1:0;
$dup->Settings['download']      = ($_POST["download"]=="on")?1:0;


/*
if(isset($_POST['resetlogs']))
{
	$query = "TRUNCATE TABLE logs;";

	if($dbfile==1)
		$query = "DROP TABLE logs;";

	try
	{
		$sdt = $dblink->query($query);
		
		if($dbfile==1)
		{
			$query = "DELETE FROM SQLITE_SEQUENCE WHERE name='logs';";
			$sdt = $dblink->query($query);
			
			$query = "CREATE TABLE `logs` (`id` INTEGER PRIMARY KEY AUTOINCREMENT,`stamp` DATETIME DEFAULT CURRENT_TIMESTAMP ,`str` TEXT ,`type` INT DEFAULT 1 ,`page` VARCHAR(255) ,`line` INT DEFAULT 0 ,`function` TEXT );";
			$sdt = $dblink->query($query);
		}
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}
}
*/
$query = "UPDATE settings SET renametype=".$dup->Settings['renametype'].
							", namecleanup=".$dup->Settings['namecleanup'].
							", cleanupfilter='".$dup->Settings['cleanupfilter'].
							"', dot=".$dup->Settings['dot'].
							", under=".$dup->Settings['under'].
							", doubleb=".$dup->Settings['doubleb'].
							", flatfolders=".$dup->Settings['flatfolders'].
							", logs=".$dup->Settings['logs'].
							", displaymoved=".$dup->Settings['displaymoved'].
							", cpfolder=".$dup->Settings['cpfolder'].
							", shortfilenames=".$dup->Settings['shortfilenames'].
							", runtime=".$dup->Settings['runtime'].
							", ifolders='".$dup->Settings['ifolders'].
							"', download=".$dup->Settings['download'].";";
												  
try
{
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	if($dbfile==0)
		$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"MySQL database updated\", \"type\": \"notice\", \"title\": \"Settings Saved!\"}}';";	
	else
		$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"sqlite database updated\", \"type\": \"notice\", \"title\": \"Settings Saved!\"}}';";		
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	
	if($dbfile==0)
		$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to MySQL, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";	
	else
		$query = "UPDATE msg SET message='{\"notification-1\": {\"message\": \"Error Saving to sqlite, check logs!\", \"type\": \"error\", \"title\": \"Settings NOT Saved!\"}}';";		
}	

try
{		
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}
//--------------------------------------------------------------------------------------------------------	 
function CleanFilters($filters)
{
	LogStr("CleanFilters() Clean up filters via passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$tmp  = explode(",", $filters);	
	$tmp  = array_unique($tmp);
	$tmp2 = array(",");	
	
	foreach($tmp as $key => $val)
	{
		if(in_array($val, $tmp2) or strlen($val)==0)
			unset($tmp[$key]);
	}

	return implode(",", $tmp);
}
//--------------------------------------------------------------------------------------------------------
?>