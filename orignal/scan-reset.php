<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Reset '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
$query = "UPDATE status SET value='Idel', data='Complete' WHERE profile=$profile;";
 
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

header("Location: index.php");
?>