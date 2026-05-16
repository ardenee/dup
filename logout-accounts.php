<?php

$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');

$cookieOptions = array(
    "expires" => time() - 3600,
    "path" => "/",
    "secure" => $secureCookie,
    "httponly" => true,
    "samesite" => "Lax"
);

setcookie("uname", "", $cookieOptions);
setcookie("password", "", $cookieOptions);

header("Location: accounts-login.php");
exit;

?>