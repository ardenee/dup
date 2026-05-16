<?php
$uname = "";
$pass  = "";

if(isset($_POST['user']))
	$uname = $_POST['user'];
	
if(isset($_POST['password']))
	$pass = $_POST['password'];
	
//	echo "<pre>";
//print_r($_POST)."<BR>";
//echo md5("sault**".$pass);

setcookie("uname",    $uname,                time()+10800, "/", $_SERVER['SERVER_NAME']);
setcookie("password", md5("sault**".$pass),  time()+10800, "/", $_SERVER['SERVER_NAME']);

header('Location: index.php'); 
?>