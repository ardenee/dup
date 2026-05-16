<?php
setcookie("uname",    "blaa", time()-3600, "/", $_SERVER['SERVER_NAME']);
setcookie("password", "blaa", time()-3600, "/", $_SERVER['SERVER_NAME']);

header('Location: accounts-login.php'); 
?>