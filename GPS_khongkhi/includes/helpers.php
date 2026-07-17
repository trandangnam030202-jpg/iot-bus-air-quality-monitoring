<?php
function json_response($data, $code = 200) {
    http_response_code($code);
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function get_request_json() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (is_array($data)) {
        return $data;
    }

    return $_POST;
}

function value_or_null($arr, $key) {
    return isset($arr[$key]) && $arr[$key] !== "" ? $arr[$key] : null;
}

function get_app_settings($pdo) {
    $stmt = $pdo->query("SELECT * FROM app_settings WHERE id = 1 LIMIT 1");
    $settings = $stmt->fetch();

    if (!$settings) {
        $pdo->exec("INSERT INTO app_settings (id) VALUES (1)");
        $stmt = $pdo->query("SELECT * FROM app_settings WHERE id = 1 LIMIT 1");
        $settings = $stmt->fetch();
    }

    return $settings;
}

function is_error_value($value) {
    if ($value === null || $value === "") {
        return true;
    }

    if (floatval($value) == 404) {
        return true;
    }

    return false;
}

function calc_aqi_pm25($pm25) {
    if (is_error_value($pm25)) {
        return null;
    }

    $c = round(floatval($pm25), 1);

    $breakpoints = [
        [0.0,   9.0,   0,   50],
        [9.1,   35.4,  51,  100],
        [35.5,  55.4,  101, 150],
        [55.5,  125.4, 151, 200],
        [125.5, 225.4, 201, 300],
        [225.5, 325.4, 301, 500]
    ];

    foreach ($breakpoints as $bp) {
        [$clow, $chigh, $ilow, $ihigh] = $bp;

        if ($c >= $clow && $c <= $chigh) {
            return round((($ihigh - $ilow) / ($chigh - $clow)) * ($c - $clow) + $ilow);
        }
    }

    return 500;
}

function calc_co2_index($co2) {
    if (is_error_value($co2)) {
        return null;
    }

    $c = intval($co2);

    $breakpoints = [
        [0,    800,   0,   50],
        [801,  1000,  51,  100],
        [1001, 1500,  101, 150],
        [1501, 2000,  151, 200],
        [2001, 5000,  201, 300],
        [5001, 10000, 301, 500]
    ];

    foreach ($breakpoints as $bp) {
        [$clow, $chigh, $ilow, $ihigh] = $bp;

        if ($c >= $clow && $c <= $chigh) {
            return round((($ihigh - $ilow) / ($chigh - $clow)) * ($c - $clow) + $ilow);
        }
    }

    return 500;
}

function calc_air_index($pm25, $co2) {
    $aqi_pm25 = calc_aqi_pm25($pm25);
    $co2_index = calc_co2_index($co2);

    $values = [];

    if ($aqi_pm25 !== null) {
        $values[] = $aqi_pm25;
    }

    if ($co2_index !== null) {
        $values[] = $co2_index;
    }

    if (count($values) == 0) {
        return null;
    }

    return max($values);
}

function air_level_from_aqi_auto($aqi) {
    if ($aqi === null) {
        return "Không xác định";
    }

    if ($aqi <= 50) return "Tốt";
    if ($aqi <= 100) return "Trung bình";
    if ($aqi <= 150) return "Kém";
    if ($aqi <= 200) return "Xấu";
    if ($aqi <= 300) return "Rất xấu";

    return "Nguy hại";
}

function color_from_aqi_auto($aqi) {
    if ($aqi === null) return "#777777";
    if ($aqi <= 50) return "#00A651";
    if ($aqi <= 100) return "#FFD400";
    if ($aqi <= 150) return "#FF7E00";
    if ($aqi <= 200) return "#FF0000";
    if ($aqi <= 300) return "#8F3F97";

    return "#7E0023";
}

function classify_air_quality($aqi, $settings) {
    $mode = $settings["threshold_mode"] ?? "auto";

    if ($mode === "custom" && $aqi !== null) {
        $good = intval($settings["aqi_good_max"]);
        $moderate = intval($settings["aqi_moderate_max"]);
        $poor = intval($settings["aqi_poor_max"]);
        $bad = intval($settings["aqi_bad_max"]);
        $very_bad = intval($settings["aqi_very_bad_max"]);

        if ($aqi <= $good) {
            return ["Tốt", "#00A651"];
        }

        if ($aqi <= $moderate) {
            return ["Trung bình", "#FFD400"];
        }

        if ($aqi <= $poor) {
            return ["Kém", "#FF7E00"];
        }

        if ($aqi <= $bad) {
            return ["Xấu", "#FF0000"];
        }

        if ($aqi <= $very_bad) {
            return ["Rất xấu", "#8F3F97"];
        }

        return ["Nguy hại", "#7E0023"];
    }

    return [
        air_level_from_aqi_auto($aqi),
        color_from_aqi_auto($aqi)
    ];
}

function check_alert($aqi, $settings) {
    if ($aqi === null) {
        return [0, null];
    }

    $safe = intval($settings["safe_aqi_threshold"] ?? 100);

    if ($aqi > $safe) {
        return [
            1,
            "Cảnh báo: Chỉ số không khí vượt ngưỡng an toàn " . $safe
        ];
    }

    return [0, null];
}

function validate_lat_lon($lat, $lon) {
    if ($lat === null || $lon === null) {
        return false;
    }

    $lat = floatval($lat);
    $lon = floatval($lon);

    if ($lat < -90 || $lat > 90) return false;
    if ($lon < -180 || $lon > 180) return false;
    if ($lat == 0 && $lon == 0) return false;

    return true;
}
?>