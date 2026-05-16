<?php
include("dup.php");
$aid = 1;
$aid = $_GET['id'];

LogStr("Delete Accounts '$aid'", 1, __FILE__, __LINE__, __FUNCTION__);

if($aid != 1)// cannot delete the admin account!!
{	
	try
	{
		$query = "DELETE FROM accounts WHERE id=$aid;";
		LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
}

header('Location: accounts.php'); 
?>