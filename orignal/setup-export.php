<?php
include("dup.php");
@unlink("backup/export.data");

$Profiles     = GetProfiles();
$Settings     = GetSettings();
$profilecount = count($Profiles);

foreach($Profiles as $key => $value)
	GetFiles($value, $key);

StatUpdate("Export Finnished\n");

//delete old tables
if($dbfile==0)
{
	try{$sdt = $dblink->query("DROP DATABASE dup;");}
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

shell_exec("php -q index.php"); // creates new tables, wait till finnished loading before trying import
shell_exec("php -q import.php >/dev/null 2>/dev/null &");// imports old data
//------------------------------------------------------------------------------------
function GetProfiles()
{
	global $dblink;
	$value = array();
	
	try
	{
		$query = "SELECT * FROM profiles ORDER BY id;";
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$id         = $row["id"];
			$value[$id] = $row; 
		}
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
			
	return $value;	
}
//------------------------------------------------------------------------------------
function GetSettings()
{
	global $dblink;
	$value = array();
		
	try
	{
		$query = "SELECT * FROM settings LIMIT 1;";
		$sdt   = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value = $row; 
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $value;
}
//------------------------------------------------------------------------------------
function GetFiles($Profile, $key)
{
	global $dblink;
	global $Settings;
	global $profilecount;
	$filenum = 1;	
	$id      = $Profile['id'];	
	
	try
	{
		$query = "SELECT count(id) AS number FROM files WHERE profile=$id;";
		$sdt   = $dblink->query($query);
		$ct    = $sdt->fetch(PDO::FETCH_ASSOC);			
		$query = "SELECT * FROM files WHERE profile=$id;";
		$sdt   = $dblink->query($query);		
		@unlink("backup/$id.txt");
		$handle = fopen("backup/$id.txt", "w");		
		fputs($handle, json_encode($Settings)."\n");
		fputs($handle, json_encode($Profile)."\n");

		if($handle) 
		{
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				fputs($handle, json_encode($row)."\n");
				
				if($filenum%200==0)
					StatUpdate("Exporting Profile: $key of $profilecount - Record ".number_format($filenum)." of ".number_format($ct['number'])."\n");
				
				$filenum++;
			}
		}
		
		fclose($handle);		
		$sdt->closeCursor();
		StatUpdate("Exporting Profile: $key of $profilecount - Complete ($filenum records exported)\n\n");
	}
	catch(PDOException $ex) {	
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
}
//------------------------------------------------------------------------------------
function StatUpdate($line)
{
	$handle2 = fopen("backup/backup.data", "a+");
	fputs($handle2, $line);
	fclose($handle2);
}
//------------------------------------------------------------------------------------
?>