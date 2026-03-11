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
    --bg: #f6f8fb;
    --panel: #ffffff;
    --line: #dbe2ea;
    --text: #111827;
    --muted: #6b7280;
    --outbound: #059669;
    --inbound: #2563eb;
    --anchored: #b45309;
    --unknown: #6b7280;
    --error: #dc2626;
    --term-bg: #0b1220;
    --term-text: #d7e3ff;
    --term-line: #1e293b;
}
* { box-sizing: border-box; }
html, body {
    margin: 0;
    background: linear-gradient(180deg, #f9fbfd 0%, #f2f5f9 100%);
    color: var(--text);
    font-family: "IBM Plex Mono", "SFMono-Regular", Menlo, Consolas, monospace;
}
.container {
    width: min(1220px, 94vw);
    margin: 0 auto;
    padding: 16px 0 30px;
}
.header {
    border: 1px solid var(--line);
    background: var(--panel);
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
    background: #f8fafc;
    color: var(--text);
    font-size: 12px;
    padding: 7px 10px;
    cursor: pointer;
}
.btn:hover {
    border-color: #9ca3af;
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
    background: var(--panel);
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
    background: var(--panel);
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
    border: 1px solid var(--line);
    background: #eef2f7;
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
    border-bottom: 1px solid #eef2f7;
    text-align: left;
    white-space: nowrap;
}
th {
    position: sticky;
    top: 0;
    background: #f8fafc;
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
    background: var(--panel);
    padding: 10px 12px;
    color: var(--muted);
    font-size: 12px;
}
.debug-wrap {
    margin-top: 8px;
    border: 1px solid var(--line);
    background: var(--panel);
}
.debug-wrap details {
    padding: 8px 12px 12px;
}
.debug-wrap summary {
    cursor: pointer;
    font-size: 12px;
    color: var(--text);
    font-weight: 600;
    user-select: none;
}
.debug-content {
    margin-top: 10px;
}
.debug-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(170px, 1fr));
    gap: 8px;
}
.debug-item {
    border: 1px solid var(--line);
    background: #f8fafc;
    padding: 8px;
}
.debug-item .k {
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
}
.debug-item .v {
    margin-top: 4px;
    font-size: 12px;
    word-break: break-word;
}
#debugHint {
    margin-top: 10px;
    font-size: 12px;
    padding: 8px;
    border: 1px solid var(--line);
    background: #f8fafc;
}
.debug-sections {
    margin-top: 10px;
    display: grid;
    grid-template-columns: 1.5fr 1fr;
    gap: 8px;
}
.debug-block {
    border: 1px solid var(--line);
    background: #f8fafc;
    padding: 8px;
}
.debug-block-title {
    font-size: 11px;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    margin-bottom: 6px;
}
.debug-terminal {
    margin: 0;
    background: var(--term-bg);
    color: var(--term-text);
    border: 1px solid var(--term-line);
    padding: 10px;
    font-size: 11px;
    overflow: auto;
    max-height: 310px;
    white-space: pre-wrap;
    line-height: 1.45;
}
.debug-list {
    margin: 0;
    padding-left: 16px;
    color: #111827;
    font-size: 12px;
}
.debug-list li {
    margin-bottom: 5px;
}
#debugJson {
    margin: 10px 0 0;
    background: #f8fafc;
    border: 1px solid var(--line);
    padding: 10px;
    font-size: 11px;
    overflow: auto;
    max-height: 260px;
    white-space: pre;
}
.leaflet-popup-content-wrapper {
    border: 1px solid #d1d5db !important;
}
.leaflet-control-zoom a {
    color: #334155 !important;
}
@media (max-width: 1050px) {
    .grid { grid-template-columns: repeat(3, minmax(100px, 1fr)); }
    .layout { grid-template-columns: 1fr; }
    #hormuz-map { height: 450px; }
    .debug-grid { grid-template-columns: repeat(2, minmax(140px, 1fr)); }
    .debug-sections { grid-template-columns: 1fr; }
}
@media (max-width: 640px) {
    .grid { grid-template-columns: repeat(2, minmax(100px, 1fr)); }
    th, td { font-size: 11px; padding: 7px 8px; }
    #hormuz-map { height: 380px; }
    .debug-grid { grid-template-columns: 1fr; }
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
            <button class="btn" id="manualRefresh">Run Collector Now</button>
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

    <div class="debug-wrap">
        <details id="debugPanel">
            <summary>Debug (API and Collector Diagnostics)</summary>
            <div class="debug-content">
                <div class="debug-grid">
                    <div class="debug-item"><div class="k">API HTTP</div><div class="v" id="dHttp">-</div></div>
                    <div class="debug-item"><div class="k">API latency</div><div class="v" id="dLatency">-</div></div>
                    <div class="debug-item"><div class="k">Generated at</div><div class="v" id="dGenerated">-</div></div>
                    <div class="debug-item"><div class="k">Issue code</div><div class="v" id="dIssue">-</div></div>
                    <div class="debug-item"><div class="k">API key configured</div><div class="v" id="dKey">-</div></div>
                    <div class="debug-item"><div class="k">Collector status</div><div class="v" id="dCollectorStatus">-</div></div>
                    <div class="debug-item"><div class="k">Collector delay</div><div class="v" id="dDelay">-</div></div>
                    <div class="debug-item"><div class="k">Last collector error</div><div class="v" id="dError">-</div></div>
                    <div class="debug-item"><div class="k">State file</div><div class="v" id="dState">-</div></div>
                    <div class="debug-item"><div class="k">WebSocket connected</div><div class="v" id="dWs">-</div></div>
                    <div class="debug-item"><div class="k">Subscription sent</div><div class="v" id="dSub">-</div></div>
                    <div class="debug-item"><div class="k">Messages seen</div><div class="v" id="dSeen">-</div></div>
                    <div class="debug-item"><div class="k">Skipped non-tanker</div><div class="v" id="dSkipType">-</div></div>
                    <div class="debug-item"><div class="k">Type backfill hits</div><div class="v" id="dBackfill">-</div></div>
                    <div class="debug-item"><div class="k">Rows: vessel_latest</div><div class="v" id="dRowsLatest">-</div></div>
                    <div class="debug-item"><div class="k">Rows: sightings</div><div class="v" id="dRowsSightings">-</div></div>
                    <div class="debug-item"><div class="k">State age</div><div class="v" id="dStateAge">-</div></div>
                    <div class="debug-item"><div class="k">PHP SAPI</div><div class="v" id="dSapi">-</div></div>
                    <div class="debug-item"><div class="k">TLS verify peer</div><div class="v" id="dTls">-</div></div>
                    <div class="debug-item"><div class="k">TCP 443 probe</div><div class="v" id="dTcp">-</div></div>
                </div>
                <div id="debugHint">Hint: -</div>
                <div class="debug-sections">
                    <div class="debug-block">
                        <div class="debug-block-title">Live Debug Terminal</div>
                        <pre id="debugTerminal" class="debug-terminal">Waiting for first payload...</pre>
                    </div>
                    <div class="debug-block">
                        <div class="debug-block-title">Recommended Actions</div>
                        <ul id="debugActions" class="debug-list">
                            <li>Waiting for diagnostics...</li>
                        </ul>
                        <div class="debug-block-title" style="margin-top:10px">Filesystem Checks</div>
                        <pre id="debugFiles" class="debug-terminal" style="max-height:160px">Waiting for diagnostics...</pre>
                    </div>
                </div>
                <div class="debug-block" style="margin-top:8px">
                    <div class="debug-block-title">Collector Log Tail</div>
                    <pre id="debugLogTail" class="debug-terminal" style="max-height:220px">No log lines yet.</pre>
                </div>
                <pre id="debugJson">No debug payload yet.</pre>
            </div>
        </details>
    </div>

    <div class="foot">
        Auto refresh: <?= DASHBOARD_REFRESH_SECONDS ?>s | Manual run button runtime: <?= MANUAL_WEB_RUNTIME ?>s | API: <code>/api.php?debug=1</code>
    </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
const API_URL = 'api.php';
const COLLECTOR_URL = 'collector.php';
const REFRESH_SECONDS = <?= DASHBOARD_REFRESH_SECONDS ?>;
const MANUAL_RUNTIME_SECONDS = <?= MANUAL_WEB_RUNTIME ?>;
const MANUAL_TRIGGER_ENABLED = <?= ALLOW_WEB_MANUAL_TRIGGER ? 'true' : 'false' ?>;

let lastManualRun = null;
let manualRunInProgress = false;

const BBOX = {
    swLat: <?= BBOX_SW_LAT ?>,
    swLon: <?= BBOX_SW_LON ?>,
    neLat: <?= BBOX_NE_LAT ?>,
    neLon: <?= BBOX_NE_LON ?>,
};

const COLOR = {
    OUTBOUND: '#059669',
    INBOUND: '#2563eb',
    ANCHORED: '#b45309',
    UNKNOWN: '#6b7280',
};

const map = L.map('hormuz-map', { zoomControl: true }).setView([26.35, 56.4], 8);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '&copy; OpenStreetMap',
    maxZoom: 18,
}).addTo(map);

L.rectangle([[BBOX.swLat, BBOX.swLon], [BBOX.neLat, BBOX.neLon]], {
    color: '#1f2937',
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
        html: `<div style="transform:rotate(${Number(cog || 0)}deg);width:24px;height:24px">\n                 <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">\n                   <circle cx="12" cy="12" r="10" fill="${c}" fill-opacity="0.2" stroke="${c}" stroke-width="1.5"/>\n                   ${arrow}\n                 </svg>\n               </div>`,
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

function setHtml(id, value) {
    const el = document.getElementById(id);
    if (el) {
        el.innerHTML = String(value);
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
            <div style="min-width:200px;color:#111827">
                <div style="font-size:13px;font-weight:700;margin-bottom:6px">${escapeHtml(v.name || 'UNKNOWN')}</div>
                <div style="font-size:11px;color:#6b7280;margin-bottom:8px">MMSI ${escapeHtml(v.mmsi)}</div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px;font-size:12px">
                    <div style="color:#6b7280">Direction</div><div style="color:${color}">${directionText}</div>
                    <div style="color:#6b7280">COG</div><div>${Number(v.cog || 0).toFixed(0)} deg</div>
                    <div style="color:#6b7280">SOG</div><div>${Number(v.sog || 0).toFixed(1)} kn</div>
                    <div style="color:#6b7280">Seen</div><div>${escapeHtml(v.seen_at || '-')}</div>
                </div>
            </div>
        `);

        marker.addTo(markersLayer);
    });
}

function renderStatus(health, diagnosis) {
    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');

    if (!dot || !text) return;

    if (health && health.collector_online) {
        dot.style.background = '#059669';
        text.textContent = 'collector online';
    } else {
        dot.style.background = '#dc2626';
        text.textContent = 'collector delayed';
    }

    if (diagnosis && diagnosis.issue_code && diagnosis.issue_code !== 'healthy' && diagnosis.issue_code !== 'no_active_tankers') {
        dot.style.background = '#dc2626';
        text.textContent = 'check debug';
    }

    const updated = document.getElementById('updatedAt');
    if (updated) {
        updated.textContent = 'last seen: ' + ((health && health.last_seen_at) ? health.last_seen_at : '-') + ' UTC';
    }
}

function boolText(value) {
    return value === true ? 'true' : value === false ? 'false' : '-';
}

function setList(id, items) {
    const el = document.getElementById(id);
    if (!el) return;

    if (!Array.isArray(items) || items.length === 0) {
        el.innerHTML = '<li>-</li>';
        return;
    }

    el.innerHTML = items.map((item) => `<li>${escapeHtml(item)}</li>`).join('');
}

function setManualButtonState(busy) {
    const btn = document.getElementById('manualRefresh');
    if (!btn) return;

    btn.disabled = busy;
    btn.textContent = busy
        ? `Collecting (${MANUAL_RUNTIME_SECONDS}s)...`
        : 'Run Collector Now';
}

async function runManualCollectorOnce() {
    if (!MANUAL_TRIGGER_ENABLED) {
        throw new Error('Manual web trigger is disabled in config (.env: ALLOW_WEB_MANUAL_TRIGGER=0).');
    }

    const response = await fetch(
        `${COLLECTOR_URL}?manual=1&runtime=${MANUAL_RUNTIME_SECONDS}&_=${Date.now()}`,
        {
            method: 'POST',
            cache: 'no-store',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'manual=1'
        }
    );

    const text = await response.text();
    lastManualRun = {
        at: new Date().toISOString(),
        ok: response.ok,
        status: response.status,
        output: (text || '').trim(),
    };

    if (!response.ok) {
        throw new Error(`Manual collector trigger failed (HTTP ${response.status})`);
    }
}

function renderDebug(data, httpStatus, latencyMs, fetchError) {
    const diagnosis = data && data.diagnosis ? data.diagnosis : {};
    const health = data && data.health ? data.health : {};
    const collectorState = data && data.collector_state ? data.collector_state : {};
    const debug = data && data.debug ? data.debug : {};
    const debugCollector = debug.collector || {};
    const debugDb = debug.database || {};
    const debugSystem = debug.system || {};
    const debugNet = debug.network || {};
    const files = debug.files || {};
    const logTail = debug.log_tail || {};

    setText('dHttp', fetchError ? 'error' : (String(httpStatus) + (httpStatus >= 200 && httpStatus < 300 ? ' OK' : '')));
    setText('dLatency', latencyMs === null ? '-' : latencyMs + ' ms');
    setText('dGenerated', data && data.generated_at ? data.generated_at : '-');
    setText('dIssue', diagnosis.issue_code || '-');
    setText('dKey', boolText(diagnosis.api_key_configured));
    setText('dCollectorStatus', diagnosis.collector_status || '-');
    setText('dDelay', health.collector_delay_seconds !== null && health.collector_delay_seconds !== undefined ? (health.collector_delay_seconds + ' s') : '-');
    setText('dError', diagnosis.collector_last_error || fetchError || '-');
    setText('dState', collectorState.available ? 'available' : (collectorState.status || 'missing'));
    setText('dWs', boolText(debugCollector.websocket_connected));
    setText('dSub', boolText(debugCollector.subscription_sent));
    setText('dSeen', debugCollector.seen_messages !== undefined ? String(debugCollector.seen_messages) : '-');
    setText('dSkipType', debugCollector.skipped_non_tanker !== undefined ? String(debugCollector.skipped_non_tanker) : '-');
    setText('dBackfill', debugCollector.type_backfill_hits !== undefined ? String(debugCollector.type_backfill_hits) : '-');
    setText('dRowsLatest', debugDb.rows_vessel_latest !== undefined ? String(debugDb.rows_vessel_latest) : '-');
    setText('dRowsSightings', debugDb.rows_tanker_sightings !== undefined ? String(debugDb.rows_tanker_sightings) : '-');
    setText('dStateAge', debugCollector.state_age_seconds !== null && debugCollector.state_age_seconds !== undefined ? (debugCollector.state_age_seconds + ' s') : '-');
    setText('dSapi', debugSystem.php_sapi || '-');
    setText('dTls', boolText(debugCollector.tls_verify_peer));
    const tcpProbe = debugNet.aisstream_tcp_443 || {};
    setText('dTcp', tcpProbe.checked ? ((tcpProbe.ok ? 'ok' : 'failed') + (tcpProbe.latency_ms !== undefined ? ` (${tcpProbe.latency_ms} ms)` : '')) : '-');

    const hint = fetchError
        ? ('Fetch error: ' + fetchError)
        : (diagnosis.hint || '-');
    setHtml('debugHint', 'Hint: ' + escapeHtml(hint));

    const terminalLines = Array.isArray(debug.terminal_lines) && debug.terminal_lines.length > 0
        ? debug.terminal_lines
        : [
            `[${new Date().toISOString()}] no terminal lines in payload`,
            'Check /api.php?debug=1 output.'
        ];

    if (lastManualRun) {
        terminalLines.push('--- Manual Trigger ---');
        terminalLines.push(
            `[${lastManualRun.at}] HTTP ${lastManualRun.status} ${lastManualRun.ok ? 'OK' : 'ERROR'}`
        );
        const lines = (lastManualRun.output || 'no output')
            .split(/\r\n|\r|\n/)
            .map((line) => line.trim())
            .filter((line) => line !== '')
            .slice(-10);
        if (lines.length === 0) {
            terminalLines.push('manual> no output');
        } else {
            lines.forEach((line) => terminalLines.push('manual> ' + line));
        }
    }

    setText('debugTerminal', terminalLines.join('\n'));

    setList('debugActions', Array.isArray(debug.recommended_actions) ? debug.recommended_actions : []);

    const fsLines = [];
    Object.keys(files).forEach((key) => {
        const p = files[key] || {};
        fsLines.push(`${key}: exists=${boolText(p.exists)} readable=${boolText(p.readable)} writable=${boolText(p.writable)} size=${p.size_bytes === null || p.size_bytes === undefined ? '-' : p.size_bytes} path=${p.path || '-'}`);
    });
    setText('debugFiles', fsLines.length > 0 ? fsLines.join('\n') : 'No filesystem diagnostics.');

    const logLines = Array.isArray(logTail.lines) ? logTail.lines : [];
    if (logLines.length > 0) {
        setText('debugLogTail', logLines.join('\n'));
    } else {
        setText('debugLogTail', logTail.message || 'No collector log lines yet.');
    }

    const payload = fetchError
        ? { fetch_error: fetchError }
        : {
            diagnosis: diagnosis,
            collector_state: collectorState,
            health: health,
            stats: data ? data.stats : null,
            debug: debug,
        };

    setText('debugJson', JSON.stringify(payload, null, 2));
}

async function refresh() {
    const t0 = performance.now();

    try {
        const response = await fetch(`${API_URL}?debug=1&_=${Date.now()}`, { cache: 'no-store' });
        const latency = Math.round(performance.now() - t0);

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
        renderStatus(data.health || {}, data.diagnosis || {});
        renderDebug(data, response.status, latency, null);
    } catch (err) {
        const msg = err && err.message ? err.message : String(err);
        renderDebug(null, null, null, msg);

        const text = document.getElementById('statusText');
        const dot = document.getElementById('statusDot');
        if (dot) dot.style.background = '#dc2626';
        if (text) text.textContent = 'api error';
    }
}

document.getElementById('manualRefresh').addEventListener('click', async () => {
    if (manualRunInProgress) {
        return;
    }

    manualRunInProgress = true;
    setManualButtonState(true);

    const dot = document.getElementById('statusDot');
    const text = document.getElementById('statusText');
    if (dot) dot.style.background = '#b45309';
    if (text) text.textContent = 'manual collect running';

    try {
        await runManualCollectorOnce();
    } catch (err) {
        const msg = err && err.message ? err.message : String(err);
        lastManualRun = {
            at: new Date().toISOString(),
            ok: false,
            status: 0,
            output: msg,
        };
    } finally {
        await refresh();
        setManualButtonState(false);
        manualRunInProgress = false;
    }
});
refresh();
setInterval(refresh, REFRESH_SECONDS * 1000);
</script>
</body>
</html>
