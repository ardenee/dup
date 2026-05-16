<?php
$folders = array();
$files   = array();
$dir     = "/";

if(isset($_POST['name']))
  $dir = rtrim($_POST['name'], '/').'/';
else if(isset($_GET['name']))
  $dir = rtrim($_GET['name'], '/').'/';
 
$dir = str_replace('//', '/', $dir);

if(!file_exists($dir)) 
	$dir = getcwd()."/"; 
	
if(file_exists($dir)) 
{
	if(strlen($dir)!=1)
		$tmp['current_path'] = rtrim($dir, '/');
	else
	    $tmp['current_path'] = $dir;
		
	$folders['paths'][] = $tmp;
	$dirItems = array_filter(scandir($dir), 'filter');
	natcasesort($dirItems);	
	
	foreach ($dirItems as $item) 
	{		
		if(isCleanDir($dir, $item)) 
		{		
			$tmp = array();
			
			if($item==="..")
				$tmp["path"] = substr($dir, 0, strrpos(rtrim($dir, '/'), '/'));
			else
				$tmp["path"] = $dir.$item;

			$tmp["name"] = $item;
			
			$folders['paths'][] = $tmp;
			unset($tmp); 
		}
	}
}

//----------------------------------------------------------------
function filter($var) {
	$filter = array('.');	
	return !in_array($var, $filter);
}
//----------------------------------------------------------------
function isCleanDir($dir, $fileItem) {
	return file_exists($dir . $fileItem) && is_dir($dir . $fileItem);
}
//----------------------------------------------------------------
function isCleanFile($dir, $fileItem) {
	return file_exists($dir . $fileItem) && !is_dir($dir . $fileItem);
}
//----------------------------------------------------------------
class Fileitem {
	public $dir;
	public $name;
	public function __construct($dir, $name) {
		$this->dir = $dir;
		$this->name = trim($name);
	}
}
//----------------------------------------------------------------
class File extends Fileitem {
	public $extension;
	public function __construct($dir, $name, $extension) {
		parent::__construct($dir, $name);
		$this->extension = $extension;
	}
}
//----------------------------------------------------------------
class Folder extends Fileitem 
{
	public $isEmpty;
	public function __construct($dir, $name) {
		parent::__construct($dir, $name);
		$this->isEmpty = count(scandir($dir . $name)) < 3;
	}
}
//----------------------------------------------------------------
$tmp2 = str_replace('\/','/',json_encode($folders));
$tmp2 = str_replace(':',': ',$tmp2);
$tmp2 = str_replace(',',', ',$tmp2);
//----------------------------------------------------------------
echo $tmp2;
?>
