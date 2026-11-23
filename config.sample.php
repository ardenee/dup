<?php

// Reference only. New installs write config.local.php in the private DUP_DATA_DIR.
// On Synology the private directory is /var/packages/dup/var.
// Never place a populated credentials file in a public web directory.

$dbuser = "root";
$dbpass = "";
$dbdir = __DIR__;
$dbfile = 0;
$dbname = "dup";
$dbhost = "127.0.0.1";
$dbport = "3306";

?>
