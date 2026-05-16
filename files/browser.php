<?php

$folders = array();
$files = array();
$dir = "/volume1/web/dup/";

if(isset($_POST['path']))
  $dir = $_POST['path'];

if(isset($_GET['path']))
  $dir = $_GET['path'];

//$dir = urldecode(strval($_POST['dir'])).'/';
$dir = str_replace('//', '/', $dir);

if (file_exists($dir)) {
	$dirItems = array_filter(scandir($dir), 'filter');
	natcasesort($dirItems);
	foreach ($dirItems as $item) {
		if (isCleanDir($dir, $item)) {
			$folders[] = new Folder($dir, $item);
		} else if (isCleanFile($dir, $item)) {
			$ext = preg_replace('/^[^.]*\./', '', $item);
			$files[] = new File($dir, $item, $ext);
		}
	}
}

function filter($var) {
	$filter = array('.', '..');
	return !in_array($var, $filter);
}

function isCleanDir($dir, $fileItem) {
	return file_exists($dir . $fileItem) && is_dir($dir . $fileItem);
}

function isCleanFile($dir, $fileItem) {
	return file_exists($dir . $fileItem) && !is_dir($dir . $fileItem);
}

class Fileitem {
	public $dir;
	public $name;
	public function __construct($dir, $name) {
		$this->dir = $dir;
		$this->name = trim($name);
	}
}
class File extends Fileitem {
	public $extension;
	public function __construct($dir, $name, $extension) {
		parent::__construct($dir, $name);
		$this->extension = $extension;
	}
}
class Folder extends Fileitem {
	public $isEmpty;
	public function __construct($dir, $name) {
		parent::__construct($dir, $name);
		$this->isEmpty = count(scandir($dir . $name)) < 3;
	}
}

?><ul rel="<?php echo htmlentities($dir) ?>" class="node">
<?php foreach ($folders as $item): ?>
	<li class="folder<?php echo $item->isEmpty ? ' empty' : '' ?>" rel="<?php echo $item->dir . $item->name ?>">
		<span><?php echo $item->name ?></span>
	</li>
<?php endforeach ?>
<?php foreach ($files as $item): ?>
	<li class="file <?php echo $item->extension ?>" rel="<?php echo $item->dir . $item->name ?>">
		<span><?php echo $item->name ?></span>
	</li>
<?php endforeach ?>
</ul>