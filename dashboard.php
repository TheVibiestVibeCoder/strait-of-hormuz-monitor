<?php
require_once __DIR__ . '/config.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hormuz Oil Flow Radar</title>
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
    background: rgba(9, 9, 9, 0.9);
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
    background: rgba(9, 9, 9, 0.9);
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
#radar {
    width: 100%;
    height: auto;
    border: 1px solid #171717;
    background: #010101;
    display: block;
}
.feed {
    overflow: auto;
    max-height: 560px;
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
@media (max-width: 1050px) {
    .grid {
        grid-template-columns: repeat(3, minmax(100px, 1fr));
    }
    .layout {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 640px) {
    .grid {
        grid-template-columns: repeat(2, minmax(100px, 1fr));
    }
    th, td {
        font-size: 11px;
        padding: 7px 8px;
    }
}
</style>
</head>
<body>
<div class="container">
    <div class="header">
        <div>
            <div class="title">HORMUZ OIL FLOW RADAR</div>
            <div class="sub">Near-real-time tanker positions and flow in the Strait of Hormuz</div>
        </div>
        <div class="sub">
            <div><span class="status-dot" id="statusDot"></span><span id="statusText">loading...</span></div>
            <div id="updatedAt">last update: -</div>
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
                <div>Radar map (monitoring box: <?= BBOX_SW_LAT ?>,<?= BBOX_SW_LON ?> to <?= BBOX_NE_LAT ?>,<?= BBOX_NE_LON ?>)</div>
                <div class="legend">
                    <span><i style="background:var(--outbound)"></i>Outbound</span>
                    <span><i style="background:var(--inbound)"></i>Inbound</span>
                    <span><i style="background:var(--anchored)"></i>Anchored</span>
                    <span><i style="background:var(--unknown)"></i>Unknown</span>
                </div>
            </div>
            <div class="map-wrap">
                <canvas id="radar" width="940" height="520"></canvas>
            </div>
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
        Refresh interval: <?= DASHBOARD_REFRESH_SECONDS ?>s | API: <code>/api.php</code> | UI is intentionally lightweight (no Leaflet, no chart libs)
    </div>
</div>

<script>
const API_URL = 'api.php';
const REFRESH_SECONDS = <?= DASHBOARD_REFRESH_SECONDS ?>;

const BBOX = {
    swLat: <?= BBOX_SW_LAT ?>,
    swLon: <?= BBOX_SW_LON ?>,
    neLat: <?= BBOX_NE_LAT ?>,
    neLon: <?= BBOX_NE_LON ?>,
};

const VIEW = {
    minLat: BBOX.swLat - 0.6,
    maxLat: BBOX.neLat + 0.6,
    minLon: BBOX.swLon - 1.0,
    maxLon: BBOX.neLon + 1.0,
};

const DIR_COLOR = {
    OUTBOUND: '#00d06f',
    INBOUND: '#3aa1ff',
    ANCHORED: '#ffc04d',
    UNKNOWN: '#6d6d6d',
};

const radar = document.getElementById('radar');
const ctx = radar.getContext('2d');

function toXY(lat, lon) {
    const x = ((lon - VIEW.minLon) / (VIEW.maxLon - VIEW.minLon)) * radar.width;
    const y = ((VIEW.maxLat - lat) / (VIEW.maxLat - VIEW.minLat)) * radar.height;
    return [x, y];
}

function drawBase() {
    ctx.clearRect(0, 0, radar.width, radar.height);
    ctx.fillStyle = '#010101';
    ctx.fillRect(0, 0, radar.width, radar.height);

    ctx.strokeStyle = 'rgba(255,255,255,0.08)';
    ctx.lineWidth = 1;
    const cols = 10;
    const rows = 6;

    for (let i = 1; i < cols; i += 1) {
        const x = (radar.width / cols) * i;
        ctx.beginPath();
        ctx.moveTo(x, 0);
        ctx.lineTo(x, radar.height);
        ctx.stroke();
    }
    for (let j = 1; j < rows; j += 1) {
        const y = (radar.height / rows) * j;
        ctx.beginPath();
        ctx.moveTo(0, y);
        ctx.lineTo(radar.width, y);
        ctx.stroke();
    }

    // Monitoring box.
    const [x1, y1] = toXY(BBOX.neLat, BBOX.swLon);
    const [x2, y2] = toXY(BBOX.swLat, BBOX.neLon);
    ctx.strokeStyle = 'rgba(255,255,255,0.45)';
    ctx.setLineDash([6, 6]);
    ctx.strokeRect(x1, y1, x2 - x1, y2 - y1);
    ctx.setLineDash([]);

    // Approximate traffic lane through Hormuz for quick orientation.
    const lane = [
        [26.95, 55.25],
        [26.7, 55.65],
        [26.45, 56.1],
        [26.3, 56.65],
        [26.2, 57.2],
        [26.05, 57.75],
    ];
    ctx.strokeStyle = 'rgba(255,255,255,0.25)';
    ctx.lineWidth = 1.2;
    ctx.beginPath();
    lane.forEach((p, idx) => {
        const [x, y] = toXY(p[0], p[1]);
        if (idx === 0) ctx.moveTo(x, y);
        else ctx.lineTo(x, y);
    });
    ctx.stroke();
}

function drawVessels(vessels) {
    drawBase();

    vessels.forEach((v) => {
        if (!v.lat || !v.lon) return;
        const [x, y] = toXY(v.lat, v.lon);
        const color = DIR_COLOR[v.direction] || DIR_COLOR.UNKNOWN;

        ctx.beginPath();
        ctx.fillStyle = color;
        ctx.arc(x, y, 3.8, 0, Math.PI * 2);
        ctx.fill();

        // Heading vector.
        if (v.direction !== 'ANCHORED') {
            const headingRad = ((v.cog || 0) - 90) * (Math.PI / 180);
            const vx = x + Math.cos(headingRad) * 9;
            const vy = y + Math.sin(headingRad) * 9;
            ctx.strokeStyle = color;
            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(x, y);
            ctx.lineTo(vx, vy);
            ctx.stroke();
        }
    });
}

function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = String(value);
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

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/\"/g, '&quot;')
        .replace(/'/g, '&#39;');
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
        return `<tr>
            <td title="${safeName} (${escapeHtml(v.mmsi)})">${label}</td>
            <td class="${dirClass(v.direction)}">${shortDir(v.direction)}</td>
            <td>${Number(v.sog || 0).toFixed(1)}</td>
            <td>${formatAgo(v.seen_at)}</td>
        </tr>`;
    });

    body.innerHTML = rows.join('');
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

        drawVessels(vessels);
        renderFeed(vessels);
        renderStatus(data.health || {});
    } catch (err) {
        const text = document.getElementById('statusText');
        const dot = document.getElementById('statusDot');
        if (dot) dot.style.background = '#ff5252';
        if (text) text.textContent = 'api error: ' + err.message;
    }
}

drawBase();
refresh();
setInterval(refresh, REFRESH_SECONDS * 1000);
</script>
</body>
</html>
