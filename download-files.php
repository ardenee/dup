<?php
include("dup.php");

$dup = new dup($profile);

LogStr("Download", 1, __FILE__, __LINE__, __FUNCTION__);

$profileId = intval($profile);
$fileId = isset($_GET["file"]) ? intval($_GET["file"]) : 0;

if($profileId <= 0 || $fileId <= 0)
{
    LogError("Invalid download request. profile=" . $profileId . " file=" . $fileId);
    header("Location: error.php");
    exit;
}

try
{
    $query = "SELECT file FROM files WHERE id=:id AND profile=:profile LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":id", $fileId, PDO::PARAM_INT);
    $sdt->bindValue(":profile", $profileId, PDO::PARAM_INT);
    $sdt->execute();

    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if(!$row || !isset($row["file"]) || $row["file"] == "")
    {
        LogError("Download file not found in DB. profile=" . $profileId . " file=" . $fileId);
        header("Location: error.php");
        exit;
    }

    DownloadFile($row["file"]);
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
    header("Location: error.php");
    exit;
}

function DownloadFile($fullPath)
{
    global $dup;

    $fullPath = trim($fullPath);

    if($fullPath == "" || !is_file($fullPath) || !is_readable($fullPath))
    {
        LogError("Download failed: file missing or unreadable: " . $fullPath);
        header("Location: error.php");
        exit;
    }

    $fsize = $dup->fsize($fullPath);
    $pathParts = pathinfo($fullPath);
    $basename = isset($pathParts["basename"]) ? $pathParts["basename"] : "download.bin";
    $ext = isset($pathParts["extension"]) ? strtolower($pathParts["extension"]) : "";
	
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);

if($asciiName == "")
    $asciiName = "download.bin";

    $contentType = "application/octet-stream";

    switch($ext)
    {
        case "pdf":
            $contentType = "application/pdf";
            break;

        case "txt":
        case "log":
            $contentType = "text/plain";
            break;

        case "jpg":
        case "jpeg":
            $contentType = "image/jpeg";
            break;

        case "png":
            $contentType = "image/png";
            break;

        case "gif":
            $contentType = "image/gif";
            break;
    }

    while(ob_get_level() > 0)
        ob_end_clean();

    header("Content-Type: " . $contentType);
    header("Content-Disposition: attachment; filename=\"" . addcslashes($asciiName, "\\\"") . "\"; filename*=UTF-8''" . rawurlencode($basename));
    header("Content-Length: " . intval($fsize));
    header("Cache-Control: private");
    header("X-Content-Type-Options: nosniff");
	header("Content-Transfer-Encoding: binary");
	header("Accept-Ranges: none");

    $fd = fopen($fullPath, "rb");

    if(!$fd)
    {
        LogError("Download failed: fopen() failed: " . $fullPath);
        exit;
    }

    while(!feof($fd))
    {
        echo fread($fd, 1048576);
        flush();
    }

    fclose($fd);
    exit;
}
?>