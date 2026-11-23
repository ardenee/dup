<?php
// Directory names can reveal server layout. Restrict the picker to administrators.
require_once __DIR__ . "/dup.php";
DupRequireAdmin();
header("Content-Type: application/json; charset=utf-8");

function GetAllowedRoots()
{
    $roots = array();

    // Prefer real Synology volume roots detected from the filesystem.
    $volumeRoots = glob("/volume*", GLOB_ONLYDIR);

    if($volumeRoots)
    {
        foreach($volumeRoots as $root)
        {
            $root = NormalizePath($root);

            if(preg_match('/^\/volume[0-9]+$/', $root))
                $roots[] = $root;
        }
    }

    // Also respect open_basedir if it explicitly includes narrower /volume paths.
    $openBaseDir = ini_get("open_basedir");

    if($openBaseDir !== false && trim($openBaseDir) != "")
    {
        $parts = explode(PATH_SEPARATOR, $openBaseDir);

        foreach($parts as $part)
        {
            $part = NormalizePath($part);

            if($part == "")
                continue;

            if(preg_match('/^\/volume[0-9]+(\/.*)?$/', $part))
                $roots[] = $part;
        }
    }

    $roots = array_values(array_unique($roots));
    sort($roots, SORT_NATURAL | SORT_FLAG_CASE);

    return $roots;
}

$allowedRoots = GetAllowedRoots();

function JsonOut($data)
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function NormalizePath($path)
{
    $path = trim((string)$path);

    if($path == "")
        $path = "/";

    $path = str_replace("\\", "/", $path);

    while(strpos($path, "//") !== false)
        $path = str_replace("//", "/", $path);

    if($path != "/" && substr($path, -1) == "/")
        $path = rtrim($path, "/");

    return $path;
}

function IsAllowedPath($path)
{
    global $allowedRoots;

    $path = NormalizePath($path);

    foreach($allowedRoots as $root)
    {
        if($path == $root || strpos($path, $root . "/") === 0)
            return true;
    }

    return false;
}

function SafeRealPath($path)
{
    $real = @realpath($path);

    if($real === false)
        return false;

    return NormalizePath($real);
}

function AddPathEntry(&$folders, $path, $name)
{
    $folders["paths"][] = array(
        "path" => $path,
        "name" => $name
    );
}

$requested = "/";

if(isset($_POST["name"]))
    $requested = $_POST["name"];
else if(isset($_GET["name"]))
    $requested = $_GET["name"];

if(!is_string($requested) || strlen($requested) > 4096)
{
    http_response_code(400);
    JsonOut(array("paths" => array(), "error" => "Invalid directory request."));
}

$requested = NormalizePath($requested);

// The original picker started at filesystem root. Keep the root as a virtual
// volume selector, without exposing unrelated parts of the server filesystem.
if($requested === "/")
{
    $volumes = array("paths" => array(array("path" => "/", "name" => "/")),
        "current_path" => "/");
    foreach($allowedRoots as $root)
    {
        $realRoot = SafeRealPath($root);
        if($realRoot !== false && IsAllowedPath($realRoot) &&
           is_dir($realRoot) && is_readable($realRoot))
            AddPathEntry($volumes, $realRoot, basename($root));
    }
    JsonOut($volumes);
}

$realDir = SafeRealPath($requested);

if($realDir === false || !is_dir($realDir) || !IsAllowedPath($realDir))
{
    $fallback = false;

    foreach($allowedRoots as $root)
    {
        $rootReal = SafeRealPath($root);

        if($rootReal !== false && is_dir($rootReal) && is_readable($rootReal))
        {
            $fallback = $rootReal;
            break;
        }
    }

    if($fallback === false)
    {
        JsonOut(array(
            "paths" => array(),
            "error" => "No readable allowed volume roots found.",
            "requested" => $requested
        ));
    }

    $realDir = $fallback;
}

$folders = array(
    "paths" => array()
);

$currentPath = ($realDir == "/") ? "/" : rtrim($realDir, "/");

AddPathEntry($folders, $currentPath, $currentPath);

$parent = dirname($realDir);

if($parent != $realDir && ($parent === "/" || IsAllowedPath($parent)))
    AddPathEntry($folders, $parent, "..");

$items = @scandir($realDir);

if($items === false)
{
    JsonOut(array(
        "paths" => $folders["paths"],
        "error" => "Unable to read folder. Check open_basedir and permissions.",
        "requested" => $requested,
        "current_path" => $currentPath
    ));
}

$dirItems = array();

foreach($items as $item)
{
    if($item == "." || $item == "..")
        continue;

    $fullPath = NormalizePath($realDir . "/" . $item);

    if(!is_dir($fullPath))
        continue;

    // Resolve links before listing: never navigate through a symlink outside
    // an authorized volume or open_basedir boundary.
    $resolved = SafeRealPath($fullPath);
    if($resolved === false || !IsAllowedPath($resolved) || !is_dir($resolved))
        continue;

    if(!is_readable($resolved))
        continue;

    $dirItems[] = $item;
}

natcasesort($dirItems);

foreach($dirItems as $item)
{
    $fullPath = SafeRealPath(NormalizePath($realDir . "/" . $item));
    if($fullPath !== false && IsAllowedPath($fullPath))
        AddPathEntry($folders, $fullPath, $item);
}

JsonOut($folders);

?>