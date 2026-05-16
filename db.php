<?php
//iconv_set_encoding("internal_encoding", "UTF-8");
//iconv_set_encoding("input_encoding", "UTF-8");
//iconv_set_encoding("output_encoding", "UTF-8");
// /usr/syno/etc.defaults/rc.d/S97apache-user.sh restart 
ini_set("log_errors" , "1");
ini_set("error_log" , "logs/php_errors.log");
ini_set("display_errors" , "0");
//ini_set("max_input_vars" , "5000");

register_shutdown_function(function()
{
    $error = error_get_last();

    if(!$error)
        return;

    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

    if(!in_array($error["type"], $fatalTypes))
        return;

    $logDir = __DIR__ . "/logs";

    if(!is_dir($logDir))
        @mkdir($logDir, 0777, true);

    $requestUri = isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : "";
    $remoteAddr = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "CLI";

    $report = array(
        "time" => date("Y-m-d H:i:s"),
        "type" => "fatal_shutdown_error",
        "php_version" => PHP_VERSION,
        "request_uri" => $requestUri,
        "remote_addr" => $remoteAddr,
        "error" => $error,
        "get" => $_GET,
        "post" => $_POST
    );

    $text = print_r($report, true);

    file_put_contents($logDir . "/last_error.txt", $text);
    file_put_contents($logDir . "/error_history.log", $text . "\n\n------------------------------------------------------------\n\n", FILE_APPEND);
});

/*
if(@filesize("logs/log.txt")>5*1025*1024)
{
	$objDateTime = new DateTime('NOW');	
	rename("logs/log.txt", "logs/log.txt".$objDateTime->format('Y-m-d-H:i'));
}

$log = fopen("logs/log.txt", "a+");
*/

$dbuser   = "root";
$dbdir    = dirname(__FILE__);
$dbfile   = 0;
$sqliteOr = "";
$action   = "";
$dbname   = "dup";
$dbhost   = "127.0.0.1";
$dbport   = "3306";
$profile  = 0;  //should be 0 ?
$start    = 0;

$configLocal = __DIR__ . "/config.local.php";

if(is_file($configLocal))
    include($configLocal);

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
	
try
{
    $dblink = null;

    if($dbfile == 0)
    {
        $dsn = "mysql:host=".$dbhost.(isset($dbport) && strlen($dbport) > 0 ? ";port=".$dbport : "").";dbname=".$dbname.";charset=utf8";
        $dblink = new PDO($dsn, $dbuser, $dbpass);
    }
    else
    {
        $sqliteOr = "OR";
        $dblink = new PDO("sqlite:".$dbdir."/dup.db");
        $dblink->exec("pragma synchronous = off;");
    }

    $dblink->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $dblink->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
}
catch(PDOException $ex)
{
    if(!is_dir(__DIR__ . "/logs"))
        @mkdir(__DIR__ . "/logs", 0777, true);

    file_put_contents(__DIR__ . "/logs/db_connect_error.txt", date("Y-m-d H:i:s") . "\n" . print_r($ex, true) . "\n", FILE_APPEND);

    header("Location: setup.php");
    exit;
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

    if(!isset($_SERVER['REMOTE_ADDR']) || strlen($_SERVER['REMOTE_ADDR']) == 0)
        return 0;

    $user = isset($_COOKIE["uname"]) ? $_COOKIE["uname"] : "";
    $pass = isset($_COOKIE["password"]) ? $_COOKIE["password"] : "";

    if(strlen($user) == 0 || strlen($pass) == 0)
    {
        header("Location: accounts-login.php");
        exit;
    }

    try
    {
        $query = "SELECT level FROM accounts WHERE name = :user AND password = :pass LIMIT 1;";
        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":user", $user, PDO::PARAM_STR);
        $sdt->bindValue(":pass", $pass, PDO::PARAM_STR);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row)
            return intval($row["level"]);
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    header("Location: accounts-login.php");
    exit;
}
//-------------------------------------------------------------------------------
function GetRuntime()
{
    global $dblink;

    LogStr("GetRuntime() Get script default run time", 2, __FILE__, __LINE__, __FUNCTION__);

    $runtime = 30;

    try
    {
        $query = "SELECT runtime FROM settings LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);

        if($row && isset($row["runtime"]))
            $runtime = intval($row["runtime"]);

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        return $runtime;
    }

    LogStr("GetRuntime() runtime=" . $runtime, 2, __FILE__, __LINE__, __FUNCTION__);

    return $runtime;
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

function DupRelativeSourcePath($file)
{
    $file = str_replace("\\", "/", (string)$file);
    $root = str_replace("\\", "/", __DIR__);

    if($file == "")
        return "";

    if($file == $root)
        return basename($file);

    if(strpos($file, $root . "/") === 0)
        return substr($file, strlen($root) + 1);

    return $file;
}

function LogStr($str, $type = 0, $file = "", $line = "", $function = "")
{
    global $dup;
    global $dblink;

    $type = intval($type);
    $writeLog = true;

    if(isset($dup) && is_object($dup) && isset($dup->Settings) && is_array($dup->Settings))
    {
        $logsEnabled = isset($dup->Settings["logs"]) ? intval($dup->Settings["logs"]) : 1;

        // Always keep error/warn-level entries. The setting only controls normal/debug noise.
        if($logsEnabled == 0 && $type > 2)
            $writeLog = false;
    }

    if(!$writeLog)
        return;

    $logDir = __DIR__ . "/logs";

    if(!is_dir($logDir))
        @mkdir($logDir, 0777, true);

    $msg = date("Y-m-d H:i:s") . " | ";

    if($type == 1)
        $msg .= "ERROR | ";
    else if($type == 2)
        $msg .= "WARN  | ";
    else if($type == 3)
        $msg .= "DEBUG | ";
    else if($type == 4)
        $msg .= "SQL   | ";
    else
        $msg .= "INFO  | ";

	$displayFile = DupRelativeSourcePath($file);

	if($displayFile != "" || $line != "" || $function != "")
		$msg .= $displayFile . ":" . $line . " " . $function . " | ";

    $msg .= (string)$str . "\n";

    @file_put_contents($logDir . "/dup.log", $msg, FILE_APPEND);

    if(isset($dblink) && $dblink instanceof PDO)
    {
        try
        {
            $query = "INSERT INTO logs (stamp, type, str, function, page, line) VALUES (:stamp, :type, :str, :function, :page, :line);";

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":stamp", date("Y-m-d H:i:s"), PDO::PARAM_STR);
            $sdt->bindValue(":type", $type, PDO::PARAM_INT);
            $sdt->bindValue(":str", (string)$str, PDO::PARAM_STR);
            $sdt->bindValue(":function", (string)$function, PDO::PARAM_STR);
            $sdt->bindValue(":page", $displayFile, PDO::PARAM_STR);
            $sdt->bindValue(":line", intval($line), PDO::PARAM_INT);
            $sdt->execute();
            $sdt->closeCursor();
        }
        catch(PDOException $ex)
        {
            @file_put_contents($logDir . "/log_db_insert_error.txt", date("Y-m-d H:i:s") . "\n" . print_r($ex, true) . "\n", FILE_APPEND);
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

    $stat = array(
        "value" => "Idle",
        "data" => "Complete"
    );

    try
    {
        $query = "SELECT value, data FROM status WHERE profile=:profile LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", intval($Profile), PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);

        if($row)
            $stat = $row;

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
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
		LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);
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

    $allowedSorts = array(
        "id",
        "path",
        "mpath",
        "lastrun",
        "type",
        "files",
        "dups",
        "runs"
    );

    if(!in_array($by, $allowedSorts))
        $by = "id";

    LogStr("GetProfileIDs() Gets profile IDs ordered by passed pram - $by", 2, __FILE__, __LINE__, __FUNCTION__);

    $value = array();

    try
    {
        $query = "SELECT id FROM profiles ORDER BY " . $by . ";";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->query($query);

        while($row = $sdt->fetch(PDO::FETCH_ASSOC))
            $value[] = intval($row['id']);

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    return $value;
}
//-------------------------------------------------------------------------------
function GetExt($ID)
{
    global $dblink;

    LogStr("GetExt() Gets exts for profile type id - $ID", 2, __FILE__, __LINE__, __FUNCTION__);

    $exts = "";

    try
    {
        $query = "SELECT exts FROM profiletypes WHERE id=:id LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":id", intval($ID), PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);

        if($row && isset($row["exts"]))
            $exts = $row["exts"];

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    return $exts;
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
		LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);
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
		LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);
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
    global $level;

    if(!isset($level))
        $level = -1;

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
   $data.="         <LI><A href=\"config-general.php\"><I class=icon-wrench></I> General</A></LI>\n";
   $data.="         <LI><A href=\"config-profiletypes.php\"><I class=icon-cog></I> Profile Types</A></LI>\n";
   $data.="         <LI><A href=\"config-viewer.php\"><I class=icon-eye-open></I> File Viewer</A></LI>\n";
   $data.="       </UL>\n";   
   $data.="      </LI>\n";
   
   $data.="	     <LI class=divider-vertical></LI>\n";
   $data.="      <LI id=\"NAVrename\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"renaming.php\" data-toggle=\"dropdown\"> Renaming</A></LI>\n";		
   $data.="      <LI class=divider-vertical></LI>\n";
   
   $data.="      <LI id=\"NAVsearch\" class=dropdown><A class=\"dropdown-toggle disabled\" href=\"search-general.php\" data-toggle=\"dropdown\">Search<B class=caret></B></A>\n";
   $data.="       <UL class=dropdown-menu>\n";
   $data.="	       <LI><A href=\"search-general.php\"><I class=icon-search></I> General Search</A></LI>\n";
   $data.="	       <LI><A href=\"search-dup.php\"><I class=icon-search></I> Dup Search</A></LI>\n";
   $data.="        <LI><A href=\"search-moved.php\"><I class=icon-search></I> Moved Files</A></LI>\n";
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
    return str_replace("'", "''", (string)$value);
}
//-------------------------------------------------------------------------------
function FromSafeSQLValue($value)
{
    return str_replace("''", "'", (string)$value);
}
//-------------------------------------------------------------------------------
function SafeJavaValue($value)
{
    return str_replace(array("\\", "'", "\r", "\n"), array("\\\\", "\\'", "", ""), (string)$value);
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

    $path_parts = pathinfo((string)$path);

    if(isset($path_parts['filename']))
        return $path_parts['filename'];

    if(isset($path_parts['basename']))
        return $path_parts['basename'];

    return basename((string)$path);
}
//----------------------------------------------------------------------------
function FileNamePart($value, $part)
{
    $path_parts = pathinfo($value);

    $dirname = isset($path_parts['dirname']) ? $path_parts['dirname'] : "";
    $basename = isset($path_parts['basename']) ? $path_parts['basename'] : basename($value);
    $extension = isset($path_parts['extension']) ? $path_parts['extension'] : "";
    $filename = isset($path_parts['filename']) ? $path_parts['filename'] : $basename;

    switch($part)
    {
        case 1:
            return $dirname;

        case 2:
            return $basename;

        case 3:
            return $extension;

        case 4:
            return $filename;

        default:
            return $basename;
    }
}
//--------------------------------------------------------------------------------------------------------
function ScanRunning()
{
    global $dblink;
    global $profile;

    $profileId = intval($profile);

    if($profileId <= 0)
        return false;

    try
    {
        $query = "SELECT scan FROM profiles WHERE id=:profile LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 2, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row && isset($row["scan"]) && intval($row["scan"]) == 1)
            return true;
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }

    return false;
}
//--------------------------------------------------------------------------------------------------------
function renameFile($old, $new)
{
    LogStr("renameFile() renames file using PHP rename()", 2, __FILE__, __LINE__, __FUNCTION__);

    $old = trim($old);
    $new = trim($new);

    if($old == "" || $new == "")
    {
        LogError("renameFile failed: empty source or target. old=" . $old . " new=" . $new);
        return false;
    }

    if(!is_file($old) && !is_link($old))
    {
        LogError("renameFile failed: source missing: " . $old);
        return false;
    }

    $targetDir = dirname($new);

    if($targetDir == "" || $targetDir == ".")
    {
        LogError("renameFile failed: invalid target directory for " . $new);
        return false;
    }

    if(!is_dir($targetDir))
    {
        if(!@mkdir($targetDir, 0777, true))
        {
            LogError("renameFile failed: could not create target directory: " . $targetDir);
            return false;
        }
    }

    if(file_exists($new) || is_link($new))
    {
        LogError("renameFile failed: target already exists: " . $new);
        return false;
    }

    if(!@rename($old, $new))
    {
        $lastError = error_get_last();
        $errorText = $lastError ? " | " . print_r($lastError, true) : "";
        LogError("renameFile failed: " . $old . " -> " . $new . $errorText);
        return false;
    }

    if((is_file($new) || is_link($new)) && !file_exists($old) && !is_link($old))
    {
        LogStr("renameFile success: " . $old . " -> " . $new, 2, __FILE__, __LINE__, __FUNCTION__);
        return true;
    }

    LogError("renameFile uncertain result: " . $old . " -> " . $new);
    return false;
}
//----------------------------------------------------------------------------
function linkFile($file, $new)
{
    LogStr("linkFile() creates a symlink using PHP symlink()", 2, __FILE__, __LINE__, __FUNCTION__);

    $file = trim($file);
    $new = trim($new);

    if($file == "" || $new == "")
    {
        LogError("linkFile failed: empty source or target. file=" . $file . " new=" . $new);
        return false;
    }

    if(!file_exists($file))
    {
        LogError("linkFile failed: source missing: " . $file);
        return false;
    }

    $targetDir = dirname($new);

    if($targetDir == "" || $targetDir == ".")
    {
        LogError("linkFile failed: invalid target directory for " . $new);
        return false;
    }

    if(!is_dir($targetDir))
    {
        if(!@mkdir($targetDir, 0777, true))
        {
            LogError("linkFile failed: could not create target directory: " . $targetDir);
            return false;
        }
    }

    if(file_exists($new) || is_link($new))
    {
        LogError("linkFile failed: target already exists: " . $new);
        return false;
    }

    if(!@symlink($file, $new))
    {
        $lastError = error_get_last();
        $errorText = $lastError ? " | " . print_r($lastError, true) : "";
        LogError("linkFile failed: " . $new . " -> " . $file . $errorText);
        return false;
    }

    if(is_link($new))
    {
        LogStr("linkFile success: " . $new . " -> " . $file, 2, __FILE__, __LINE__, __FUNCTION__);
        return true;
    }

    LogError("linkFile failed: target was not created as symlink: " . $new);
    return false;
}
//----------------------------------------------------------------------------
function LogError($Error)
{
    $logDir = __DIR__ . "/logs";

    if(!is_dir($logDir))
        @mkdir($logDir, 0777, true);

    $requestUri = isset($_SERVER["REQUEST_URI"]) ? $_SERVER["REQUEST_URI"] : "";
    $remoteAddr = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "CLI";
    $scriptName = isset($_SERVER["SCRIPT_NAME"]) ? $_SERVER["SCRIPT_NAME"] : "";
    $user = isset($_COOKIE["uname"]) ? $_COOKIE["uname"] : "";
    $lastPhpError = error_get_last();

    $report = array(
        "time" => date("Y-m-d H:i:s"),
        "php_version" => PHP_VERSION,
        "script" => $scriptName,
        "request_uri" => $requestUri,
        "remote_addr" => $remoteAddr,
        "user" => $user,
        "error" => $Error,
        "last_php_error" => $lastPhpError,
        "get" => $_GET,
        "post" => $_POST
    );

    $text = print_r($report, true);

    file_put_contents($logDir . "/last_error.txt", $text);
    file_put_contents($logDir . "/error_history.log", $text . "\n\n------------------------------------------------------------\n\n", FILE_APPEND);

    LogStr("Error captured; see logs/last_error.txt", 1, __FILE__, __LINE__, __FUNCTION__);
}
//----------------------------------------------------------------------------
function GetTotalDupsByType($ID, $TYPE, $SKIP)
{
    global $dblink;
    global $dup;

    $ID = intval($ID);
    $TYPE = ($TYPE == "crc32") ? "crc32" : "size";
    $SKIP = intval($SKIP);
    $mfs = isset($dup->Profile['msize']) ? ((1024 * 1024) * intval($dup->Profile['msize'])) : 0;

    if($ID <= 0)
        return 0;

    try
    {
        if($TYPE == "crc32")
        {
            if($SKIP == 0)
            {
                $query = "
                    SELECT COUNT(files.id) AS total
                    FROM files
                    INNER JOIN (
                        SELECT crc32
                        FROM files
                        WHERE size>=:mfs
                          AND crc32!='unknown'
                          AND crc32!=''
                          AND profile=:profile_inner
                        GROUP BY crc32
                        HAVING COUNT(crc32)>1
                    ) AS files2 ON files.crc32 = files2.crc32
                    WHERE files.profile=:profile_outer;
                ";
            }
            else
            {
                $query = "
                    SELECT COUNT(files.id) AS total
                    FROM files
                    INNER JOIN (
                        SELECT crc32
                        FROM files
                        WHERE size>=:mfs
                          AND profile=:profile_inner
                        GROUP BY crc32
                        HAVING COUNT(crc32)>1
                    ) AS files2 ON files.crc32 = files2.crc32
                    WHERE files.profile=:profile_outer;
                ";
            }
        }
        else
        {
            $query = "
                SELECT COUNT(files.id) AS total
                FROM files
                INNER JOIN (
                    SELECT size
                    FROM files
                    WHERE size>=:mfs
                      AND profile=:profile_inner
                    GROUP BY size
                    HAVING COUNT(size)>1
                ) AS files2 ON files.size = files2.size
                WHERE files.profile=:profile_outer;
            ";
        }

        LogStr("MySQL/SQLite: count duplicate files by " . $TYPE, 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":mfs", $mfs, PDO::PARAM_INT);
        $sdt->bindValue(":profile_inner", $ID, PDO::PARAM_INT);
        $sdt->bindValue(":profile_outer", $ID, PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row && isset($row["total"]))
            return intval($row["total"]);
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }

    return 0;
}
//----------------------------------------------------------------------------
function GetTotalDupsGroupsByType($ID, $TYPE, $SKIP)
{
    global $dblink;

    $ID = intval($ID);
    $TYPE = ($TYPE == "crc32") ? "crc32" : "size";
    $SKIP = intval($SKIP);

    if($ID <= 0)
        return 0;

    try
    {
        if($TYPE == "crc32")
        {
            if($SKIP == 0)
            {
                $query = "
                    SELECT COUNT(*) AS total
                    FROM (
                        SELECT crc32
                        FROM files
                        WHERE profile=:profile
                          AND crc32!='unknown'
                          AND crc32!=''
                        GROUP BY crc32
                        HAVING COUNT(crc32)>1
                    ) AS dupgroups;
                ";
            }
            else
            {
                $query = "
                    SELECT COUNT(*) AS total
                    FROM (
                        SELECT crc32
                        FROM files
                        WHERE profile=:profile
                        GROUP BY crc32
                        HAVING COUNT(crc32)>1
                    ) AS dupgroups;
                ";
            }
        }
        else
        {
            $query = "
                SELECT COUNT(*) AS total
                FROM (
                    SELECT size
                    FROM files
                    WHERE profile=:profile
                    GROUP BY size
                    HAVING COUNT(size)>1
                ) AS dupgroups;
            ";
        }

        LogStr("MySQL/SQLite: count duplicate groups by " . $TYPE, 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $ID, PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row && isset($row["total"]))
            return intval($row["total"]);
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }

    return 0;
}
?>
