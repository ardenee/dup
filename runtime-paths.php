<?php
/**
 * Private, server-side runtime storage shared by web and CLI workers.
 * DUP_DATA_DIR must point OUTSIDE every web-served document root.
 */
function DupPrivateDir(): string
{
    static $directory = null;

    if($directory !== null)
        return $directory;

    $configured = trim((string)getenv("DUP_DATA_DIR"));
    if($configured !== "")
        $candidate = $configured;
    elseif(is_dir("/var/packages/dup/var") && is_writable("/var/packages/dup/var"))
        $candidate = "/var/packages/dup/var";
    else
        $candidate = rtrim(sys_get_temp_dir(), "/\\") .
            DIRECTORY_SEPARATOR . "dup-private-" . substr(hash("sha256", __DIR__), 0, 12);

    if(!is_dir($candidate) && !@mkdir($candidate, 0700, true) && !is_dir($candidate))
        throw new RuntimeException("DUP private runtime directory could not be created.");

    $resolved = realpath($candidate);
    if($resolved === false || !is_writable($resolved))
        throw new RuntimeException("DUP private runtime directory is unavailable.");

    $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? "";
    if($documentRoot !== "" && ($public = realpath($documentRoot)))
    {
        $normalizedPrivate = rtrim(str_replace("\\", "/", $resolved), "/");
        $normalizedPublic = rtrim(str_replace("\\", "/", $public), "/");
        if(PHP_OS_FAMILY === "Windows")
        {
            $normalizedPrivate = strtolower($normalizedPrivate);
            $normalizedPublic = strtolower($normalizedPublic);
        }
        if($normalizedPrivate === $normalizedPublic ||
            str_starts_with($normalizedPrivate, $normalizedPublic . "/"))
            throw new RuntimeException("DUP_DATA_DIR must not be inside the web document root.");
    }

    $directory = $resolved;
    return $directory;
}

function DupPrivateSubdir(string $name): string
{
    if(!preg_match('/^[a-z][a-z0-9_-]*$/', $name))
        throw new InvalidArgumentException("Invalid private directory name.");

    $directory = DupPrivateDir() . DIRECTORY_SEPARATOR . $name;
    if(!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory))
        throw new RuntimeException("DUP could not create a private data directory.");

    return $directory;
}

function DupLogDir(): string
{
    return DupPrivateSubdir("logs");
}

function DupBackupDir(): string
{
    return DupPrivateSubdir("backup");
}
