<?php
include("dup.php");

$dup = new dup($profile);

LogStr("View File", 1, __FILE__, __LINE__, __FUNCTION__);

$profileId = intval($profile);
$fileId = isset($_GET["file"]) ? intval($_GET["file"]) : 0;

function DupHtml($value)
{
    return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

if($profileId <= 0 || $fileId <= 0)
{
    LogError("Invalid view request. profile=" . $profileId . " file=" . $fileId);
    header("Location: error.php");
    exit;
}

try
{
    $query = "SELECT file, size, fdate, crc32, link FROM files WHERE id=:id AND profile=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":id", $fileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if(!$row || !isset($row["file"]) || $row["file"] == "")
    {
        LogError("View file not found in DB. profile=" . $profileId . " file=" . $fileId);
        header("Location: error.php");
        exit;
    }
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}

$fullPath = trim((string)$row["file"]);
$pathParts = pathinfo($fullPath);
$basename = isset($pathParts["basename"]) ? $pathParts["basename"] : "file";
$ext = isset($pathParts["extension"]) ? strtolower($pathParts["extension"]) : "";
$fileExists = is_file($fullPath);
$fileReadable = is_readable($fullPath);
$fileSize = $fileExists ? filesize($fullPath) : false;
$fileDate = $fileExists ? filemtime($fullPath) : false;

?>
<!DOCTYPE HTML>
<HTML>
<HEAD>
<TITLE>dup - View File</TITLE>
<META CHARSET="utf-8">
<META name="robots" content="noindex">
<LINK REL="shortcut icon" HREF="images/dup.png">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/bootstrap.css">
<LINK REL="stylesheet" TYPE="text/css" HREF="files/style.css">
<style>
.preview-wrap { padding: 12px; }
.preview-meta td { padding: 4px 8px; }
</style>
</HEAD>
<BODY>
<?php echo PageHeader(); ?>
</HEADER>
<DIV id="contentWrapper">
<DIV id="content" class="preview-wrap">
<H1 class="title">View File</H1>
<table class="preview-meta table table-striped table-bordered">
<tr><td><b>Name</b></td><td><?php echo DupHtml($basename); ?></td></tr>
<tr><td><b>Path</b></td><td><?php echo DupHtml($fullPath); ?></td></tr>
<tr><td><b>Extension</b></td><td><?php echo DupHtml($ext); ?></td></tr>
<tr><td><b>Exists</b></td><td><?php echo $fileExists ? "Yes" : "No"; ?></td></tr>
<tr><td><b>Readable</b></td><td><?php echo $fileReadable ? "Yes" : "No"; ?></td></tr>
<tr><td><b>Size</b></td><td><?php echo $fileSize !== false ? DupHtml($dup->format_bytes($fileSize)) : "Unknown"; ?></td></tr>
<tr><td><b>Modified</b></td><td><?php echo $fileDate !== false ? DupHtml(date("Y-m-d H:i:s", $fileDate)) : "Unknown"; ?></td></tr>
<tr><td><b>CRC32</b></td><td><?php echo DupHtml(isset($row["crc32"]) ? $row["crc32"] : ""); ?></td></tr>
<tr><td><b>Symlink target</b></td><td><?php echo DupHtml(isset($row["link"]) ? $row["link"] : ""); ?></td></tr>
</table>
<p>
    <a class="btn" href="download-files.php?profile=<?php echo $profileId; ?>&file=<?php echo $fileId; ?>">Download</a>
    <a class="btn" href="profile-view.php?profile=<?php echo $profileId; ?>">Back to Profile</a>
</p>
<p>Inline previews for images and text files will be added after this metadata route is tested.</p>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
</HTML>
