<?php
include("db.php");
LogStr("Status Update", 1, __FILE__, __LINE__, __FUNCTION__);
$stat  = array();	
$num   = 0;

try
{
	$query = "SELECT profile, value, data FROM status;";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))
	{
		$stat[$num]['profile'] = $row['profile'];
		$stat[$num]['value']     = $row['value'];
		$stat[$num]['data']      = $row['data'];
		$num++;
	}
	
	$sdt->closeCursor();
}
catch(PDOException $ex) {
	LogError(print_r($ex, true));
	Exit;
}

$tmp2 = str_replace('\/','/',json_encode($stat));
$tmp2 = str_replace(':',': ',$tmp2);
//$tmp2 = str_replace(',',', ',$tmp2);

echo $tmp2;
?>