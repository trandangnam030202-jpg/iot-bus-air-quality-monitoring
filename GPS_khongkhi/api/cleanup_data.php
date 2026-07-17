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

$action = $data["action"] ?? ($_GET["action"] ?? "");

$device_id = $data["device_id"] ?? ($_GET["device_id"] ?? "");

$settings = get_app_settings($pdo);


if ($action === "delete_old") {

    $days = intval(
        $settings["retention_days"] ?? 4
    );

    if ($days < 1) {
        $days = 4;
    }

    $sql = "
        DELETE FROM air_data
        WHERE created_at < DATE_SUB(
            NOW(),
            INTERVAL {$days} DAY
        )
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute();

    json_response([
        "success" => true,

        "message" =>
            "Đã xóa dữ liệu cũ hơn {$days} ngày",

        "deleted" => $stmt->rowCount()
    ]);
}


if ($action === "end_journey") {

    if ($device_id !== "") {

        $stmt = $pdo->prepare("
            DELETE FROM air_data
            WHERE device_id = :device_id
        ");

        $stmt->execute([
            ":device_id" => $device_id
        ]);

    } else {

        $stmt = $pdo->prepare("
            DELETE FROM air_data
        ");

        $stmt->execute();
    }


    json_response([
        "success" => true,

        "message" =>
            "Đã kết thúc hành trình và xóa dữ liệu bản đồ",

        "deleted" => $stmt->rowCount()
    ]);
}


json_response([
    "success" => false,
    "message" => "Action không hợp lệ"
], 400);
?>