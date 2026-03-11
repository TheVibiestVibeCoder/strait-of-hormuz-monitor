<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hormuz Oil Flow Radar</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
:root {
    --bg: #020202;
    --panel: #090909;
    --line: #262626;
    --text: #f2f2f2;
    --muted: #8d8d8d;
    --outbound: #00d06f;
    --inbound: #3aa1ff;
    --anchored: #ffc04d;
    --unknown: #6d6d6d;
}
* { box-sizing: border-box; }
html, body {
    margin: 0;
    background: radial-gradient(circle at 40% 10%, #0f0f0f 0%, #020202 60%);
    color: var(--text);
    font-family: "IBM Plex Mono", "SFMono-Regular", Menlo, Consolas, monospace;
}
.container {
    width: min(1200px, 94vw);
    margin: 0 auto;
    padding: 16px 0 28px;
}
.header {
    border: 1px solid var(--line);
    background: rgba(9, 9, 9, 0.92);
    padding: 12px 14px;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    flex-wrap: wrap;
}
.title {
    font-size: clamp(18px, 2.2vw, 28px);
    letter-spacing: 1px;
}
.sub {
    color: var(--muted);
    font-size: 12px;
}
.actions {
    display: flex;
    align-items: center;
    gap: 10px;
}
.btn {
    border: 1px solid var(--line);
    background: #0f0f0f;
    color: var(--text);
    font-size: 12px;
    padding: 7px 10px;
    cursor: pointer;
}
.btn:hover {
    border-color: #4a4a4a;
}
.status-dot {
    width: 8px;
    height: 8px;
    display: inline-block;
    border-radius: 50%;
    margin-right: 6px;
    background: var(--outbound);
}
.grid {
    margin-top: 8px;
    display: grid;
    grid-template-columns: repeat(6, minmax(120px, 1fr));
    gap: 8px;
}
.card {
    border: 1px solid var(--line);
    background: rgba(9, 9, 9, 0.92);
    padding: 10px;
}
.card .k {
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
}
.card .v {
    margin-top: 4px;
    font-size: clamp(20px, 2.8vw, 34px);
    line-height: 1;
}
.layout {
    margin-top: 8px;
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 8px;
}
.panel {
    border: 1px solid var(--line);
    background: rgba(9, 9, 9, 0.95);
}
.panel-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
    color: var(--muted);
    gap: 8px;
    flex-wrap: wrap;
}
.legend {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.legend span {
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.legend i {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    display: inline-block;
}
.map-wrap {
    padding: 10px;
}
#hormuz-map {
    width: 100%;
    height: 540px;
    border: 1px solid #171717;
    background: #010101;
}
.feed {
    overflow: auto;
    max-height: 590px;
}
table {
    width: 100%;
    border-collapse: collapse;
}
th, td {
    font-size: 12px;
    padding: 8px 10px;
    border-bottom: 1px solid rgba(255, 255, 255, 0.05);
    text-align: left;
    white-space: nowrap;
}
th {
    position: sticky;
    top: 0;
    background: #0b0b0b;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    z-index: 1;
}
.dir-outbound { color: var(--outbound); }
.dir-inbound { color: var(--inbound); }
.dir-anchored { color: var(--anchored); }
.dir-unknown { color: var(--unknown); }
.foot {
    margin-top: 8px;
    border: 1px solid var(--line);
    padding: 10px 12px;
    color: var(--muted);
    font-size: 12px;
}
.leaflet-container { background: #060a0d !important; }
.leaflet-tile { filter: brightness(0.62) saturate(0.28) hue-rotate(185deg) invert(0.9); }
.leaflet-control-zoom a {
    background: #0c0c0c !important;
    color: #909090 !important;
    border-color: #222 !important;
}
.leaflet-control-zoom a:hover {
    color: #fff !important;
    border-color: #444 !important;
}
.leaflet-popup-content-wrapper {
    background: #0c0c0c !important;
    color: #ddd !important;
    border: 1px solid #2c2c2c !important;
}
.leaflet-popup-tip { background: #0c0c0c !important; }
.leaflet-control-attribution {
    background: rgba(0,0,0,0.6) !important;
    color: #666 !important;
    font-size: 10px !important;
}
@media (max-width: 1050px) {
    .grid { grid-template-columns: repeat(3, minmax(100px, 1fr)); }
    .layout { grid-template-columns: 1fr; }
    #hormuz-map { height: 450px; }
}
@media (max-width: 640px) {
    .grid { grid-template-columns: repeat(2, minmax(100px, 1fr)); }
    th, td { font-size: 11px; padding: 7px 8px; }
    #hormuz-map { height: 380px; }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <div class="title">HORMUZ OIL FLOW RADAR</div>
            <div class="sub">Tanker positions and flow in the Strait of Hormuz</div>
        </div>
        <div class="actions">
            <button class="btn" id="manualRefresh">Refresh now</button>
            <div class="sub">
                <div><span class="status-dot" id="statusDot"></span><span id="statusText">loading...</span></div>
                <div id="updatedAt">last update: -</div>
            </div>
        </div>
    </div>

    <div class="grid">
        <div class="card"><div class="k">Active tankers</div><div class="v" id="sActive">0</div></div>
        <div class="card"><div class="k">Outbound</div><div class="v" id="sOut">0</div></div>
        <div class="card"><div class="k">Inbound</div><div class="v" id="sIn">0</div></div>
        <div class="card"><div class="k">Anchored</div><div class="v" id="sAnc">0</div></div>
        <div class="card"><div class="k">Unique (24h)</div><div class="v" id="s24">0</div></div>
        <div class="card"><div class="k">Flow (1h)</div><div class="v" id="s1h">0</div></div>
    </div>

    <div class="layout">
        <div class="panel">
            <div class="panel-head">
                <div>Map with monitoring zone (<?= BBOX_SW_LAT ?>,<?= BBOX_SW_LON ?> to <?= BBOX_NE_LAT ?>,<?= BBOX_NE_LON ?>)</div>
                <div class="legend">
                    <span><i style="background:var(--outbound)"></i>Outbound</span>
                    <span><i style="background:var(--inbound)"></i>Inbound</span>
                    <span><i style="background:var(--anchored)"></i>Anchored</span>
                    <span><i style="background:var(--unknown)"></i>Unknown</span>
                </div>
            </div>
            <div class="map-wrap"><div id="hormuz-map"></div></div>
        </div>

        <div class="panel">
            <div class="panel-head">Latest tracked tankers</div>
            <div class="feed">
                <table>
                    <thead>
                    <tr>
                        <th>Vessel</th>
                        <th>Dir</th>
                        <th>kn</th>
                        <th>Seen</th>
                    </tr>
                    </thead>
                    <tbody id="feedBody">
                    <tr><td colspan="4">Waiting for data...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="foot">
        Auto refresh: <?= DASHBOARD_REFRESH_SECONDS ?>s | Manual refresh button enabled | API: <code>/api.php</code>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
const API_URL = 'api.php';
const REFRESH_SECONDS = <?= DASHBOARD_REFRESH_SECONDS ?>;

const BBOX = {
    swLat: <?= BBOX_SW_LAT ?>,
    swLon: <?= BBOX_SW_LON ?>,
    neLat: <?= BBOX_NE_LAT ?>,
    neLon: <?= BBOX_NE_LON ?>,
};

const COLOR = {
    OUTBOUND: '#00d06f',
    INBOUND: '#3aa1ff',
    ANCHORED: '#ffc04d',
    UNKNOWN: '#6d6d6d',
};

const map = L.map('hormuz-map', { zoomControl: true }).setView([26.35, 56.4], 8);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    maxZoom: 18,
}).addTo(map);

L.rectangle([[BBOX.swLat, BBOX.swLon], [BBOX.neLat, BBOX.neLon]], {
    color: 'rgba(255,255,255,0.65)',
    weight: 1,
    dashArray: '6 4',
    fillOpacity: 0,
}).addTo(map).bindTooltip('Monitoring Zone');

const markersLayer = L.layerGroup().addTo(map);

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function markerIcon(direction, cog) {
    const c = COLOR[direction] || COLOR.UNKNOWN;
    const arrow = direction !== 'ANCHORED'
        ? `<line x1="12" y1="12" x2="12" y2="3" stroke="${c}" stroke-width="2"/>\n           <polygon points="9,5 12,0 15,5" fill="${c}"/>`
        : `<circle cx="12" cy="12" r="3" fill="${c}"/>`;

    return L.divIcon({
        className: '',
        html: `<div style="transform:rotate(${Number(cog || 0)}deg);width:24px;height:24px">\n                 <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">\n                   <circle cx="12" cy="12" r="10" fill="${c}" fill-opacity="0.18" stroke="${c}" stroke-width="1.5"/>\n                   ${arrow}\n                 </svg>\n               </div>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
    });
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.textContent = String(value);
    }
}

function formatAgo(seenAt) {
    if (!seenAt) return '-';
    const ts = Date.parse(seenAt.replace(' ', 'T') + 'Z');
    if (Number.isNaN(ts)) return '-';
    const diff = Math.max(0, Math.floor((Date.now() - ts) / 1000));
    if (diff < 60) return diff + 's';
    if (diff < 3600) return Math.floor(diff / 60) + 'm';
    return Math.floor(diff / 3600) + 'h';
}

function dirClass(direction) {
    if (direction === 'OUTBOUND') return 'dir-outbound';
    if (direction === 'INBOUND') return 'dir-inbound';
    if (direction === 'ANCHORED') return 'dir-anchored';
    return 'dir-unknown';
}

function shortDir(direction) {
    if (direction === 'OUTBOUND') return 'OUT';
    if (direction === 'INBOUND') return 'IN';
    if (direction === 'ANCHORED') return 'ANC';
    return 'UNK';
}

function renderFeed(vessels) {
    const body = document.getElementById('feedBody');
    if (!body) return;

    if (!Array.isArray(vessels) || vessels.length === 0) {
        body.innerHTML = '<tr><td colspan="4">No active tankers in window.</td></tr>';
        return;
    }

    const rows = vessels.slice(0, 40).map((v) => {
        const safeName = escapeHtml(v.name || 'UNKNOWN');
        const label = safeName.length > 14 ? safeName.slice(0, 14) + '...' : safeName;
        return `<tr>\n            <td title="${safeName} (${escapeHtml(v.mmsi)})">${label}</td>\n            <td class="${dirClass(v.direction)}">${shortDir(v.direction)}</td>\n            <td>${Number(v.sog || 0).toFixed(1)}</td>\n            <td>${formatAgo(v.seen_at)}</td>\n        </tr>`;
    });

    body.innerHTML = rows.join('');
}

function renderMap(vessels) {
    markersLayer.clearLayers();

    if (!Array.isArray(vessels) || vessels.length === 0) {
        return;
    }

    vessels.forEach((v) => {
        if (!v.lat || !v.lon) return;

        const direction = v.direction || 'UNKNOWN';
        const marker = L.marker([v.lat, v.lon], {
            icon: markerIcon(direction, v.cog),
        });

        const color = COLOR[direction] || COLOR.UNKNOWN;
        const directionText = direction === 'OUTBOUND'
            ? 'Outbound'
            : direction === 'INBOUND'
                ? 'Inbound'
                : direction === 'ANCHORED'
                    ? 'Anchored'
                    : 'Unknown';

        marker.bindPopup(`
            <div style="min-width:200px">
                <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:6px">${escapeHtml(v.name || 'UNKNOWN')}</div>
                <div style="font-size:11px;color:#666;margin-bottom:8px">MMSI ${escapeHtml(v.mmsi)}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:12px">
                    <div style="color:#666">Direction</div><div style="color:${color}">${directionText}</div>
                    <div style="color:#666">COG</div><div>${Number(v.cog || 0).toFixed(0)} deg</div>
                    <div style="color:#666">SOG</div><div>${Number(v.sog || 0).toFixed(1)} kn</div>
                    <div style="color:#666">Seen</div><div>${escapeHtml(v.seen_at || '-')}</div>
                </div>
            </div>
        `);

        marker.addTo(markersLayer);
    });
}

function renderStatus(health) {
    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');

    if (!health || !dot || !text) return;

    if (health.collector_online) {
        dot.style.background = '#00d06f';
        text.textContent = 'collector online';
    } else {
        dot.style.background = '#ff5252';
        text.textContent = 'collector delayed';
    }

    const updated = document.getElementById('updatedAt');
    if (updated) {
        updated.textContent = 'last seen: ' + (health.last_seen_at || '-') + ' UTC';
    }
}

async function refresh() {
    try {
        const response = await fetch(`${API_URL}?_=${Date.now()}`, { cache: 'no-store' });
        if (!response.ok) {
            throw new Error('HTTP ' + response.status);
        }

        const data = await response.json();
        const stats = data.stats || {};
        const vessels = data.vessels || [];

        setText('sActive', stats.active_total || 0);
        setText('sOut', stats.active_outbound || 0);
        setText('sIn', stats.active_inbound || 0);
        setText('sAnc', stats.active_anchored || 0);
        setText('s24', stats.unique_24h || 0);
        setText('s1h', stats.flow_1h || 0);

        renderMap(vessels);
        renderFeed(vessels);
        renderStatus(data.health || {});
    } catch (err) {
        const text = document.getElementById('statusText');
        const dot = document.getElementById('statusDot');
        if (dot) dot.style.background = '#ff5252';
        if (text) text.textContent = 'api error: ' + err.message;
    }
}

document.getElementById('manualRefresh').addEventListener('click', refresh);
refresh();
setInterval(refresh, REFRESH_SECONDS * 1000);
</script>
</body>
</html>
