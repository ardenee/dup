<?php
include("dup.php");
$dup = new dup($profile);
LogStr("Delete Profile '".$dup->Profile['path']."'", 1, __FILE__, __LINE__, __FUNCTION__);
$query = "DELETE FROM profiles WHERE id=$profile;";

try
{
	LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}

$query = "DELETE FROM files WHERE profile=$profile;";

try
{
	LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}	

$query = "DELETE FROM status WHERE profile=$profile;";

try
{
	LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}	

$query = "DELETE FROM viewpages WHERE profile=$profile;";

try
{
	LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}	
 
header('Location: index.php'); 
?>