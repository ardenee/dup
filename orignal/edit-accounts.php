<?php
include("dup.php");
$aid = 1;

if(isset($argv[1])) // running a scan! locally, can delete in build
	$aid = $argv[1];
else if(isset($_GET['id']))
	$aid = $_GET['id'];
else
{
	echo "No id - $aid";
	Exit;
}

if(isset($argv[2])) // running a scan! locally, can delete in build
	$uname = $argv[2];
else if(isset($_GET['uname']))
	$uname = $_GET['uname'];
else
{
	LogError("No username - $uname");
	header("Location: error.php");
	Exit;
}

if(isset($argv[3])) // running a scan! locally, can delete in build
	$pass = $argv[3];
else if(isset($_GET['pass']))
	$pass = $_GET['pass'];
else
{
	LogError("No password - $pass");
	header("Location: error.php");
	Exit;
}

if($aid==1) // cant change admin account username
	$uname="admin";
	
	
if($aid==1 and $level!=1)
{
	LogError("Only an Admin account can edit the Admin account!");
	header("Location: error.php");
	Exit;
}

$uname = strtolower($uname);
$pass  = md5("sault**".$pass);
LogStr("Edit Accounts '$aid'", 1, __FILE__, __LINE__, __FUNCTION__);

try
{
	$query = "UPDATE accounts SET name='$uname', password='$pass' WHERE id=$aid;";
	LogStr($query, 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
}
catch(PDOException $ex) {
	//LogError(print_r($ex, true));
	//LogError("logs/last_error.txt", print_r($ex, true));
	LogError(print_r($ex, true));
	header("Location: error.php");	
	Exit;
}	

header('Location: accounts.php'); 
?>