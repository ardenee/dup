<?php
require("dup.php");
$dup = new dup($profile);
LogStr("Get Message", 1, __FILE__, __LINE__, __FUNCTION__);

$query = "SELECT * FROM msg LIMIT 1;";		
		
try
{
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
		
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		echo $row['message'];
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}

$query = "UPDATE msg SET message='';";		
		
try
{
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
}
?>