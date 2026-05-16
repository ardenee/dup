<?php

$uname = "";
$pass = "";

if(isset($_POST['user']))
    $uname = trim((string)$_POST['user']);

if(isset($_POST['password']))
    $pass = (string)$_POST['password'];

$uname = str_replace(array("\0", "\r", "\n"), "", $uname);

if(strlen($uname) > 64)
    $uname = substr($uname, 0, 64);

if(strlen($pass) > 256)
    $pass = substr($pass, 0, 256);

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

$cookieOptions = array(
    "expires" => time() + 10800,
    "path" => "/",
    "secure" => $secureCookie,
    "httponly" => true,
    "samesite" => "Lax"
);

setcookie("uname", $uname, $cookieOptions);
setcookie("password", md5("sault**" . $pass), $cookieOptions);

header("Location: index.php");
exit;

?>