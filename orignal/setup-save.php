<?php
$password  = "";
$db_dir    = "";
$olddb_dir = "";

if(isset($_POST["password"]))
	$password = $_POST["password"];	
	
if(isset($_POST["db_dir"]))
	$db_dir = $_POST["db_dir"];	
	
if(strlen($password)>0 or strlen($db_dir)>0 or isset($dbfile))
{
	$dbf = file_get_contents("db.php");
	$da  = explode("\n", $dbf);		
	$pwd = false;
	$db  = false;
		
	foreach($da as $key=>$val)
	{
		if(strpos($val, "dbpass = \"")>0 and strlen($password)>0 and !$pwd)
		{
			$da[$key] = "$"."dbpass = \"NeedPASSWORD\";";// remove old password string
			$pwd = true;
		}
		
		if(strpos($val, "dbdir = \"")>0 and strlen($db_dir)>0 and !$db)
		{
			if (preg_match('/"([^"]+)"/', $val, $m)) // get old location
				$olddb_dir = $m[1];   

			$da[$key] = "$"."dbdir = \"NeedDBLoc\";";// remove old password string
			$db = true;
		}
			
		if($pwd and $db)
			break;
	}
		
	$dbf = implode($da, "\n");
	$dbf = str_replace("NeedPASSWORD", $password, $dbf); // add new password string
	$dbf = str_replace("NeedDBLoc",    $db_dir,   $dbf); // add new password string
	
	if(isset($_POST["filebd"]))
	{
		$dbf = str_replace("dbfile = 0;", "dbfile = 1;", $dbf); // add new password string			
		
		try	{
			if(is_file($olddb_dir."/dup.db") and ($olddb_dir."/dup.db" != $db_dir."/dup.db"))
				rename($olddb_dir."/dup.db", $db_dir."/dup.db");				
		}
		catch(Exception $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		} 
	}
	else
		$dbf = str_replace("dbfile = 1;", "dbfile = 0;", $dbf); // set to MySQL by default
	
	file_put_contents("db.php", $dbf);
	sleep (3); // time to save
}

//phpinfo(INFO_VARIABLES);

//--------------------------------------------------------------------------------------------------------
include("dup.php");

if(isset($_POST['upgrade']))
{
	try	{$sdt = $dblink->query("ALTER TABLE logs ADD line INT NOT NULL;");} // no need for this again!
	catch(PDOException $ex) {	
	}
	
	shell_exec("php -q export.php >/dev/null 2>/dev/null &");
	
	@header('Location: backup.php');	
	Exit;
}

if($dbfile==0)
{
	try{
		$sdt = $dblink->query("DROP DATABASE dup;");
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
}
else
{
	unlink($dbdir."/dup.db");
	copy("files/dup.db", $dbdir."/dup.db");
}

@header('Location: index.php');	
?>