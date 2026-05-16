<?php
function DupLogEnsureUserColumn()
{
    global $dblink;

    if(!isset($dblink) || !($dblink instanceof PDO))
        return;

    try { $dblink->exec("ALTER TABLE logs ADD user_id INT NOT NULL DEFAULT 0;"); }
    catch(PDOException $ex) {}

    try { $dblink->exec("CREATE INDEX logs_user_id ON logs (user_id);"); }
    catch(PDOException $ex) {}
}

function DupLogCurrentUserId()
{
    global $dblink;

    if(!isset($dblink) || !($dblink instanceof PDO))
        return 0;

    if(!isset($_SERVER['REMOTE_ADDR']) || strlen($_SERVER['REMOTE_ADDR']) == 0)
        return 0;

    $user = isset($_COOKIE['uname']) ? trim($_COOKIE['uname']) : '';

    if($user == '')
        return 0;

    try
    {
        $query = "SELECT id FROM accounts WHERE name=:user LIMIT 1;";
        $sdt = $dblink->prepare($query);
        $sdt->bindValue(':user', $user, PDO::PARAM_STR);
        $sdt->execute();
        $row = $sdt->fetch(PDO::FETCH_ASSOC);
        $sdt->closeCursor();

        if($row && isset($row['id']))
            return intval($row['id']);
    }
    catch(PDOException $ex) {}

    return 0;
}

function DupLogApplyUserToRequestRows($requestStart, $userId)
{
    global $dblink;

    if($userId <= 0)
        return;

    if(!isset($dblink) || !($dblink instanceof PDO))
        return;

    try
    {
        $query = "UPDATE logs SET user_id=:user_id WHERE user_id=0 AND stamp>=:request_start;";
        $sdt = $dblink->prepare($query);
        $sdt->bindValue(':user_id', intval($userId), PDO::PARAM_INT);
        $sdt->bindValue(':request_start', $requestStart, PDO::PARAM_STR);
        $sdt->execute();
        $sdt->closeCursor();
    }
    catch(PDOException $ex) {}
}

DupLogEnsureUserColumn();

$dupLogRequestStart = date('Y-m-d H:i:s', max(0, (isset($_SERVER['REQUEST_TIME']) ? intval($_SERVER['REQUEST_TIME']) : time()) - 2));
$dupLogUserId = DupLogCurrentUserId();

register_shutdown_function(function() use ($dupLogRequestStart, $dupLogUserId)
{
    DupLogApplyUserToRequestRows($dupLogRequestStart, $dupLogUserId);
});
?>
