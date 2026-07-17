<?php

require_once __DIR__ . "/../includes/db.php";
require_once __DIR__ . "/../includes/helpers.php";


function get_rows($pdo) {

    return $pdo->query("
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

            DATE_FORMAT(
                created_at,
                '%Y-%m-%d %H:%i:%s'
            ) AS created_at

        FROM air_data

        ORDER BY created_at DESC

        LIMIT 200
    ")->fetchAll();
}


if (isset($_GET["ajax"])) {

    header(
        "Content-Type: application/json; charset=utf-8"
    );

    echo json_encode([
        "success" => true,
        "data" => get_rows($pdo),
        "updated_at" => date("H:i:s")
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$settings = get_app_settings($pdo);

?>
<!DOCTYPE html>

<html lang="vi">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Admin - Dữ liệu không khí
    </title>


    <style>

        * {
            box-sizing: border-box;
        }


        body {

            font-family:
                Arial,
                Helvetica,
                sans-serif;

            background: #f5f7fb;

            margin: 20px;

            color: #1d2939;
        }


        h1 {

            color: #0b4f8a;

            margin-bottom: 6px;
        }


        .top-actions {

            display: flex;

            justify-content: space-between;

            align-items: center;

            margin-bottom: 14px;

            gap: 12px;

            flex-wrap: wrap;
        }


        .btn {

            display: inline-flex;

            align-items: center;

            justify-content: center;

            background: #0b4f8a;

            color: white;

            padding: 9px 14px;

            border-radius: 8px;

            text-decoration: none;

            font-weight: bold;

            border: none;

            cursor: pointer;

            min-height: 38px;
        }


        .btn:hover {

            opacity: 0.9;
        }


        .btn-danger {

            background: #d92d20;
        }


        .btn-warning {

            background: #f79009;
        }


        .btn-success {

            background: #079455;
        }


        .btn-dark {

            background: #344054;
        }


        .status {

            color: #667085;

            font-size: 14px;
        }


        .login-box {

            display: flex;

            align-items: center;

            gap: 8px;

            flex-wrap: wrap;

            padding: 14px;

            background: white;

            margin-bottom: 16px;

            border-radius: 12px;

            box-shadow:
                0 4px 16px
                rgba(0,0,0,0.08);
        }


        .login-box input {

            height: 38px;

            border:
                1px solid #d0d5dd;

            border-radius: 8px;

            padding: 0 12px;

            width: 250px;

            max-width: 100%;
        }


        .auth-status {

            font-weight: bold;

            margin-left: 5px;
        }


        .auth-locked {

            color: #d92d20;
        }


        .auth-unlocked {

            color: #079455;
        }


        .setting-box {

            background: white;

            padding: 16px;

            border-radius: 12px;

            margin-bottom: 16px;

            box-shadow:
                0 4px 16px
                rgba(0,0,0,0.08);

            position: relative;
        }


        .setting-box h2 {

            margin-top: 0;

            color: #0b4f8a;

            font-size: 18px;
        }


        .setting-grid {

            display: grid;

            grid-template-columns:
                repeat(4, 1fr);

            gap: 12px;
        }


        .field label {

            display: block;

            font-size: 13px;

            color: #667085;

            margin-bottom: 4px;
        }


        .field input,
        .field select {

            width: 100%;

            height: 36px;

            border:
                1px solid #d0d5dd;

            border-radius: 8px;

            padding: 0 10px;

            background: white;
        }


        .field input:disabled,
        .field select:disabled {

            background: #eaecf0;

            color: #667085;

            cursor: not-allowed;
        }


        .locked-message {

            margin-top: 12px;

            padding: 10px;

            border-radius: 8px;

            background: #fff1f0;

            color: #b42318;

            font-weight: bold;
        }


        .unlocked-message {

            background: #ecfdf3;

            color: #027a48;
        }


        .table-wrap {

            width: 100%;

            overflow-x: auto;
        }


        table {

            width: 100%;

            min-width: 1200px;

            border-collapse: collapse;

            background: white;

            box-shadow:
                0 4px 16px
                rgba(0,0,0,0.08);
        }


        th,
        td {

            border:
                1px solid #ddd;

            padding: 8px;

            font-size: 13px;

            text-align: center;
        }


        th {

            background: #0b4f8a;

            color: white;

            position: sticky;

            top: 0;

            z-index: 2;
        }


        tbody tr:nth-child(even) {

            background: #f8fafc;
        }


        .badge {

            color: white;

            padding: 4px 8px;

            border-radius: 12px;

            font-weight: bold;

            display: inline-block;

            min-width: 70px;
        }


        .alert-row {

            background: #fff1f0 !important;
        }


        @media (max-width: 1000px) {

            .setting-grid {

                grid-template-columns:
                    repeat(2, 1fr);
            }
        }


        @media (max-width: 600px) {

            body {

                margin: 10px;
            }


            .setting-grid {

                grid-template-columns: 1fr;
            }


            .login-box {

                align-items: stretch;
            }


            .login-box input {

                width: 100%;
            }
        }

    </style>

</head>


<body>


<h1>
    Admin - Dữ liệu GPS và chất lượng không khí
</h1>


<div class="top-actions">

    <a
        class="btn"
        href="../index.php"
    >
        Quay lại bản đồ
    </a>


    <div>

        <button
            id="btnDeleteOld"
            class="btn btn-warning"
            onclick="deleteOldData()"
            disabled
        >
            Xóa dữ liệu cũ
        </button>


        <button
            id="btnEndJourney"
            class="btn btn-danger"
            onclick="endJourney()"
            disabled
        >
            Kết thúc hành trình
        </button>

    </div>


    <div class="status">

        Tự cập nhật mỗi 5 giây |

        Lần cập nhật:

        <b id="lastUpdate">
            --:--:--
        </b>

    </div>

</div>



<div class="login-box">

    <input
        type="password"
        id="adminPassword"
        placeholder="Nhập mật khẩu quản trị"
        autocomplete="current-password"
    >


    <button
        class="btn btn-success"
        onclick="loginAdmin()"
    >
        Mở khóa cài đặt
    </button>


    <button
        class="btn btn-dark"
        onclick="logoutAdmin()"
    >
        Khóa lại
    </button>


    <span
        id="authStatus"
        class="auth-status auth-locked"
    >
        Cài đặt đang bị khóa
    </span>

</div>



<div class="setting-box">

    <h2>
        Cài đặt hệ thống
    </h2>


    <div class="setting-grid">


        <div class="field">

            <label>
                Mã xe / thiết bị
            </label>

            <input
                type="text"
                id="deviceIdSetting"
                value="BUS_01"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Chu kỳ gửi dữ liệu (giây)
            </label>

            <input
                type="number"
                id="send_interval_sec"
                value="<?= htmlspecialchars(
                    $settings["send_interval_sec"]
                ) ?>"
                class="setting-control"
                min="5"
                max="86400"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Số ngày lưu dữ liệu
            </label>

            <input
                type="number"
                id="retention_days"
                value="<?= htmlspecialchars(
                    $settings["retention_days"]
                ) ?>"
                class="setting-control"
                min="1"
                max="30"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Chế độ mức không khí
            </label>

            <select
                id="threshold_mode"
                class="setting-control"
                disabled
            >

                <option
                    value="auto"
                    <?= $settings["threshold_mode"] === "auto"
                        ? "selected"
                        : "" ?>
                >
                    Auto theo AQI / CO2 Index
                </option>


                <option
                    value="custom"
                    <?= $settings["threshold_mode"] === "custom"
                        ? "selected"
                        : "" ?>
                >
                    Tùy chỉnh
                </option>

            </select>

        </div>


        <div class="field">

            <label>
                Ngưỡng cảnh báo chỉ số
            </label>

            <input
                type="number"
                id="safe_aqi_threshold"
                value="<?= htmlspecialchars(
                    $settings["safe_aqi_threshold"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Tốt đến chỉ số
            </label>

            <input
                type="number"
                id="aqi_good_max"
                value="<?= htmlspecialchars(
                    $settings["aqi_good_max"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Trung bình đến chỉ số
            </label>

            <input
                type="number"
                id="aqi_moderate_max"
                value="<?= htmlspecialchars(
                    $settings["aqi_moderate_max"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Kém đến chỉ số
            </label>

            <input
                type="number"
                id="aqi_poor_max"
                value="<?= htmlspecialchars(
                    $settings["aqi_poor_max"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Xấu đến chỉ số
            </label>

            <input
                type="number"
                id="aqi_bad_max"
                value="<?= htmlspecialchars(
                    $settings["aqi_bad_max"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>


        <div class="field">

            <label>
                Rất xấu đến chỉ số
            </label>

            <input
                type="number"
                id="aqi_very_bad_max"
                value="<?= htmlspecialchars(
                    $settings["aqi_very_bad_max"]
                ) ?>"
                class="setting-control"
                disabled
            >

        </div>

    </div>


    <br>


    <button
        id="btnSaveSettings"
        class="btn"
        onclick="saveSettings()"
        disabled
    >
        Lưu cài đặt
    </button>


    <div
        id="lockMessage"
        class="locked-message"
    >
        Hãy nhập đúng mật khẩu quản trị để thay đổi thông số.
    </div>

</div>



<div class="table-wrap">

<table>

    <thead>

    <tr>

        <th>ID</th>

        <th>Xe</th>

        <th>Thời gian</th>

        <th>Nhiệt độ</th>

        <th>Độ ẩm</th>

        <th>Áp suất</th>

        <th>CO2</th>

        <th>PM2.5</th>

        <th>Chỉ số</th>

        <th>Mức</th>

        <th>Cảnh báo</th>

        <th>Lat</th>

        <th>Lon</th>

    </tr>

    </thead>


    <tbody id="dataBody">

    </tbody>

</table>

</div>



<script>

let isAdminAuthenticated = false;


function escapeHtml(text) {

    if (
        text === null ||
        text === undefined
    ) {
        return "";
    }


    return String(text)

        .replaceAll("&", "&amp;")

        .replaceAll("<", "&lt;")

        .replaceAll(">", "&gt;")

        .replaceAll('"', "&quot;")

        .replaceAll("'", "&#039;");
}



function setSettingsLocked(locked) {

    isAdminAuthenticated = !locked;


    document
        .querySelectorAll(".setting-control")
        .forEach(element => {

            element.disabled = locked;

        });


    document.getElementById(
        "btnSaveSettings"
    ).disabled = locked;


    document.getElementById(
        "btnDeleteOld"
    ).disabled = locked;


    document.getElementById(
        "btnEndJourney"
    ).disabled = locked;


    const status =
        document.getElementById("authStatus");


    const message =
        document.getElementById("lockMessage");


    if (locked) {

        status.textContent =
            "Cài đặt đang bị khóa";

        status.className =
            "auth-status auth-locked";


        message.textContent =
            "Hãy nhập đúng mật khẩu quản trị để thay đổi thông số.";

        message.className =
            "locked-message";

    } else {

        status.textContent =
            "Đã mở khóa quyền quản trị";

        status.className =
            "auth-status auth-unlocked";


        message.textContent =
            "Bạn có thể thay đổi và lưu các thông số hệ thống.";

        message.className =
            "locked-message unlocked-message";
    }
}



async function checkAuthStatus() {

    try {

        const res = await fetch(
            "../api/admin_auth.php?action=status&_="
            + Date.now(),
            {
                credentials: "same-origin"
            }
        );


        const json = await res.json();


        setSettingsLocked(
            !json.authenticated
        );

    } catch (e) {

        console.error(e);

        setSettingsLocked(true);
    }
}



async function loginAdmin() {

    const password =
        document
            .getElementById("adminPassword")
            .value;


    if (!password) {

        alert(
            "Vui lòng nhập mật khẩu quản trị"
        );

        return;
    }


    try {

        const res = await fetch(
            "../api/admin_auth.php",
            {

                method: "POST",

                credentials: "same-origin",

                headers: {

                    "Content-Type":
                        "application/json"

                },

                body: JSON.stringify({

                    action: "login",

                    password: password

                })
            }
        );


        const json = await res.json();


        if (!res.ok || !json.success) {

            setSettingsLocked(true);

            alert(
                json.message ||
                "Sai mật khẩu"
            );

            return;
        }


        document
            .getElementById("adminPassword")
            .value = "";


        setSettingsLocked(false);


        alert(
            "Đăng nhập quản trị thành công"
        );

    } catch (e) {

        console.error(e);

        alert(
            "Không kết nối được máy chủ"
        );
    }
}



async function logoutAdmin() {

    try {

        const res = await fetch(
            "../api/admin_auth.php",
            {

                method: "POST",

                credentials: "same-origin",

                headers: {

                    "Content-Type":
                        "application/json"

                },

                body: JSON.stringify({

                    action: "logout"

                })
            }
        );


        await res.json();


        setSettingsLocked(true);


        alert(
            "Đã khóa quyền quản trị"
        );

    } catch (e) {

        console.error(e);

        setSettingsLocked(true);
    }
}



function renderRows(rows) {

    const tbody =
        document.getElementById("dataBody");


    if (!rows.length) {

        tbody.innerHTML = `

            <tr>

                <td colspan="13">

                    Chưa có dữ liệu

                </td>

            </tr>
        `;

        return;
    }


    let html = "";


    rows.forEach(r => {

        const isAlert =
            Number(r.is_alert) === 1;


        html += `

            <tr class="${
                isAlert
                    ? "alert-row"
                    : ""
            }">

                <td>
                    ${escapeHtml(r.id)}
                </td>

                <td>
                    ${escapeHtml(r.device_id)}
                </td>

                <td>
                    ${escapeHtml(r.created_at)}
                </td>

                <td>
                    ${escapeHtml(r.temperature)}
                </td>

                <td>
                    ${escapeHtml(r.humidity)}
                </td>

                <td>
                    ${escapeHtml(r.pressure)}
                </td>

                <td>
                    ${escapeHtml(r.co2)}
                </td>

                <td>
                    ${escapeHtml(r.pm25)}
                </td>

                <td>
                    ${escapeHtml(r.aqi)}
                </td>

                <td>

                    <span
                        class="badge"

                        style="
                            background:
                            ${escapeHtml(r.color)}
                        "
                    >

                        ${escapeHtml(
                            r.air_level
                        )}

                    </span>

                </td>

                <td>

                    ${
                        isAlert
                            ? escapeHtml(
                                r.alert_message
                              )
                            : "OK"
                    }

                </td>

                <td>
                    ${escapeHtml(r.lat)}
                </td>

                <td>
                    ${escapeHtml(r.lon)}
                </td>

            </tr>
        `;
    });


    tbody.innerHTML = html;
}



async function reloadAdminData() {

    try {

        const res = await fetch(
            "index.php?ajax=1&_="
            + Date.now()
        );


        const json = await res.json();


        if (json.success) {

            renderRows(json.data);


            document
                .getElementById(
                    "lastUpdate"
                )
                .textContent =
                    json.updated_at;
        }

    } catch (e) {

        console.log(
            "Admin reload error",
            e
        );
    }
}



async function saveSettings() {

    if (!isAdminAuthenticated) {

        alert(
            "Bạn chưa mở khóa quyền quản trị"
        );

        return;
    }


    const data = {

        send_interval_sec:
            document
                .getElementById(
                    "send_interval_sec"
                ).value,

        retention_days:
            document
                .getElementById(
                    "retention_days"
                ).value,

        threshold_mode:
            document
                .getElementById(
                    "threshold_mode"
                ).value,

        safe_aqi_threshold:
            document
                .getElementById(
                    "safe_aqi_threshold"
                ).value,

        aqi_good_max:
            document
                .getElementById(
                    "aqi_good_max"
                ).value,

        aqi_moderate_max:
            document
                .getElementById(
                    "aqi_moderate_max"
                ).value,

        aqi_poor_max:
            document
                .getElementById(
                    "aqi_poor_max"
                ).value,

        aqi_bad_max:
            document
                .getElementById(
                    "aqi_bad_max"
                ).value,

        aqi_very_bad_max:
            document
                .getElementById(
                    "aqi_very_bad_max"
                ).value
    };


    const res = await fetch(
        "../api/update_settings.php",
        {

            method: "POST",

            credentials: "same-origin",

            headers: {

                "Content-Type":
                    "application/json"

            },

            body: JSON.stringify(data)
        }
    );


    const json = await res.json();


    if (!res.ok) {

        alert(
            json.message ||
            "Không lưu được cài đặt"
        );

        if (res.status === 401) {

            setSettingsLocked(true);
        }

        return;
    }


    alert(
        json.message ||
        "Đã lưu cài đặt"
    );
}



async function deleteOldData() {

    if (!isAdminAuthenticated) {

        alert(
            "Bạn chưa mở khóa quyền quản trị"
        );

        return;
    }


    if (
        !confirm(
            "Xóa dữ liệu cũ theo số ngày lưu trữ đã cài đặt?"
        )
    ) {
        return;
    }


    const res = await fetch(
        "../api/cleanup_data.php",
        {

            method: "POST",

            credentials: "same-origin",

            headers: {

                "Content-Type":
                    "application/json"

            },

            body: JSON.stringify({

                action: "delete_old"

            })
        }
    );


    const json = await res.json();


    if (!res.ok) {

        alert(
            json.message ||
            "Không xóa được dữ liệu"
        );

        if (res.status === 401) {

            setSettingsLocked(true);
        }

        return;
    }


    alert(

        json.message +

        " | Số dòng xóa: " +

        json.deleted
    );


    reloadAdminData();
}



async function endJourney() {

    if (!isAdminAuthenticated) {

        alert(
            "Bạn chưa mở khóa quyền quản trị"
        );

        return;
    }


    const deviceId =
        document
            .getElementById(
                "deviceIdSetting"
            )
            .value
            .trim();


    if (
        !confirm(
            "Kết thúc hành trình và xóa toàn bộ dữ liệu bản đồ của "
            + deviceId +
            "?"
        )
    ) {
        return;
    }


    const res = await fetch(
        "../api/cleanup_data.php",
        {

            method: "POST",

            credentials: "same-origin",

            headers: {

                "Content-Type":
                    "application/json"

            },

            body: JSON.stringify({

                action: "end_journey",

                device_id: deviceId

            })
        }
    );


    const json = await res.json();


    if (!res.ok) {

        alert(
            json.message ||
            "Không thể kết thúc hành trình"
        );

        if (res.status === 401) {

            setSettingsLocked(true);
        }

        return;
    }


    alert(

        json.message +

        " | Số dòng xóa: " +

        json.deleted
    );


    reloadAdminData();
}



document
    .getElementById("adminPassword")
    .addEventListener(
        "keydown",
        function(event) {

            if (event.key === "Enter") {

                loginAdmin();
            }
        }
    );


setSettingsLocked(true);

checkAuthStatus();

reloadAdminData();

setInterval(
    reloadAdminData,
    5000
);

</script>


</body>

</html>