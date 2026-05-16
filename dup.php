<?php
include("db.php");
include_once("db-log-user.php");

class dup
{		
	var $Settings;
	var $Profile;	
	
	function DefaultProfile()
	{		
		LogStr("DefaultProfile() Get Defailt settings for a Profile", 2, __FILE__, __LINE__, __FUNCTION__);
		
		$this->Profile['id']        = 0;  // 1 id
		$this->Profile['msize']     = 0;  // 2 msize
		$this->Profile['path']      = "/";  // 3 path
		$this->Profile['mpath']     = "/";  // 4 mpath
		$this->Profile['crc32']     = 0;  // 5 crc32
		$this->Profile['filetypes'] = "avi,mp4,mkv,divx,mpg,rmvb,wmv,rm,ram,vob,wmv,wma,mpc,mp3,flac,wav,ogg,exe,msi,pdf,iso,rar,gz,zip,tgz,bmp,png,gif,jpg,jpeg,pdf,rtf,doc,docx,html,txt,db,log,nds";  // 6 filetypes
		$this->Profile['scan']      = 0;  // 7 scan
		$this->Profile['lastrun']   = null;  // 8 lastrun
		$this->Profile['type']      = 1;  // 9 type
		$this->Profile['files']     = 0;  // 10 files
		$this->Profile['dups']      = 0; // 11 dups
		$this->Profile['runs']      = 0; // 12 runs
	}
	//-------------------------------------------------------------------------------------------------------
	function GetFirstProfile()
	{
		global $dblink;
		LogStr("GetFirstProfile() Gets the first profile based on id", 2, __FILE__, __LINE__, __FUNCTION__);
		$value = array();
		$query = "SELECT * FROM profiles ORDER BY id LIMIT 1;";		
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
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
	//-------------------------------------------------------------------------------------------------------
	function GetProfileTypes() // update for raw data only
	{
		global $dblink;
		$value = array();
		LogStr("GetProfileTypes() Gets profile types ordered by name", 2, __FILE__, __LINE__, __FUNCTION__);
		$query = "SELECT * FROM profiletypes ORDER BY name;";
				
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
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
	//-------------------------------------------------------------------------------------------------------
	function GetProfiles($by)
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

		LogStr("GetProfiles() Gets profiles ordered by parm - $by", 2, __FILE__, __LINE__, __FUNCTION__);

		$value = array();

		try
		{
			$query = "SELECT * FROM profiles ORDER BY " . $by . ";";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

			$sdt = $dblink->query($query);

			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$id = intval($row["id"]);
				$value[$id] = $row;
			}

			$sdt->closeCursor();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");
			exit;
		}

		return $value;
	}
//-------------------------------------------------------------------------------------------------------
	function GetAccounts()
{
    global $dblink;

    LogStr("GetAccounts() Gets accounts", 2, __FILE__, __LINE__, __FUNCTION__);

    $value = array();

    try
    {
        $query = "SELECT id, name, level FROM accounts ORDER BY id;";
        LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->query($query);

        while($row = $sdt->fetch(PDO::FETCH_ASSOC))
        {
            $id = intval($row["id"]);
            $value[$id] = $row;
        }

        $sdt->closeCursor();
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    return $value;
}
//-------------------------------------------------------------------------------------------------------
	function SetProfileType($ProfileData)
	{
		global $dblink;

		if(!is_array($ProfileData) || count($ProfileData) == 0)
			return $ProfileData;

		$type = isset($ProfileData['type']) ? intval($ProfileData['type']) : 1;

		LogStr("SetProfileType() Sets the profile type based on parm", 2, __FILE__, __LINE__, __FUNCTION__);

		try
		{
			$query = "SELECT exts FROM profiletypes WHERE id=:type LIMIT 1;";
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

			$sdt = $dblink->prepare($query);
			$sdt->bindValue(":type", $type, PDO::PARAM_INT);
			$sdt->execute();

			$row = $sdt->fetch(PDO::FETCH_ASSOC);

			if($row && isset($row["exts"]))
				$ProfileData['filetypes'] = $row["exts"];

			$sdt->closeCursor();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");
			exit;
		}

		return $ProfileData;
	}
//-------------------------------------------------------------------------------------------------------
	function GetMovedFolders($pProfile)
	{
		global $dblink;

		$pProfile = intval($pProfile);

		LogStr("GetMovedFolders() Gets Moved Folder for profile via passed parm - $pProfile", 2, __FILE__, __LINE__, __FUNCTION__);

		$value = array();

		try
		{
			if($pProfile == 0)
			{
				$query = "SELECT mpath FROM profiles;";
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

				$sdt = $dblink->query($query);
			}
			else
			{
				$query = "SELECT mpath FROM profiles WHERE id=:profile;";
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

				$sdt = $dblink->prepare($query);
				$sdt->bindValue(":profile", $pProfile, PDO::PARAM_INT);
				$sdt->execute();
			}

			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				$value[] = $row['mpath'];

			$sdt->closeCursor();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");
			exit;
		}

		return $value;
	}
	//-------------------------------------------------------------------------------------------------------
	function GetProfile($ID)
	{
		global $dblink;

		$ID = intval($ID);

		LogStr("GetProfile() Get profile based on parm - $ID", 2, __FILE__, __LINE__, __FUNCTION__);

		$value = array();

		try
		{
			if($ID > 0)
			{
				$query = "SELECT * FROM profiles WHERE id=:id LIMIT 1;";
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);

				$sdt = $dblink->prepare($query);
				$sdt->bindValue(":id", $ID, PDO::PARAM_INT);
				$sdt->execute();

				$row = $sdt->fetch(PDO::FETCH_ASSOC);

				if($row)
					$value = $row;

				$sdt->closeCursor();
			}

			if(count($value) <= 0)
				$value = $this->GetFirstProfile();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");
			exit;
		}

		if($ID != 0 && count($value) > 0)
			$value = $this->SetProfileType($value);

		return $value;
	}	
	//-------------------------------------------------------------------------------------------------------
	function __construct($pProfile)
	{
		LogStr("__construct() Constructing dup class with pram - $pProfile", 2, __FILE__, __LINE__, __FUNCTION__);

		$this->Settings = array();
		$this->Profile = array();

		$this->Settings['logs'] = 1;
		$this->GetSettings($pProfile);
	}
	//-------------------------------------------------------------------------------------------------------
	function LoadSettings($SettingsData)
	{	
		LogStr("LoadSettings() Load settings with array values - ".implode(" - ", $SettingsData), 2, __FILE__, __LINE__, __FUNCTION__);
		
		$this->Settings = $SettingsData;
		$this->Settings['start']   = 0;   // 15 start
		$this->Settings['display'] = 990; // 16 display 
		$this->Settings['msg']     = ""; //17 msg
	}
	//-------------------------------------------------------------------------------------------------------
	function SetProfileSettings($ProfileData)
	{	
		LogStr("SetProfileSettings() Set profile settings with array values - ".implode(" - ", $ProfileData), 2, __FILE__, __LINE__, __FUNCTION__);
		$this->Profile = $ProfileData;  
	}	
	//-------------------------------------------------------------------------------------------------------	
	function GetSettings($pProfile)
	{		
		global $dblink;
		LogStr("GetSettings() Get setting and load profile via pram - $pProfile", 2, __FILE__, __LINE__, __FUNCTION__);
		$query = "SELECT * FROM settings;";
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			$set=false;
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				//p($row);
				dup::LoadSettings($row); // load settinsg
				$this->Profile = dup::GetProfile($pProfile); // load profile				
					
				if(empty($this->Profile)) // no profiles yet?
					dup::DefaultProfile(); // setup default
				
				LogStr("GetProfile() Load Profile with array values - ".implode(" - ", $this->Profile), 2, __FILE__, __LINE__, __FUNCTION__);
					
				$set = true;
				break;		
			}	

			if($set==false)
				SetupDB();
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
	}
	//-------------------------------------------------------------------------------------------------------
	function GetDups($pProfile, $start)
{
    global $oldstart;
    global $dblink;

    $pProfile = intval($pProfile);
    $start = intval($start);

    if($start < 0)
        $start = 0;

    LogStr("GetDups() Get dups starting from via prams - $pProfile - $start", 2, __FILE__, __LINE__, __FUNCTION__);

    $mixedRows = array();
    $groups = array();
    $total = 0;
    $useMovedFilter = false;
    $movedLike = "";

    if(isset($this->Settings['displaymoved']) && intval($this->Settings['displaymoved']) == 0 && isset($this->Profile['mpath']) && trim($this->Profile['mpath']) != "")
    {
        $useMovedFilter = true;
        $movedLike = "%" . $this->Profile['mpath'] . "%";
    }

    if($pProfile <= 0)
    {
        $tabledata = array();
        $tabledata['stats']['total'] = 0;
        $tabledata['stats']['items'] = 0;
        $tabledata['stats']['start'] = $start;
        $tabledata['stats']['end'] = $start;
        $tabledata['stats']['oldstart'] = $oldstart;
        return $tabledata;
    }

    try
    {
        if(isset($this->Profile['crc32']) && intval($this->Profile['crc32']) > 0)
        {
            LogStr("MySQL/SQLite: Finding matches based on CRC32", 2, __FILE__, __LINE__, __FUNCTION__);

            $whereMoved = $useMovedFilter ? " AND file NOT LIKE :moved " : "";

            $query = "
                SELECT crc32, COUNT(crc32) AS cnt
                FROM files
                WHERE crc32!='unknown'
                  AND crc32!=''
                  AND profile=:profile
                  " . $whereMoved . "
                GROUP BY crc32
                HAVING COUNT(crc32)>1
                ORDER BY MIN(file) ASC
                LIMIT :start, 500;
            ";

            LogStr("MySQL/SQLite: Get duplicate CRC groups", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":profile", $pProfile, PDO::PARAM_INT);
            $sdt->bindValue(":start", $start, PDO::PARAM_INT);

            if($useMovedFilter)
                $sdt->bindValue(":moved", $movedLike, PDO::PARAM_STR);

            $sdt->execute();
            $groups = $sdt->fetchAll(PDO::FETCH_ASSOC);
            $sdt->closeCursor();

            foreach($groups as $k => $v)
            {
                $cnt = isset($v['cnt']) ? intval($v['cnt']) : 0;

                if(($total + 5) + $cnt > 1000)
                    break;

                $query = "SELECT * FROM files WHERE crc32=:crc32 AND profile=:profile" . ($useMovedFilter ? " AND file NOT LIKE :moved " : " ") . "ORDER BY file;";

                LogStr("MySQL/SQLite: Get duplicate CRC rows", 3, __FILE__, __LINE__, __FUNCTION__);

                $sdt = $dblink->prepare($query);
                $sdt->bindValue(":crc32", $v['crc32'], PDO::PARAM_STR);
                $sdt->bindValue(":profile", $pProfile, PDO::PARAM_INT);

                if($useMovedFilter)
                    $sdt->bindValue(":moved", $movedLike, PDO::PARAM_STR);

                $sdt->execute();

                while($row = $sdt->fetch(PDO::FETCH_ASSOC))
                    $mixedRows[] = $row;

                $sdt->closeCursor();

                $total += $cnt;
            }
        }
        else
        {
            LogStr("MySQL/SQLite: Finding matches based on Size", 2, __FILE__, __LINE__, __FUNCTION__);

            $whereMoved = $useMovedFilter ? " AND file NOT LIKE :moved " : "";

            $query = "
                SELECT size, COUNT(size) AS cnt
                FROM files
                WHERE profile=:profile
                  " . $whereMoved . "
                GROUP BY size
                HAVING COUNT(size)>1
                ORDER BY MIN(file) ASC
                LIMIT :start, 500;
            ";

            LogStr("MySQL/SQLite: Get duplicate size groups", 3, __FILE__, __LINE__, __FUNCTION__);

            $sdt = $dblink->prepare($query);
            $sdt->bindValue(":profile", $pProfile, PDO::PARAM_INT);
            $sdt->bindValue(":start", $start, PDO::PARAM_INT);

            if($useMovedFilter)
                $sdt->bindValue(":moved", $movedLike, PDO::PARAM_STR);

            $sdt->execute();
            $groups = $sdt->fetchAll(PDO::FETCH_ASSOC);
            $sdt->closeCursor();

            foreach($groups as $k => $v)
            {
                $cnt = isset($v['cnt']) ? intval($v['cnt']) : 0;

                if(($total + 5) + $cnt > 1000)
                    break;

                $query = "SELECT * FROM files WHERE size=:size AND profile=:profile" . ($useMovedFilter ? " AND file NOT LIKE :moved " : " ") . "ORDER BY file;";

                LogStr("MySQL/SQLite: Get duplicate size rows", 3, __FILE__, __LINE__, __FUNCTION__);

                $sdt = $dblink->prepare($query);
                $sdt->bindValue(":size", intval($v['size']), PDO::PARAM_INT);
                $sdt->bindValue(":profile", $pProfile, PDO::PARAM_INT);

                if($useMovedFilter)
                    $sdt->bindValue(":moved", $movedLike, PDO::PARAM_STR);

                $sdt->execute();

                while($row = $sdt->fetch(PDO::FETCH_ASSOC))
                    $mixedRows[] = $row;

                $sdt->closeCursor();

                $total += $cnt;
            }
        }
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
        header("Location: error.php");
        exit;
    }

    $tabledata = $this->FixArray2($mixedRows);

    $tabledata['stats']['total'] = count($groups);
    $tabledata['stats']['items'] = count($mixedRows);
    $tabledata['stats']['start'] = $start;
    $tabledata['stats']['end'] = $start + $total;
    $tabledata['stats']['oldstart'] = $oldstart;

    return $tabledata;
}
	//-------------------------------------------------------------------------------
	function GetPage($page)
{
    global $profile;
    global $dblink;

    $profileId = intval($profile);
    $page = intval($page);

    LogStr("GetPage() Gets starting point on required page via parm - $page, and global Profile $profileId", 2, __FILE__, __LINE__, __FUNCTION__);

    if($profileId <= 0 || $page <= 0)
        return array("start" => 0, "end" => 0);

    $value = array();

    try
    {
        $query = "SELECT data FROM viewpages WHERE profile=:profile LIMIT 1;";
        LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

        $sdt = $dblink->prepare($query);
        $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
        $sdt->execute();

        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row && isset($row['data']) && $row['data'] != "")
        {
            $decoded = json_decode($row['data'], true);

            if(is_array($decoded))
                $value = $decoded;
        }

        if(isset($value[$page]) && is_array($value[$page]))
            return $value[$page];
    }
    catch(PDOException $ex) {
        LogError(print_r($ex, true));
    }

    return array("start" => 0, "end" => 0);
}
	//-------------------------------------------------------------------------------	
	function FixArray2($MixedArray) // rename, remove 2
	{
		LogStr("FixArray2() to get into correct format and grouping", 2, __FILE__, __LINE__, __FUNCTION__);
		$SortedArray = array();
		
		if(count($MixedArray)>0)
		{
			foreach($MixedArray as $key=>$group)
			{	
				$hash                 = ($this->Profile['crc32']==0)?$group['size']:$group['crc32'];/// 2 = size, 4=crc32			
				$SortedArray[$hash][] = $group;			
			}
		}

		return $SortedArray;	
	}	
	//-------------------------------------------------------------------------------
	function fsize($file)
{
    LogStr("fsize() Get file size via prams - $file", 2, __FILE__, __LINE__, __FUNCTION__);

    $file = trim((string)$file);

    if($file == "" || (!is_file($file) && !is_link($file)))
        return 0;

    $size = @filesize($file);

    if($size === false)
    {
        $lastError = error_get_last();
        $errorText = $lastError ? " | " . print_r($lastError, true) : "";
        LogError("fsize() failed for file: " . $file . $errorText);
        return 0;
    }

    return intval($size);
}
	//-------------------------------------------------------------------------------
	 function format_bytes($a_bytes)
	{
		LogStr("format_bytes() Get file size text via pram - $a_bytes", 2, __FILE__, __LINE__, __FUNCTION__);
		
		if ($a_bytes < 1024) {
			return $a_bytes .' B';
		} elseif ($a_bytes < 1048576) {
			return round($a_bytes / 1024, 2) .' KiB';
		} elseif ($a_bytes < 1073741824) {
			return round($a_bytes / 1048576, 2) . ' MiB';
		} elseif ($a_bytes < 1099511627776) {
			return round($a_bytes / 1073741824, 2) . ' GiB';
		} elseif ($a_bytes < 1125899906842624) {
			return round($a_bytes / 1099511627776, 2) .' TiB';
		} elseif ($a_bytes < 1152921504606846976) {
			return round($a_bytes / 1125899906842624, 2) .' PiB';
		} elseif ($a_bytes < 1180591620717411303424) {
			return round($a_bytes / 1152921504606846976, 2) .' EiB';
		} elseif ($a_bytes < 1208925819614629174706176) {
			return round($a_bytes / 1180591620717411303424, 2) .' ZiB';
		} else {
			return round($a_bytes / 1208925819614629174706176, 2) .' YiB';
		}
	}
	//-------------------------------------------------------------------------------
function SafeSQLString($mode, $str)
{
    return str_replace("'", "''", (string)$str);
}
	//-------------------------------------------------------------------------------
function full_file_crc($file)
{
    LogStr("full_file_crc() Full CRC32 a file via pram - $file", 2, __FILE__, __LINE__, __FUNCTION__);

    $file = trim((string)$file);

    if($file == "" || !is_file($file) || !is_readable($file))
    {
        LogError("full_file_crc() skipped unreadable file: " . $file);
        return "unknown";
    }

    if(strpos($file, "#recycle") !== false)
        return "unknown";

    $hash = @hash_file('crc32b', $file);

    if($hash === false || $hash == "")
    {
        LogError("full_file_crc() hash_file failed: " . $file);
        return "unknown";
    }

    return strtoupper($hash);
}
	//-------------------------------------------------------------------------------
	function file_crc($file)
{
    LogStr("file_crc() CRC32 a file via pram - $file", 2, __FILE__, __LINE__, __FUNCTION__);

    $file = trim((string)$file);

    if($file == "" || !is_file($file) || !is_readable($file))
    {
        LogError("file_crc() skipped unreadable file: " . $file);
        return "unknown";
    }

    if(strpos($file, "#recycle") !== false)
        return "unknown";

    $size = $this->fsize($file);
    $bitebuff = (1024 * 150);

    if($size <= 0)
        return "unknown";

    if($size < ($bitebuff * 3))
        return $this->full_file_crc($file);

    $middleStart = intval(($size / 2) - ($bitebuff / 2));
    $endStart = intval($size - $bitebuff);

    if($middleStart < 0)
        $middleStart = 0;

    if($endStart < 0)
        $endStart = 0;

    $handle = @fopen($file, "rb");

    if(!$handle)
    {
        LogError("file_crc() fopen failed: " . $file);
        return "unknown";
    }

    $chunks = array();

    foreach(array(0, $middleStart, $endStart) as $offset)
    {
        if(@fseek($handle, $offset) !== 0)
        {
            fclose($handle);
            LogError("file_crc() fseek failed: " . $file . " offset=" . $offset);
            return "unknown";
        }

        $data = @fread($handle, $bitebuff);

        if($data === false || $data == "")
        {
            fclose($handle);
            LogError("file_crc() fread failed: " . $file . " offset=" . $offset);
            return "unknown";
        }

        $chunks[] = hash('crc32b', $data);
    }

    fclose($handle);

    $h1 = $chunks[0];
    $h2 = $chunks[1];
    $h3 = $chunks[2];

    LogStr("Hash 3: $h1 - $h2 - $h3 - $file", 3, __FILE__, __LINE__, __FUNCTION__);

    if($h1 == "00000000" || $h2 == "00000000" || $h3 == "00000000")
    {
        LogStr("Hashing failed : $h1 $h2 $h3 - $file, skipping!!!", 3, __FILE__, __LINE__, __FUNCTION__);
        return "0 Filled";
    }

    return strtoupper(hash('crc32b', $h1 . $h2 . $h3));
}
}
?>
