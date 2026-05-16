<?php
include("db.php");

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
	function GetProfiles($by) // update for raw data only
	{
		global $dblink;
		LogStr("GetProfiles() Gets profiles ordered by parm - $by", 2, __FILE__, __LINE__, __FUNCTION__);
		$value = array();
		$query = "SELECT * FROM profiles ORDER BY $by;";
		
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
	function GetAccounts() // update for raw data only
	{
		global $dblink;
		LogStr("GetAccounts() Gets accounts", 2, __FILE__, __LINE__, __FUNCTION__);
		$value = array();
		$query = "SELECT * FROM accounts;";
		
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
	function SetProfileType($ProfileData)
	{
		global $dblink;
		//p($ProfileData);
		LogStr("SetProfileType() Sets the profiles type based on parm", 2, __FILE__, __LINE__, __FUNCTION__);
		$query = "SELECT exts FROM profiletypes WHERE id=".$ProfileData['type'].";";
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
			{
				$ProfileData['filetypes'] = $row["exts"];
				break;
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
			
		return $ProfileData;
	}
	//-------------------------------------------------------------------------------------------------------
	function GetMovedFolders($pProfile)
	{
		global $dblink;
		LogStr("GetMovedFolders() Gets Moved Folder for profile via passed parm - $pProfile", 2, __FILE__, __LINE__, __FUNCTION__);
		$value   = array();
		
		if($pProfile==0)
			$query = "SELECT mpath FROM profiles;"; // get all
		else	
			$query = "SELECT mpath FROM profiles WHERE id=".$pProfile.";";	
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);	
			$sdt = $dblink->query($query);
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				$value[] = $row['mpath']; 
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
			
		return $value;	
	}
	//-------------------------------------------------------------------------------------------------------
	function GetProfile($ID) // update for raw data only
	{
		global $dblink;
		global $profile;
		LogStr("GetProfile() Get profile based on parm - $ID", 2, __FILE__, __LINE__, __FUNCTION__);	
		$value = array();
		$query = "SELECT * FROM profiles WHERE id=$ID;";
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt   = $dblink->query($query);			
			$pros  = $sdt->fetchAll();
			//if($sdt->rowCount()==0)
				
			if(count($pros)<=0)
				$value = dup::GetFirstProfile();
			else
			{	
				foreach($pros as $key => $row)
				{
					$value = $row; 
					break;
				}
			}
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
		
		if($ID!=0)
			$value = dup::SetProfileType($value);
						
		return $value;	
	}		
	//-------------------------------------------------------------------------------------------------------
	function __construct($pProfile) 
	{
		LogStr("__construct() Constructing dup class with pram - $pProfile", 2, __FILE__, __LINE__, __FUNCTION__);
		$this->Settings['logs']     = 1; //17 msg		
	    dup::GetSettings($pProfile);
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
		LogStr("GetDups() Get dups starting from via prams - $pProfile - $start", 2, __FILE__, __LINE__, __FUNCTION__);
		$tabledata  = array();	
		$sizes      = array();
		$total      = 0;		
		$additional = "";	   
			
		if($this->Settings['displaymoved']==0)
			$additional = " AND file NOT LIKE '%".SafeSQLValue($this->Profile['mpath'])."%'";
		
		if($this->Profile['crc32']>0) // crc32
		{	
			LogStr("MySQL: Finding matches based on CRC32", 2, __FILE__, __LINE__, __FUNCTION__);			
			$query = "SELECT crc32, COUNT(crc32) AS cnt FROM files WHERE crc32!='unknown' AND profile=".$pProfile."$additional GROUP BY crc32 HAVING COUNT(crc32)>1 ORDER BY files.file ASC LIMIT $start, 500;";			
			
			try
			{
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);
				
				while($row = $sdt->fetch(PDO::FETCH_ASSOC))
					array_push($sizes, $row); 					
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				header("Location: error.php");	
				Exit;
			}	
														
			foreach($sizes as $k => $v) // max of 500 matches entered
			{				
				if(($total+5)+$v['cnt']>1000)
					break;	// drop out but keep track of position and totals for next/back page
					
				$query = "SELECT * FROM files WHERE crc32='".$v['crc32']."' AND profile=".$pProfile.";";
				
				try
				{
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
					$sdt = $dblink->query($query);
					
					while($row = $sdt->fetch(PDO::FETCH_ASSOC))
						array_push($tabledata, $row); 					
				}
				catch(PDOException $ex) {
					LogError(print_r($ex, true));
					header("Location: error.php");	
					Exit;
				}	
					
				$total+=$v['cnt'];
			}
		}
		else // size
		{			
			LogStr("MySQL: Finding matches based on Size", 2, __FILE__, __LINE__, __FUNCTION__);		 		
			$query = "SELECT size, COUNT(size) FROM files WHERE profile=".$pProfile."$additional GROUP BY size HAVING COUNT(size)>1 ORDER BY file ASC LIMIT $start, 500;";
								
			try
			{
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);
				
				while($row = $sdt->fetch(PDO::FETCH_ASSOC))
					$sizes = $row; 
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				header("Location: error.php");	
				Exit;
			}	
				
			foreach($sizes as $k => $v) // max of 500 matches entered
			{				
				if(($total+5)+$v[1]>1000)
					break;	// drop out but keep track of position and totals for next/back page
					
				$query = "SELECT * FROM files WHERE size=".$v[0]." AND profile=".$pProfile.";";
				
				try
				{
					LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
					$sdt = $dblink->query($query);
					
					while($row = $sdt->fetch(PDO::FETCH_ASSOC))
						array_push($tabledata, $row); 					
				}
				catch(PDOException $ex) {
					LogError(print_r($ex, true));
					header("Location: error.php");	
					Exit;
				}
										
				$total+=$v[1];
			}		
		}	
				
		$tabledata                      = $this->FixArray2($tabledata); // set mas pram limit	to less tham 1000 as per php POST limits
		$tabledata['stats']['total']    = count($sizes);         // we got 500 items to view
		$tabledata['stats']['items']    = (count($tabledata)-1); // we only kept this amount out of the 500 due to more then 1 doubble	, -1 for stats key			
		$tabledata['stats']['start']    = $start;	             // starting from here
		$tabledata['stats']['end']	    = $start+$total;         // stopping here		
		$tabledata['stats']['oldstart'] = $oldstart;             // stopping here	

		return $tabledata;	
	}	
	//-------------------------------------------------------------------------------
	function GetPage($page)
	{
		global $profile;
		global $dblink;
		LogStr("GetPage() Gets starting point on required page via parm - $page, and global Profile $profile", 2, __FILE__, __LINE__, __FUNCTION__);		
		$query = "SELECT data FROM viewpages WHERE profile=".$profile.";";
		$value = array();
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
			
			while($row = $sdt->fetch(PDO::FETCH_ASSOC))
				$value = json_decode($row['data'], true);					
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}	
		
		return $value[$page];// array with start and end
	}
	//-------------------------------------------------------------------------------
	function UpdatePages($page, $start, $end)
	{
		global $profile;
		global $dblink;
		$value = array();
		LogStr("UpdatePages() Sets page info based on parms - $page, $start, $end", 2, __FILE__, __LINE__, __FUNCTION__);		
		$data[$page]["start"] = $start;
		$data[$page]["end"]   = $end;					
		
		if($page!=1)
		{	
			$query = "SELECT data FROM viewpages WHERE profile=".$profile.";";
			
			try
			{
				LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
				$sdt = $dblink->query($query);
				
				while($row = $sdt->fetch(PDO::FETCH_ASSOC))
					$value = json_decode($row['data'], true); 
			}
			catch(PDOException $ex) {
				LogError(print_r($ex, true));
				header("Location: error.php");	
				Exit;
			}	
					
			$value[$page]["start"] = $data[$page]["start"];
			$value[$page]["end"]   = $data[$page]["end"];
			$data                  = $value;	
		}

		$jdata = json_encode($data);
		$query = "UPDATE viewpages SET data='$jdata' WHERE profile=".$profile.";";
		
		try
		{
			LogStr("MySQL: $query", 3, __FILE__, __LINE__, __FUNCTION__);
			$sdt = $dblink->query($query);
		}
		catch(PDOException $ex) {
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		}			
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
	function fsize($file) // update to ls -le format and work with time to get size
	{	
// -rwxrwxrwx    1 admin    users       333715 Sat Apr  2 12:06:16 2011 /volume1/photo/PHOTO/IMG0002/DC000207.jpg

		LogStr("fsize() Get file size via prams - $file", 2, __FILE__, __LINE__, __FUNCTION__);
		$tmp = "";
		
		try
		{
			LogStr("shell_exec: ls -le \"$file\"", 2, __FILE__, __LINE__, __FUNCTION__);
			$tmp = shell_exec("ls -le \"$file\""); //e
		}		
		catch(Exception $ex) {
			LogStr("shell_exec: ".$e->getMessage(), 2, __FILE__, __LINE__, __FUNCTION__);
			$LastPHPError = error_get_last();	
			LogStr("shell_exec: ".$LastPHPError['message'], 2, __FILE__, __LINE__, __FUNCTION__);
			
			LogError(print_r($ex, true));
			header("Location: error.php");	
			Exit;
		} 
		
		$FileInfoString = ProcesslsStr($tmp);		
		//$FileInfoString['link'] = is_link($file)?1:0;		
		$FileInfoArray  = explode("\t", $FileInfoString);

		//LogStr("Result: ".implode(" - ", $FileInfoArray), 3, __FILE__, __LINE__, __FUNCTION__);		
		//p($FileInfoArray);		
		
		return $FileInfoArray[0];
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
		if($mode==0) //MySQL
			return sqlite_escape_string($str);
		else	// sqlite
			return str_replace("'", "''", $str);
	}
	//-------------------------------------------------------------------------------
	function full_file_crc($file) 
	{ 	
		LogStr("full_file_crc() Full CRC32 a file via pram - $file", 2, __FILE__, __LINE__, __FUNCTION__);
		
		//echo $file." - Full\n";
		
		if(strpos($file, "#recycle")>0) // dont crc files in bin // remove already checked
			return "unknown";
			
		return strtoupper(hash_file('crc32b', $file));
	}	
	//-------------------------------------------------------------------------------
	function file_crc($file) 
	{ 	
		LogStr("file_crc() CRC32 a file via pram - $file", 2, __FILE__, __LINE__, __FUNCTION__);
		
		//echo $file." Part\n";
		
		if(strpos($file, "#recycle")>0) // don't crc files in bin // remove already checked!!
			return "unknown";
			
		$size     = $this->fsize($file);
		$bitebuff = (1024*150); // 150 kb chunks, totaling 450 kb crc checking per file, start, middle and end.	

		//LogStr("size: $size", 3, __FILE__, __LINE__, __FUNCTION__);
		//echo $size."\n";
		
		if($size<($bitebuff*3)) // full scan will be faster!
			return $this->full_file_crc($file);
		
		if($size>2147483647) // Files over 2G in size (php limited to 32bit int sizes)
		{
			$ms = (2147483647/2)-($bitebuff/2);
			$es = 2147483647-$bitebuff;
		}
		else // Files under 2GB in size
		{
			$ms = ($size/2)-($bitebuff/2); 
			$es = $size-$bitebuff;	
		}
		
		if(strpos($ms, ".")>0) // remove points
			$ms = substr($ms, 0, strpos($ms, "."));	
		
		if(strpos($es, ".")>0) // remove points
			$es = substr($es, 0, strpos($es, "."));		
			
		try
		{		
			$sfile_string = file_get_contents($file, TRUE, NULL, 0,   $bitebuff);
			$mfile_string = file_get_contents($file, TRUE, NULL, $ms, $bitebuff); 
			$efile_string = file_get_contents($file, TRUE, NULL, $es, $bitebuff); 
		}
		catch(Exception $ex) { 
			LogStr("shell_exec: ".$e->getMessage(), 2, __FILE__, __LINE__, __FUNCTION__);
			$LastPHPError = error_get_last();	
			LogStr("shell_exec: ".$LastPHPError['message'], 2, __FILE__, __LINE__, __FUNCTION__);			
			LogError(print_r($ex, true));
		} 
		
		$h1 = hash('crc32b', $sfile_string);
		$h2 = hash('crc32b', $mfile_string);
		$h3 = hash('crc32b', $efile_string);
		
		LogStr("Hash 3: $h1 - $h2 - $h3 - $file", 3, __FILE__, __LINE__, __FUNCTION__);
		
		if($h1=="00000000" or $h2=="00000000" or $h3=="00000000")
		{
			LogStr("Hashing failed : $h1 $h2 $h3 - $file, skipping!!!", 3, __FILE__, __LINE__, __FUNCTION__);
			return "0 Filled";
		}
		
		$crc = hash('crc32b', $h1.$h2.$h3);

		return strtoupper($crc); 
	}
}
?>