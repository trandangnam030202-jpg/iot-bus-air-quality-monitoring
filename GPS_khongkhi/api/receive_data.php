<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/helpers.php";
require_once __DIR__ . "/../includes/config.php";

$headers = getallheaders();

$apiKey = null;

if (isset($headers["X-API-Key"])) {
    $apiKey = $headers["X-API-Key"];
} elseif (isset($headers["x-api-key"])) {
    $apiKey = $headers["x-api-key"];
} elseif (isset($_GET["api_key"])) {
    $apiKey = $_GET["api_key"];
}

if ($apiKey !== API_KEY) {
    json_response([
        "success" => false,
        "message" => "Invalid API key"
    ], 401);
}

$settings = get_app_settings($pdo);

$retentionDays = intval($settings["retention_days"] ?? 4);
if ($retentionDays < 1) {
    $retentionDays = 4;
}

$pdo->exec("DELETE FROM air_data WHERE created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)");

$data = get_request_json();

$device_id = value_or_null($data, "device_id") ?: "BUS_01";

$temperature = value_or_null($data, "temperature");
$humidity = value_or_null($data, "humidity");
$pressure = value_or_null($data, "pressure");

$co2 = value_or_null($data, "co2");
$pm1 = value_or_null($data, "pm1");
$pm25 = value_or_null($data, "pm25");
$pm10 = value_or_null($data, "pm10");

$lat = value_or_null($data, "lat");
$lon = value_or_null($data, "lon");

if (!validate_lat_lon($lat, $lon)) {
    json_response([
        "success" => false,
        "message" => "Invalid GPS location"
    ], 422);
}

$aqi_pm25 = calc_aqi_pm25($pm25);
$co2_index = calc_co2_index($co2);
$aqi = calc_air_index($pm25, $co2);

[$air_level, $color] = classify_air_quality($aqi, $settings);
[$is_alert, $alert_message] = check_alert($aqi, $settings);

$stmt = $pdo->prepare("
    INSERT INTO air_data
    (
        device_id,
        temperature,
        humidity,
        pressure,
        co2,
        pm1,
        pm25,
        pm10,
        lat,
        lon,
        aqi,
        air_level,
        color,
        is_alert,
        alert_message,
        raw_json,
        created_at
    )
    VALUES
    (
        :device_id,
        :temperature,
        :humidity,
        :pressure,
        :co2,
        :pm1,
        :pm25,
        :pm10,
        :lat,
        :lon,
        :aqi,
        :air_level,
        :color,
        :is_alert,
        :alert_message,
        :raw_json,
        NOW()
    )
");

$stmt->execute([
    ":device_id" => $device_id,
    ":temperature" => $temperature,
    ":humidity" => $humidity,
    ":pressure" => $pressure,
    ":co2" => $co2,
    ":pm1" => $pm1,
    ":pm25" => $pm25,
    ":pm10" => $pm10,
    ":lat" => $lat,
    ":lon" => $lon,
    ":aqi" => $aqi,
    ":air_level" => $air_level,
    ":color" => $color,
    ":is_alert" => $is_alert,
    ":alert_message" => $alert_message,
    ":raw_json" => json_encode($data, JSON_UNESCAPED_UNICODE)
]);

json_response([
    "success" => true,
    "message" => "Data saved",
    "id" => $pdo->lastInsertId(),
    "aqi" => $aqi,
    "aqi_pm25" => $aqi_pm25,
    "co2_index" => $co2_index,
    "air_level" => $air_level,
    "color" => $color,
    "is_alert" => $is_alert,
    "alert_message" => $alert_message,
    "send_interval_sec" => intval($settings["send_interval_sec"])
]);
?>