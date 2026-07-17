let map = L.map("map").setView([21.0285, 105.8542], 13);

L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    maxZoom: 19,
    attribution: "&copy; OpenStreetMap contributors"
}).addTo(map);

let pointLayer = L.layerGroup().addTo(map);
let lineLayer = L.layerGroup().addTo(map);
let latestMarker = null;

function safeNumber(v, digits = 1) {
    if (v === null || v === undefined || v === "") {
        return "--";
    }

    const n = Number(v);

    if (Number.isNaN(n)) {
        return "--";
    }

    return n.toFixed(digits);
}

function popupHtml(p) {
    return `
        <div class="popup-title">${p.device_id} - ${p.created_at}</div>
        <div class="popup-row"><span>PM2.5</span><b>${p.pm25 ?? "--"} µg/m³</b></div>
        <div class="popup-row"><span>CO2</span><b>${p.co2 ?? "--"} ppm</b></div>
        <div class="popup-row"><span>Chỉ số</span><b>${p.aqi ?? "--"}</b></div>
        <div class="popup-row"><span>Mức</span><b>${p.air_level ?? "--"}</b></div>
        <div class="popup-row"><span>Nhiệt độ</span><b>${safeNumber(p.temperature)} °C</b></div>
        <div class="popup-row"><span>Độ ẩm</span><b>${safeNumber(p.humidity, 0)} %</b></div>
        <div class="popup-row"><span>Áp suất</span><b>${safeNumber(p.pressure, 0)} hPa</b></div>
        <div class="popup-row"><span>Cảnh báo</span><b>${Number(p.is_alert) === 1 ? p.alert_message : "OK"}</b></div>
        <div class="popup-row"><span>GPS</span><b>${Number(p.lat).toFixed(6)}, ${Number(p.lon).toFixed(6)}</b></div>
    `;
}

function tooltipHtml(p) {
    return `
        <div style="min-width:220px">
            <b>${p.device_id}</b><br>
            Thời gian: ${p.created_at}<br>
            PM2.5: <b>${p.pm25 ?? "--"} µg/m³</b><br>
            CO2: <b>${p.co2 ?? "--"} ppm</b><br>
            Chỉ số: <b>${p.aqi ?? "--"}</b><br>
            Mức: <b>${p.air_level ?? "--"}</b><br>
            ${Number(p.is_alert) === 1 ? "<b style='color:red'>" + p.alert_message + "</b><br>" : ""}
            Nhiệt độ: ${safeNumber(p.temperature)} °C<br>
            Độ ẩm: ${safeNumber(p.humidity, 0)} %
        </div>
    `;
}

function clearMapView() {
    pointLayer.clearLayers();
    lineLayer.clearLayers();
    latestMarker = null;

    document.getElementById("totalPoints").textContent = "0 điểm";
    document.getElementById("lastPm25").textContent = "--";
    document.getElementById("lastAqi").textContent = "--";
    document.getElementById("lastLevel").textContent = "--";
}

function drawMap(points) {
    pointLayer.clearLayers();
    lineLayer.clearLayers();
    latestMarker = null;

    document.getElementById("totalPoints").textContent = points.length + " điểm";

    if (!points.length) {
        clearMapView();
        return;
    }

    let bounds = [];
    let last = points[points.length - 1];

    document.getElementById("lastPm25").textContent = (last.pm25 ?? "--") + " µg/m³";
    document.getElementById("lastAqi").textContent = last.aqi ?? "--";
    document.getElementById("lastLevel").textContent = last.air_level ?? "--";

    for (let i = 0; i < points.length; i++) {
        const p = points[i];
        const lat = Number(p.lat);
        const lon = Number(p.lon);

        if (!lat || !lon) {
            continue;
        }

        const color = p.color || "#777777";
        const latlng = [lat, lon];

        bounds.push(latlng);

        const marker = L.circleMarker(latlng, {
            radius: i === points.length - 1 ? 8 : 6,
            color: color,
            fillColor: color,
            fillOpacity: 0.85,
            weight: Number(p.is_alert) === 1 ? 4 : 2
        });

        marker.bindPopup(popupHtml(p));

        marker.bindTooltip(tooltipHtml(p), {
            direction: "top",
            sticky: true,
            opacity: 0.95
        });

        marker.on("mouseover", function () {
            this.openTooltip();
        });

        marker.on("mouseout", function () {
            this.closeTooltip();
        });

        marker.addTo(pointLayer);

        if (i === points.length - 1) {
            latestMarker = marker;
        }

        if (i > 0) {
            const prev = points[i - 1];
            const prevLat = Number(prev.lat);
            const prevLon = Number(prev.lon);

            if (prevLat && prevLon) {
                L.polyline([[prevLat, prevLon], latlng], {
                    color: color,
                    weight: Number(p.is_alert) === 1 ? 8 : 6,
                    opacity: 0.75
                }).addTo(lineLayer);
            }
        }
    }

    if (bounds.length) {
        map.fitBounds(bounds, {
            padding: [40, 40]
        });
    }
}

async function loadData() {
    const deviceId = document.getElementById("deviceId").value.trim();
    const date = document.getElementById("filterDate").value;

    let url = API_GET_DATA + "?limit=1000";

    if (deviceId) {
        url += "&device_id=" + encodeURIComponent(deviceId);
    }

    if (date) {
        url += "&date=" + encodeURIComponent(date);
    }

    try {
        const res = await fetch(url + "&_=" + Date.now());
        const json = await res.json();

        if (!json.success) {
            alert("Không lấy được dữ liệu");
            return;
        }

        drawMap(json.data);
    } catch (err) {
        console.error(err);
        alert("Lỗi kết nối API get_data.php");
    }
}

document.getElementById("btnLoad").addEventListener("click", loadData);

document.getElementById("btnLatest").addEventListener("click", () => {
    if (latestMarker) {
        map.setView(latestMarker.getLatLng(), 17);
        latestMarker.openPopup();
    }
});

loadData();
setInterval(loadData, 30000);