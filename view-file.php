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

function DupExtList($settingName, $defaultValue)
{
    global $dup;

    $value = isset($dup->Settings[$settingName]) ? trim((string)$dup->Settings[$settingName]) : $defaultValue;
    $parts = explode(",", strtolower($value));
    $clean = array();

    foreach($parts as $part)
    {
        $part = trim($part);

        if($part != "" && !in_array($part, $clean))
            $clean[] = $part;
    }

    return $clean;
}

function DupExtAllowed($ext, $list)
{
    return in_array(strtolower((string)$ext), $list);
}

function DupImageContentType($ext)
{
    switch(strtolower((string)$ext))
    {
        case "jpg":
        case "jpeg": return "image/jpeg";
        case "png": return "image/png";
        case "gif": return "image/gif";
        case "webp": return "image/webp";
        case "bmp": return "image/bmp";
    }

    return "application/octet-stream";
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
$viewerEnabled = isset($dup->Settings["view_enable"]) ? intval($dup->Settings["view_enable"]) : 1;
$imageExts = DupExtList("view_image_exts", "jpg,jpeg,png,gif,webp,bmp");
$textExts = DupExtList("view_text_exts", "txt,log,json,xml,csv,nfo,srt,md,php,css,js,html,htm,ini,conf");
$pdfExts = DupExtList("view_pdf_exts", "pdf");
$isImage = DupExtAllowed($ext, $imageExts);
$isText = DupExtAllowed($ext, $textExts);
$isPdf = DupExtAllowed($ext, $pdfExts);
$canPreview = ($viewerEnabled == 1 && ($isImage || $isText || $isPdf));

if($canPreview && $fileExists && $fileReadable && isset($_GET["raw"]) && intval($_GET["raw"]) == 1 && ($isImage || $isPdf))
{
    while(ob_get_level() > 0)
        ob_end_clean();

    header("Content-Type: " . ($isImage ? DupImageContentType($ext) : "application/pdf"));
    header("Content-Length: " . intval(filesize($fullPath)));
    header("X-Content-Type-Options: nosniff");
    readfile($fullPath);
    exit;
}

$textPreview = "";
$textTooLarge = false;

if($canPreview && $fileExists && $fileReadable && $isText)
{
    $maxBytes = 1048576;
    $size = filesize($fullPath);

    if($size !== false && $size > $maxBytes)
        $textTooLarge = true;

    $textPreview = file_get_contents($fullPath, false, null, 0, $maxBytes);

    if($textPreview === false)
        $textPreview = "";
}
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
.preview-note { margin-top: 12px; padding: 8px; border: 1px solid #ccc; background: #fff; }
.preview-image { max-width: 100%; height: auto; border: 1px solid #ccc; background: #fff; }
.preview-text { white-space: pre-wrap; font-family: Consolas, monospace; background: #fff; border: 1px solid #ccc; padding: 10px; overflow: auto; }
.preview-frame { width: 100%; height: 80vh; border: 1px solid #ccc; }
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
<tr><td><b>Viewer allowed</b></td><td><?php echo $canPreview ? "Yes" : "No"; ?></td></tr>
<tr><td><b>Exists</b></td><td><?php echo $fileExists ? "Yes" : "No"; ?></td></tr>
<tr><td><b>Readable</b></td><td><?php echo $fileReadable ? "Yes" : "No"; ?></td></tr>
<tr><td><b>Size</b></td><td><?php echo $fileSize !== false ? DupHtml($dup->format_bytes($fileSize)) : "Unknown"; ?></td></tr>
<tr><td><b>Modified</b></td><td><?php echo $fileDate !== false ? DupHtml(date("Y-m-d H:i:s", $fileDate)) : "Unknown"; ?></td></tr>
<tr><td><b>CRC32</b></td><td><?php echo DupHtml(isset($row["crc32"]) ? $row["crc32"] : ""); ?></td></tr>
<tr><td><b>Symlink target</b></td><td><?php echo DupHtml(isset($row["link"]) ? $row["link"] : ""); ?></td></tr>
</table>
<p>
    <a class="btn" href="download-files.php?profile=<?php echo $profileId; ?>&file=<?php echo $fileId; ?>">Download</a>
    <a class="btn" href="javascript:history.back();">Back</a>
</p>
<?php if($viewerEnabled != 1) { ?>
    <div class="preview-note">File viewer previews are disabled in General Configuration.</div>
<?php } else if(!$canPreview) { ?>
    <div class="preview-note">No preview is enabled for this file extension. Use Download to open it locally.</div>
<?php } else if(!$fileExists || !$fileReadable) { ?>
    <div class="preview-note">The file is missing or not readable.</div>
<?php } else if($isImage) { ?>
    <img class="preview-image" src="view-file.php?profile=<?php echo $profileId; ?>&file=<?php echo $fileId; ?>&raw=1" alt="<?php echo DupHtml($basename); ?>">
<?php } else if($isPdf) { ?>
    <iframe class="preview-frame" src="view-file.php?profile=<?php echo $profileId; ?>&file=<?php echo $fileId; ?>&raw=1"></iframe>
<?php } else if($isText) { ?>
    <?php if($textTooLarge) { ?><p><b>Showing the first 1 MiB only.</b></p><?php } ?>
    <div class="preview-text"><?php echo DupHtml($textPreview); ?></div>
<?php } else { ?>
    <div class="preview-note">No inline preview is available for this file type.</div>
<?php } ?>
</DIV>
</DIV>
<?php echo PageFooter(); ?>
</BODY>
</HTML>
