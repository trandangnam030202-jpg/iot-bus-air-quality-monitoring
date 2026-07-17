<?php
require_once __DIR__ . "/includes/config.php";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Bản đồ chất lượng không khí xe buýt</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link
        rel="stylesheet"
        href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
    >

    <link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div>
            <h1>Bản đồ giám sát chất lượng không khí xe buýt</h1>
            <p>Hiển thị tuyến đường, vị trí GPS và màu theo PM2.5 + CO2</p>
        </div>

        <div class="controls">
            <input type="text" id="deviceId" value="BUS_01" placeholder="Mã xe">
            <input type="date" id="filterDate">
            <button id="btnLoad">Tải dữ liệu</button>
            <button id="btnLatest">Về điểm mới nhất</button>
            <a class="admin-link" href="admin/index.php">Trang admin</a>
        </div>
    </header>

    <section class="summary">
        <div class="card">
            <span>Dữ liệu</span>
            <strong id="totalPoints">0 điểm</strong>
        </div>

        <div class="card">
            <span>PM2.5 mới nhất</span>
            <strong id="lastPm25">--</strong>
        </div>

        <div class="card">
            <span>Chỉ số mới nhất</span>
            <strong id="lastAqi">--</strong>
        </div>

        <div class="card">
            <span>Mức chất lượng</span>
            <strong id="lastLevel">--</strong>
        </div>
    </section>

    <main class="map-wrap">
        <div id="map"></div>

        <div class="legend">
            <h3>Chú giải chỉ số</h3>
            <div><span style="background:#00A651"></span> Tốt 0-50</div>
            <div><span style="background:#FFD400"></span> Trung bình 51-100</div>
            <div><span style="background:#FF7E00"></span> Kém 101-150</div>
            <div><span style="background:#FF0000"></span> Xấu 151-200</div>
            <div><span style="background:#8F3F97"></span> Rất xấu 201-300</div>
            <div><span style="background:#7E0023"></span> Nguy hại >300</div>
        </div>
    </main>
</div>

<script>
const API_GET_DATA = "api/get_data.php";
</script>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="assets/js/app.js?v=4"></script>
</body>
</html>