<?php
session_start();

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/helpers.php";

if (
    !isset($_SESSION["admin_authenticated"]) ||
    $_SESSION["admin_authenticated"] !== true
) {
    json_response([
        "success" => false,
        "message" => "Bạn chưa đăng nhập quyền quản trị"
    ], 401);
}

$data = get_request_json();

$send_interval_sec = intval($data["send_interval_sec"] ?? 3600);

$retention_days = intval($data["retention_days"] ?? 4);

$threshold_mode = $data["threshold_mode"] ?? "auto";

$safe_aqi_threshold = intval(
    $data["safe_aqi_threshold"] ?? 100
);

$aqi_good_max = intval(
    $data["aqi_good_max"] ?? 50
);

$aqi_moderate_max = intval(
    $data["aqi_moderate_max"] ?? 100
);

$aqi_poor_max = intval(
    $data["aqi_poor_max"] ?? 150
);

$aqi_bad_max = intval(
    $data["aqi_bad_max"] ?? 200
);

$aqi_very_bad_max = intval(
    $data["aqi_very_bad_max"] ?? 300
);


if ($send_interval_sec < 5) {
    $send_interval_sec = 5;
}

if ($send_interval_sec > 86400) {
    $send_interval_sec = 86400;
}


if ($retention_days < 1) {
    $retention_days = 1;
}

if ($retention_days > 30) {
    $retention_days = 30;
}


if (
    $threshold_mode !== "auto" &&
    $threshold_mode !== "custom"
) {
    $threshold_mode = "auto";
}


if ($safe_aqi_threshold < 1) {
    $safe_aqi_threshold = 1;
}

if ($safe_aqi_threshold > 500) {
    $safe_aqi_threshold = 500;
}


if (
    !(
        $aqi_good_max <
        $aqi_moderate_max &&

        $aqi_moderate_max <
        $aqi_poor_max &&

        $aqi_poor_max <
        $aqi_bad_max &&

        $aqi_bad_max <
        $aqi_very_bad_max
    )
) {
    json_response([
        "success" => false,
        "message" => "Các ngưỡng AQI tùy chỉnh phải tăng dần"
    ], 422);
}


$stmt = $pdo->prepare("
    UPDATE app_settings
    SET
        send_interval_sec = :send_interval_sec,
        retention_days = :retention_days,
        threshold_mode = :threshold_mode,
        safe_aqi_threshold = :safe_aqi_threshold,
        aqi_good_max = :aqi_good_max,
        aqi_moderate_max = :aqi_moderate_max,
        aqi_poor_max = :aqi_poor_max,
        aqi_bad_max = :aqi_bad_max,
        aqi_very_bad_max = :aqi_very_bad_max
    WHERE id = 1
");

$stmt->execute([
    ":send_interval_sec" => $send_interval_sec,
    ":retention_days" => $retention_days,
    ":threshold_mode" => $threshold_mode,
    ":safe_aqi_threshold" => $safe_aqi_threshold,
    ":aqi_good_max" => $aqi_good_max,
    ":aqi_moderate_max" => $aqi_moderate_max,
    ":aqi_poor_max" => $aqi_poor_max,
    ":aqi_bad_max" => $aqi_bad_max,
    ":aqi_very_bad_max" => $aqi_very_bad_max
]);


json_response([
    "success" => true,
    "message" => "Đã lưu cài đặt thành công",

    "settings" => [
        "send_interval_sec" => $send_interval_sec,
        "retention_days" => $retention_days,
        "threshold_mode" => $threshold_mode,
        "safe_aqi_threshold" => $safe_aqi_threshold,
        "aqi_good_max" => $aqi_good_max,
        "aqi_moderate_max" => $aqi_moderate_max,
        "aqi_poor_max" => $aqi_poor_max,
        "aqi_bad_max" => $aqi_bad_max,
        "aqi_very_bad_max" => $aqi_very_bad_max
    ]
]);
?>