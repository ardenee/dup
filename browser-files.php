<?php

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

$requested = "/volume1";

if(isset($_POST["name"]))
    $requested = $_POST["name"];
else if(isset($_GET["name"]))
    $requested = $_GET["name"];

$requested = NormalizePath($requested);
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

if($parent != $realDir && IsAllowedPath($parent))
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

    if(!IsAllowedPath($fullPath))
        continue;

    if(!is_readable($fullPath))
        continue;

    $dirItems[] = $item;
}

natcasesort($dirItems);

foreach($dirItems as $item)
{
    $fullPath = NormalizePath($realDir . "/" . $item);
    AddPathEntry($folders, $fullPath, $item);
}

JsonOut($folders);

?>