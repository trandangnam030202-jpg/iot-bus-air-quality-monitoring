<?php
require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/helpers.php";

$settings = get_app_settings($pdo);

json_response([
    "success" => true,
    "settings" => [
        "send_interval_sec" => intval($settings["send_interval_sec"]),
        "retention_days" => intval($settings["retention_days"]),
        "threshold_mode" => $settings["threshold_mode"],
        "safe_aqi_threshold" => intval($settings["safe_aqi_threshold"]),
        "aqi_good_max" => intval($settings["aqi_good_max"]),
        "aqi_moderate_max" => intval($settings["aqi_moderate_max"]),
        "aqi_poor_max" => intval($settings["aqi_poor_max"]),
        "aqi_bad_max" => intval($settings["aqi_bad_max"]),
        "aqi_very_bad_max" => intval($settings["aqi_very_bad_max"])
    ]
]);
?>