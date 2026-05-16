<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Clear Logs", 1, __FILE__, __LINE__, __FUNCTION__);

$level = 1;
$show  = 100;

if(isset($_GET['level']))
	$level = $_GET['level'];

if(isset($_GET['show']))
	$show = $_GET['show'];

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
	header("Location: error.php");	
	Exit;
}

header('Location: logs.php?level='.$level.'&show='.$show); 
?>