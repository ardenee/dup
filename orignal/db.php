<?php
//iconv_set_encoding("internal_encoding", "UTF-8");
//iconv_set_encoding("input_encoding", "UTF-8");
//iconv_set_encoding("output_encoding", "UTF-8");
// /usr/syno/etc.defaults/rc.d/S97apache-user.sh restart 
ini_set("log_errors" , "1");
ini_set("error_log" , "logs/php_errors.log");
ini_set("display_errors" , "1");
//ini_set("max_input_vars" , "5000");

/*
if(@filesize("logs/log.txt")>5*1025*1024)
{
	$objDateTime = new DateTime('NOW');	
	rename("logs/log.txt", "logs/log.txt".$objDateTime->format('Y-m-d-H:i'));
}

$log = fopen("logs/log.txt", "a+");
*/

$dbpass   = "NeedPASSWORD";
$dbdir    = "/volume1/web/dup";
$dbfile   = 0;
$sqliteOr = "";
$action   = "";
$dbname   = "dup";
$dbhost   = "localhost";
$dbuser   = "root";
$profile  = 0;  //should be 0 ?
$start    = 0;

if(isset($_GET["profile"]))
	$profile = $_GET["profile"];
	
if(isset($_POST["profile"]))
	$profile = $_POST["profile"];

if(isset($argv[1])) // running a scan! locally, can delete in build
	$profile = $argv[1];
	
if(isset($_GET["action"]))
	$action = $_GET["action"];
	
if(isset($_POST["action"]))
	$action = $_POST["action"];
	
if(isset($argv[2])) // running a scan! locally, can delete in build
	$action = $argv[2];
	
if(isset($_POST["start"]))
	$start = $_POST["start"];
	
if(isset($_POST["dbfile"])) // running a scan! locally, can delete in build
	$dbfile = $_POST["dbfile"];
	
try {
	if($dbfile==0)
	{
		$dblink = new PDO("mysql:host=".$dbhost.";charset=utf8", $dbuser, $dbpass); # MySQL Database
		
		if(!$dblink)
		{
			header("Location: setup.php");
			Exit;
		}
		
		$dblink->query("CREATE DATABASE IF NOT EXISTS dup;");
		$dblink->query("USE dup;");
	}
	else
	{	
		$sqliteOr = "OR";

		$dblink   = new PDO("sqlite:".$dbdir."/dup.db");  
		$dblink->exec("pragma synchronous  = off;"); // speeds up!
		//$dblink->exec("pragma journal_mode = WAL;"); // speeds up!		
	}

	$dblink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$dblink->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
} 
catch(PDOException $ex) {
	if(!$dblink)
	{
		header("Location: setup.php");
		Exit;
	}
}

try	{$sdt = $dblink->query("ALTER TABLE logs ADD line INT NOT NULL;");}
catch(PDOException $ex) {} // required for db update, can be ignored afterwards.	

$runtime = GetRuntime();
$level = -1;
$level = CheckLogin();

//ini_set("max_execution_time", $runtime); // 1 houre limit for scaning large folders!!
//ini_set("max_execution_time", 3600); // 1 houre limit for scaning large folders!!
//-------------------------------------------------------------------------------
function CheckLogin()
{
	global $dblink;
	$user = $_COOKIE["uname"];
	$pass = $_COOKIE["password"];
	
	//p($_COOKIE);
	
	//p($query);
	
	if(!isset($_SERVER['REMOTE_ADDR']) or strlen($_SERVER['REMOTE_ADDR'])==0)
		return 0; // run omfr system, not client, let ir run	
	
	try
	{
		$query = "SELECT level FROM accounts WHERE name='$user' AND password='$pass';";
		$sdt = $dblink->query($query);
		
		//p($query);

		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			return $row['level'];
			Exit;
		}
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {	
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
	// set header here?
	header("Location: accounts-login.php");	
	//return $result;
	Exit;
}
//-------------------------------------------------------------------------------
function GetRuntime()
{
	global $dblink;
	LogStr("GetRuntime() Get script default run time", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = 3600;//1 hour
	$query = "SELECT runtime FROM settings;";
	
	try
	{
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);//will nver log no profile set yet!!
		$sdt = $dblink->query($query);

		if($std)
		{		
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$value = $row['runtime'];
				break;
			}
		}
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {	
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $value;
}
//-------------------------------------------------------------------------------
function ProcesslsStr($str) 
{	
	$str = trim($str);
	LogStr("ProcesslsStr() Process ls string via pram - $str", 2, __FILE__, __LINE__, __FUNCTION__);	
	$ret = "";	
	//-rwxrwxrwx    1 admin    users       333715 Sat Apr  2 12:06:16 2011 /volume1/photo/PHOTO/IMG0002/DC000207.jpg
	//lrwxrwxrwx    1 root     root            17 Mon Aug 19 13:03:42 2013 zy-b5tlt_Copy2.sub -> zy-b5tlt_Copy.sub 
	
	if(strlen($str)>0) // blank line check
	{	
		if(strripos($str, ':')==(strlen(trim($str))-1))
			return "Folder\tFolder\t".substr(trim($str), 0, -1)."\tFolder"; // return folder without the : on end
		
		$n     = strpos($str, ':'); // common in all ls outputs with -l switch
		$str1  = trim(substr($str, 0, $n));
		$str2  = trim(substr($str, $n)); 
		$tmp   = $str1;
		$catch = 0;
		
		while($catch++<4)
			$tmp = trim(substr($tmp, 0, (strripos($tmp, ' '))));	
		
		$size     = trim(substr($tmp, strripos($tmp, ' ')));		
		$datetime = trim(substr($str1, strlen($tmp)).substr($str2, 0, 11)); 
		$datetime = trim(substr($datetime, strpos($datetime, ' ')));// remove day in date time
		$filename = trim(substr($str2, 11)); 
		$ext      = "";
		$link     = "";
		
		if(strripos($filename, '.')>0)	
			$ext = substr($filename, strripos($filename, '.')+1);	
			
		if(strripos($filename, ' -> ')>0)	// links !!	
		{		
			$link     = $filename;
			$filename = trim(substr($filename, 0, strripos($filename, '->')));	// filename
			$link     = trim(substr($link, (strripos($link, '->')+3), strlen($link))); // link
		}			
			
		$ret = $size."\t".strtotime($datetime)."\t".$filename."\t".$ext."\t".$link;		
	}
	
	return $ret;  
}
//-------------------------------------------------------------------------------
function p($a)
{
	echo "<pre>\n";
	print_r($a);
	echo "</pre>\n";
}
//-------------------------------------------------------------------------------
function LogStr($str, $type, $page, $line, $func)
{
	global $dup;
	global $profile;
	global $dblink;
	
	$str = SafeSQLValue($str);		
	$page = pathinfo($page, PATHINFO_BASENAME); 
	
	if($dup->Settings['logs']==1 or $type==1 or type==4 or $profile==0)// log defaults always
	{ 
		try	{		
			$query = "INSERT INTO logs(str, type, page, line, function) VALUES(:str, :type, :page, :line, :func);";
			$sdt   = $dblink->prepare($query);
			$sdt->bindParam(':str',  $str,  SQLITE3_TEXT); 
			$sdt->bindParam(':type', $type, SQLITE3_INTEGER);
			$sdt->bindParam(':page', $page, SQLITE3_TEXT);
			$sdt->bindParam(':line', $line, SQLITE3_INTEGER);
			$sdt->bindParam(':func', $func, SQLITE3_TEXT);
			$sdt->execute();
			$sdt->closeCursor();
		}
		catch(PDOException $ex) {		
			if(strstr($ex, "SQLSTATE[42S02]") == FALSE)
			{
				file_put_contents("logs/last_error.txt", print_r($ex, true), FILE_APPEND);// append this error instead of overwrite!
				header("Location: error.php");	
				Exit;
			}
			else
				SetupDB();
		}
	}
}
//-------------------------------------------------------------------------------
function SetupDB()
{
	global $dblink;
	global $dbfile;
	
	if($dbfile==0)
		$SQLData = file_get_contents("files/dup.sql");
	else
		$SQLData = file_get_contents("files/dup.sqlite");
	
	$SQLArrayData = explode("--------------------------------------------------------------------------", $SQLData);
	
	foreach($SQLArrayData as $key => $value)
	{
		try	{		
			$sdt = $dblink->query($value);			
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}
	}
	
	$sdt->closeCursor();
}
//-------------------------------------------------------------------------------
function GetStat($Profile)
{
	global $dblink;
	LogStr("GetStat() Get profile status via pram - $Profile", 2, __FILE__, __LINE__, __FUNCTION__);
	$stat = array();	
	
	try
	{
		$query = "SELECT value, data FROM status WHERE profile=$Profile;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$stat = $row; 
			
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}
	
	return $stat;
} 
//-------------------------------------------------------------------------------
function IsMovedFile($file, $foldersarray)
{
	LogStr("IsMovedFile() Identify if is a moved file via prams - $file", 2, __FILE__, __LINE__, __FUNCTION__);

	foreach($foldersarray as $k => $v)
	{
		if(strpos($file, $v)===0)
			return true;
	}	
	
	return false;
}
//-------------------------------------------------------------------------------
function GetStats()
{
	global $dblink;
	LogStr("GetStats() Gets Profile Status", 2, __FILE__, __LINE__, __FUNCTION__);
	$stats = array();
	$stats['profiles'] = 0;
	$stats['dups']     = 0;
	$stats['files']    = 0;
	
	try
	{
		$query = "SELECT dups, files FROM profiles;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
			
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$stats['dups']  += $row["dups"];
			$stats['files'] += $row["files"];
			$stats['profiles']++;
		}	
			
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $stats;
} 
//-------------------------------------------------------------------------------
function GetProfileIDs($by)
{
	global $dblink;
	LogStr("GetProfiles() Gets profile ordered by passed pram - $by", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = array();	
	
	try
	{
		$query = "SELECT id FROM profiles ORDER BY $by;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			$value[] = $row['id']; 
			
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
		
	return $value;
}
//-------------------------------------------------------------------------------
function GetExt($ID)
{
	global $dblink;
	LogStr("GetExts() Gets exts", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = array();
		
	try
	{
		$query = "SELECT exts FROM profiletypes WHERE id=$ID;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$id         = $row["id"];
			$value[$id] = $row["exts"];
		}
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $value;
}
//-------------------------------------------------------------------------------
function GetExts()
{
	global $dblink;
	LogStr("GetExts() Gets exts", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = array();
	
	try
	{
		$query = "SELECT id, exts FROM profiletypes;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$id         = $row["id"];
			$value[$id] = $row["exts"];
		}
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}	
	
	return $value;
}
//-------------------------------------------------------------------------------
function GetExtSel()
{
	global $dblink;
	LogStr("GetExtSel() Gets profile types exts ordered by name", 2, __FILE__, __LINE__, __FUNCTION__);
	$value = array();
		
	try
	{
		$query = "SELECT id, name FROM profiletypes ORDER BY name;";
		LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			$id         = $row["id"];
			$value[$id] = $row["name"];
		}
		
		$sdt->closeCursor();
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
		header("Location: error.php");	
		Exit;
	}		
	
	return $value;
}
//-------------------------------------------------------------------------------
function PageFooter()
{
	LogStr("PageFooter() HTML Header", 2, __FILE__, __LINE__, __FUNCTION__);
	
    $data=" <FOOTER>\n";
	$fdata = GetStats();   
    $data.="  <DIV class=\"container footer\"><B>".$fdata['profiles']." </B>Profiles | <B>".number_format($fdata['dups'])."</B> Duplicates | <B>".number_format($fdata['files'])."</B> Files</DIV><!-- /container footer -->\n";
    $data.=" </FOOTER>\n";
  
    return $data;
}
//-------------------------------------------------------------------------------
function PageHeader()
{
	LogStr("PageHeader() HTML Header", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$data="<HEADER>\n";
   $data.=" <DIV class=navbar>\n";
   $data.="  <DIV class=navbar-inner>\n";
   $data.="   <DIV class=container>\n";
   $data.="    <A class=\"btn btn-navbar\" data-target=\".nav-collapse\" data-toggle=\"collapse\">\n";
   $data.="     <SPAN class=icon-bar></SPAN>\n";
   $data.="     <SPAN class=icon-bar></SPAN>\n";
   $data.="     <SPAN class=icon-bar></SPAN>\n";
   $data.="    </A>\n";
   $data.="    <DIV class=nav-collapse>\n";
   $data.="     <UL class=nav>\n";	   
   $data.="      <LI class=divider-vertical></LI>\n";	
   
   $data.="      <LI id=\"NAVsystem\" class=dropdown><A class=dropdown-toggle  onclick=\"return false;\" href=\"#\" data-toggle=\"dropdown\"><IMG alt=\"\" src=\"images/system18.png\" width=18 height=18> <B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   $data.="        <LI><A href=\"update-check.php\"><I class=icon-check></I> Force Version Check</A></LI>\n";
   $data.="        <LI class=divider></LI>\n";
   $data.="        <LI><A href=\"setup.php\"><I class=icon-off></I> Setup</A></LI>\n";
   $data.="        <LI class=divider></LI>\n";
   $data.="        <LI><A href=\"error.php\"><I class=icon-info-sign></I> Error Report</A></LI>\n";
   $data.="       </UL>\n";   
   $data.="      </LI>\n";
   
   $data.="      <LI class=divider-vertical></LI>\n";
   
   $data.="      <LI id=\"NAVhome\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"index.php\" data-toggle=\"dropdown\"> Home<B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   $data.="        <LI><A href=\"profile-add.php\"><I class=icon-plus-sign></I> Add Profile</A></LI>\n";  
   $data.="       </UL>\n";
   $data.="      </LI>\n";
   
   $data.="      <LI class=divider-vertical></LI>\n";
   
   $data.="      <LI id=\"NAVaccounts\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"accounts.php\" data-toggle=\"dropdown\">Accounts<B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   
	if($level<0)   
		$data.="	       <LI><A href=\"accounts-login.php\"><I class=icon-ok-circle></I> Login</A></LI>\n";
	else
		$data.="	       <LI><A href=\"logout-accounts.php\"><I class=icon-remove-circle></I> Logout</A></LI>\n";
   $data.="       </UL>\n";   
   $data.="      </LI>\n";


   $data.="      <LI class=divider-vertical></LI>\n";
   
   $data.="      <LI id=\"NAVconfig\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"config-general.php\" data-toggle=\"dropdown\">Config<B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   $data.="	       <LI><A href=\"config-help.php\"><I class=icon-question-sign></I> Help &amp; Info</A></LI>\n";
   $data.="	       <LI class=divider></LI>\n";
   $data.="	       <LI><A href=\"config-general.php\"><I class=icon-wrench></I> General</A></LI>\n";
   $data.="	       <LI><A href=\"config-profiletypes.php\"><I class=icon-cog></I> Profile Types</A></LI>\n";
   $data.="       </UL>\n";   
   $data.="      </LI>\n";
   
   $data.="	     <LI class=divider-vertical></LI>\n";
   $data.="      <LI id=\"NAVrename\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"renaming.php\" data-toggle=\"dropdown\"> Renaming</A></LI>\n";		
   $data.="      <LI class=divider-vertical></LI>\n";
   
   $data.="      <LI id=\"NAVsearch\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"search-general.php\" data-toggle=\"dropdown\">Search<B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   $data.="	       <LI><A href=\"search-general.php\"><I class=icon-search></I> General Search</A></LI>\n";
   $data.="	       <LI><A href=\"search-dup.php\"><I class=icon-search></I> Dup Search</A></LI>\n";
   $data.="       </UL>\n";
   $data.="      </LI>\n";   
   
   $data.="      <LI class=divider-vertical></LI>\n";
   $data.="       <LI id=\"NAVlogs\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"logs.php\" data-toggle=\"dropdown\"> Logs</A></LI>\n";
   $data.="      <LI class=divider-vertical></LI>\n";	 
   $data.="       <LI id=\"NAVfeedback\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"feedback.php\" data-toggle=\"dropdown\"> Feedback</A>\n";
   $data.="      <LI class=divider-vertical></LI>\n"; 
   
   $data.="     </UL>\n";
   $data.="     <UL class=\"nav pull-right\">\n";
   $data.="      <LI><A id=navDonate onclick=\"window.open(this.href); return false;\" href=\"https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=7RVSGJHGCYCUW\"><IMG alt=[donate] src=\"images/btn_donate_LG.gif\" width=92 height=26></A></LI>\n";
   $data.="     </UL>\n";
   $data.="    </DIV><!-- /nav-collapse -->\n";
   $data.="   </DIV><!-- /container -->\n";
   $data.="  </DIV><!-- /navbar-inner -->\n";
   $data.=" </DIV><!-- /navbar -->\n";
  
    return $data;
}
//-------------------------------------------------------------------------------
function SafeSQLValue($value)
{
	global $dbfile;
	
	if($dbfile==1)
		return str_replace("'", "''", $value);
	else
		return addslashes($value);	
}

//-------------------------------------------------------------------------------
function FromSafeSQLValue($value)
{
	global $dbfile;
	
	if($dbfile==1)
		return str_replace("''", "'", $value);
	else
		return stripslashes($value);	
}
//-------------------------------------------------------------------------------
function SafeJavaValue($value)
{
	return str_replace("'", "\'", $value);
}
//-------------------------------------------------------------------------------
//----------------------------------------------------------------------------
function FilterOn($name)
{
	LogStr("FilterOn() Changes [ ] to {{ }}in passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$nname = str_ireplace("[", "{dup{", $name);
	$nname = str_ireplace("]", "}dup}", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------
function FilterOff($name)
{
	LogStr("FilterOff() Changes {{ }}to [ ] in passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$nname = str_ireplace("{dup{", "[", $name);
	$nname = str_ireplace("}dup}", "]", $nname);
	
	return $nname;
}
//----------------------------------------------------------------------------
function StripFilePath($path)
{
	LogStr("StripPath() Gets filename only from passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$path_parts = pathinfo($path);
	
	return $path_parts['filename'];
}
//----------------------------------------------------------------------------
function FileNamePart($value, $part)
{
	$path_parts = pathinfo($value);
	$ret = "";
	/*
	/www/htdocs/inc
	lib.inc.php
	php
	lib.inc
	*/

	switch($part)
	{
		case 1  : $ret=$path_parts['dirname'];   break; // folder
		case 2  : $ret=$path_parts['basename'];  break; // full file name
		case 3  : $ret=$path_parts['extension']; break; // ext
		case 4  : $ret=$path_parts['filename'];  break; // file name no ext
		default : $ret=$path_parts['basename'];  break;
	}

	return $ret;	
}
//--------------------------------------------------------------------------------------------------------
function ScanRunning()
{
	global $dblink;
	global $profile;
		
	try	{
		$query = "SELECT scan FROM profiles where id=$profile;";
		LogStr("MySQL: $query", 2, __FILE__, __LINE__, __FUNCTION__);
		$sdt = $dblink->query($query);
		
		while($row = $sdt->fetch(PDO::FETCH_ASSOC))
		{
			if($row['scan']==1)
				return true;
		}	
	}
	catch(PDOException $ex) {
		LogError(print_r($ex, true));
	}  
	
	return false;
}
//--------------------------------------------------------------------------------------------------------
function renameFile($old, $new)
{
	LogStr("renameFile() renames file using shell_exec mv cmd based on passed pram", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$trgFile = escapeshellarg($new);
	$srcFile = escapeshellarg($old);

	$res = shell_exec("mv $srcFile $trgFile");
	echo "$res\nmv $srcFile $trgFile\n";	
	
	LogStr("shell_exec: mv $srcFile $trgFile", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(is_file($new))
		LogStr("New File is there", 2, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("New File is not there", 2, __FILE__, __LINE__, __FUNCTION__);
		
	if(!is_file($old))
		LogStr("Old File is not there", 2, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("Old File is there", 2, __FILE__, __LINE__, __FUNCTION__);
		
	if(is_file($new) and !is_file($old))
		return true;

	return false;
}
//----------------------------------------------------------------------------
function linkFile($file, $new)
{
	LogStr("linkFile() creates a link to a file va passed prams", 2, __FILE__, __LINE__, __FUNCTION__);
	
	$trgFile = escapeshellarg($new);
	$srcFile = escapeshellarg($file);
	
	//$res = symlink($srcFile, $trgFile);	
	
	$res = shell_exec("ln -s $srcFile $trgFile");
	echo "$res\nln -s $srcFile $trgFile\n";	
	
	if(!$res)
		LogError(print_r($ex, true));

	//$res = shell_exec("mv $srcFile $trgFile");
	//echo "$res\nmv $srcFile $trgFile\n";	
	
	LogStr("symlink: $srcFile $trgFile", 2, __FILE__, __LINE__, __FUNCTION__);
	
	if(is_link($new))
		LogStr("New Link is there", 2, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("New Link is not there", 2, __FILE__, __LINE__, __FUNCTION__);
		
	if(!is_file($file))
		LogStr("File is not there", 2, __FILE__, __LINE__, __FUNCTION__);
	else
		LogStr("File is there", 2, __FILE__, __LINE__, __FUNCTION__);
		
	if(is_link($new) and is_file($file))
		return true;

	return false;
}
//----------------------------------------------------------------------------
function LogError($Error)
{
	file_put_contents("logs/last_error.txt", print_r($Error, true));
	LogStr(json_encode($Error), 4, __FILE__, __LINE__, __FUNCTION__);	
}
//----------------------------------------------------------------------------
function GetTotalDupsByType($ID, $TYPE, $SKIP)
{
	global $dblink;
	global $dup;
	$mfs        = ((1024*1024)*$dup->Profile['msize']); // min file size
	$total = 0;
	$additional = "";
	
	if($TYPE=="crc32" and $SKIP==0)
		$additional = "AND crc32!='unknown'";
		
	$query = "SELECT id FROM files INNER JOIN (SELECT $TYPE, COUNT($TYPE) FROM files WHERE size>=$mfs $additional AND profile=$ID GROUP BY $TYPE HAVING COUNT($TYPE)>1) as files2 ON files.$TYPE = files2.$TYPE WHERE profile=$ID;";
	//echo $query."\n";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	$sdt->closeCursor();
		
	return $total;
}
//----------------------------------------------------------------------------
function GetTotalDupsGroupsByType($ID, $TYPE, $SKIP)
{
	global $dblink;
	global $dup;
	global $profile;
	$mfs        = ((1024*1024)*$dup->Profile['msize']); // min file size
	$total = 0;
	$additional = "";
	
	if($TYPE=="crc32" and $SKIP==0)
		$additional = "AND crc32!='unknown'";
		
	$query =   "SELECT $TYPE, COUNT($TYPE) FROM files WHERE profile=$profile $additional GROUP BY $TYPE HAVING COUNT($TYPE)>1"; 
	//$query = "SELECT id FROM files INNER JOIN (SELECT $TYPE, COUNT($TYPE) FROM files WHERE size>=$mfs $additional AND profile=$ID GROUP BY $TYPE HAVING COUNT($TYPE)>1) as files2 ON files.$TYPE = files2.$TYPE WHERE profile=$ID;";
	echo $query."\n";
	LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
	$sdt = $dblink->query($query);	
	
	while($row = $sdt->fetch(PDO::FETCH_ASSOC))	
		$total++;	
		
	$sdt->closeCursor();
		
	return $total;
}
?>
