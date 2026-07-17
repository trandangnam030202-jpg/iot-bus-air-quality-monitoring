<?php
session_start();

require_once __DIR__ . "/../includes/config.php";
require_once __DIR__ . "/../includes/helpers.php";

$data = get_request_json();

$action = $data["action"] ?? ($_GET["action"] ?? "status");

if ($action === "status") {
    json_response([
        "success" => true,
        "authenticated" => isset($_SESSION["admin_authenticated"])
            && $_SESSION["admin_authenticated"] === true
    ]);
}

if ($action === "login") {
    $password = $data["password"] ?? "";

    if (hash_equals(ADMIN_PASSWORD, $password)) {
        session_regenerate_id(true);

        $_SESSION["admin_authenticated"] = true;
        $_SESSION["admin_login_time"] = time();

        json_response([
            "success" => true,
            "authenticated" => true,
            "message" => "Đăng nhập quản trị thành công"
        ]);
    }

    json_response([
        "success" => false,
        "authenticated" => false,
        "message" => "Sai mật khẩu quản trị"
    ], 401);
}

if ($action === "logout") {
    $_SESSION = [];

    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            "",
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }

    session_destroy();

    json_response([
        "success" => true,
        "authenticated" => false,
        "message" => "Đã khóa quyền quản trị"
    ]);
}

json_response([
    "success" => false,
    "message" => "Action không hợp lệ"
], 400);
?>