<?php

require_once("dup.php");

$dup = new dup($profile);

LogStr("Get Message", 1, __FILE__, __LINE__, __FUNCTION__);

try
{
    $query = "SELECT message FROM msg LIMIT 1;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->query($query);
    $row = $sdt->fetch(PDO::FETCH_ASSOC);
    $sdt->closeCursor();

    if($row && isset($row["message"]))
        echo $row["message"];

    $query = "UPDATE msg SET message=:message;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->prepare($query);
    $sdt->bindValue(":message", "", PDO::PARAM_STR);
    $sdt->execute();
    $sdt->closeCursor();
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));
}

?>