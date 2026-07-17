<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/helpers.php";

$device_id = isset($_GET["device_id"]) && $_GET["device_id"] !== "" ? $_GET["device_id"] : null;

$limit = isset($_GET["limit"]) ? intval($_GET["limit"]) : 500;
$limit = max(1, min($limit, 2000));

$date = isset($_GET["date"]) && $_GET["date"] !== "" ? $_GET["date"] : null;

$where = [
    "lat IS NOT NULL",
    "lon IS NOT NULL"
];

$params = [];

if ($device_id) {
    $where[] = "device_id = :device_id";
    $params[":device_id"] = $device_id;
}

if ($date) {
    $where[] = "DATE(created_at) = :date";
    $params[":date"] = $date;
}

$sql = "
    SELECT
        id,
        device_id,
        temperature,
        humidity,
        pressure,
        co2,
        pm25,
        lat,
        lon,
        aqi,
        air_level,
        color,
        is_alert,
        alert_message,
        DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS created_at
    FROM air_data
    WHERE " . implode(" AND ", $where) . "
    ORDER BY created_at DESC
    LIMIT " . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$rows = $stmt->fetchAll();
$rows = array_reverse($rows);

json_response([
    "success" => true,
    "count" => count($rows),
    "data" => $rows
]);
?>