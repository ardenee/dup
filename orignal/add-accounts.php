<?php
include("db.php");
$uname = "";
$pass  = "";

if(isset($argv[1])) // running a scan! locally, can delete in build
	$uname = $argv[1];
else if(isset($_GET['uname']))
	$uname = $_GET['uname'];
else
{
	//header("Location: index.php");
	echo "No username - $uname";
	Exit;
}

if(isset($argv[2])) // running a scan! locally, can delete in build
	$pass = $argv[2];
else if(isset($_GET['pass']))
	$pass = $_GET['pass'];
else
{
	//header("Location: index.php");
	echo "No Password - $pass";
	Exit;
}

$uname = strtolower($uname);
$pass = md5("sault**".$pass);

$query = "INSERT IGNORE INTO accounts (name, password) VALUES('$uname', '$pass');";
$dblink->query($query);		

header("Location: accounts.php");
?>