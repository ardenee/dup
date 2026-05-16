<?php

include("db.php");

header("Content-Type: application/json; charset=utf-8");

LogStr("Status Update", 1, __FILE__, __LINE__, __FUNCTION__);

$stat = array();

try
{
    $query = "SELECT profile, value, data FROM status ORDER BY profile;";
    LogStr("MySQL/SQLite: $query", 3, __FILE__, __LINE__, __FUNCTION__);

    $sdt = $dblink->query($query);

    while($row = $sdt->fetch(PDO::FETCH_ASSOC))
    {
        $stat[] = array(
            "profile" => intval($row["profile"]),
            "value"   => isset($row["value"]) ? $row["value"] : "",
            "data"    => isset($row["data"]) ? $row["data"] : ""
        );
    }

    $sdt->closeCursor();

    echo json_encode($stat, JSON_UNESCAPED_SLASHES);
    exit;
}
catch(PDOException $ex)
{
    LogError(print_r($ex, true));

    http_response_code(500);

    echo json_encode(array(
        "error" => true,
        "message" => "Could not load profile status."
    ), JSON_UNESCAPED_SLASHES);

    exit;
}

?>