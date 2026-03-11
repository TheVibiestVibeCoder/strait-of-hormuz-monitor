<?php
/**
 * HORMUZ MONITOR — DASHBOARD v2
 * Leaflet Map + Historical Chart + Live Feed
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = get_db();

// ── Core Alert Status ──────────────────────────────────────────────────────────
$ais       = calculate_alert_level($db);
$ratio_pct = min(100, round($ais['ratio'] * 100));

$status_label = match(true) {
    $ais['level'] >= 2  => 'NORMALISIERUNG',
    $ais['level'] >= 1  => 'STEIGEND',
    $ais['level'] <= -1 => 'BLOCKADE',
    default             => 'MONITORING',
};
$status_color = match(true) {
    $ais['level'] >= 2  => '#44ff88',
    $ais['level'] >= 1  => '#ffaa00',
    $ais['level'] <= -1 => '#ff4444',
    default             => '#555555',
};

// ── Tanker Positionen für Karte (letzte 6h, nur unique MMSI) ──────────────────
$map_tankers = $db->query("
    SELECT t1.mmsi, t1.ship_name, t1.ship_type, t1.latitude, t1.longitude,
           t1.cog, t1.sog, t1.direction, t1.nav_status, t1.seen_at
    FROM tanker_sightings t1
    INNER JOIN (
        SELECT mmsi, MAX(seen_at) as max_seen
        FROM tanker_sightings
        WHERE seen_at > datetime('now', '-6 hours')
        GROUP BY mmsi
    ) t2 ON t1.mmsi = t2.mmsi AND t1.seen_at = t2.max_seen
    WHERE t1.latitude != 0 AND t1.longitude != 0
    ORDER BY t1.seen_at DESC
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// ── Historische Stats (letzte 7 Tage, stündlich) ─────────────────────────────
$history_7d = $db->query("
    SELECT hour_bucket, tanker_count, outbound_count, inbound_count, anchored_count
    FROM hourly_stats
    WHERE hour_bucket > datetime('now', '-7 days')
    ORDER BY hour_bucket ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Historische Stats (letzte 30 Tage, täglich) ───────────────────────────────
$history_30d = $db->query("
    SELECT
        date(hour_bucket) as day,
        SUM(tanker_count)   as total,
        SUM(outbound_count) as outbound,
        SUM(inbound_count)  as inbound,
        SUM(anchored_count) as anchored
    FROM hourly_stats
    WHERE hour_bucket > datetime('now', '-30 days')
    GROUP BY date(hour_bucket)
    ORDER BY day ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Letzte News (24h) ─────────────────────────────────────────────────────────
$news = $db->query("
    SELECT feed_name, title, link, keyword, alert_level, seen_at
    FROM news_items
    WHERE seen_at > datetime('now', '-24 hours')
    ORDER BY alert_level DESC, seen_at DESC LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// ── Live Feed (letzte 30 Sichtungen) ─────────────────────────────────────────
$live_feed = $db->query("
    SELECT ship_name, direction, cog, sog, seen_at
    FROM tanker_sightings
    WHERE ship_type BETWEEN 80 AND 89
    ORDER BY seen_at DESC LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// ── Gesendete Alerts ──────────────────────────────────────────────────────────
$sent_alerts = $db->query("
    SELECT alert_type, alert_level, message, sent_at
    FROM alerts_sent ORDER BY sent_at DESC LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── JS-Daten vorbereiten ──────────────────────────────────────────────────────
$map_json = json_encode(array_map(fn($t) => [
    'mmsi'      => $t['mmsi'],
    'name'      => $t['ship_name'] ?: 'UNKNOWN',
    'lat'       => (float)$t['latitude'],
    'lon'       => (float)$t['longitude'],
    'cog'       => (float)$t['cog'],
    'sog'       => (float)$t['sog'],
    'direction' => $t['direction'],
    'seen'      => $t['seen_at'],
], $map_tankers));

$chart_7d_labels = json_encode(array_map(fn($r) => date('d.m H:i', strtotime($r['hour_bucket'])), $history_7d));
$chart_7d_total  = json_encode(array_map(fn($r) => (int)$r['tanker_count'],   $history_7d));
$chart_7d_out    = json_encode(array_map(fn($r) => (int)$r['outbound_count'], $history_7d));
$chart_7d_anc    = json_encode(array_map(fn($r) => (int)$r['anchored_count'], $history_7d));

$chart_30d_labels = json_encode(array_map(fn($r) => date('d.m', strtotime($r['day'])), $history_30d));
$chart_30d_total  = json_encode(array_map(fn($r) => (int)$r['total'],    $history_30d));
$chart_30d_out    = json_encode(array_map(fn($r) => (int)$r['outbound'], $history_30d));

function time_ago(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)    return "{$diff}s";
    if ($diff < 3600)  return round($diff/60)  . "min";
    if ($diff < 86400) return round($diff/3600) . "h";
    return round($diff/86400) . "d";
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>HORMUZ MONITOR</title>
<link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Manrope:wght@300;400;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<style>
:root {
    --bg:     #050505;
    --card:   #0d0d0d;
    --text:   #f0f0f0;
    --muted:  #888;
    --dim:    #3a3a3a;
    --line:   rgba(255,255,255,0.07);
    --white:  #ffffff;
    --green:  #44ff88;
    --orange: #ff8800;
    --red:    #ff4444;
    --blue:   #4488ff;
    --fh:     'Bebas Neue', display;
    --fb:     'Manrope', sans-serif;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
html { scroll-behavior: smooth; }
body { background: var(--bg); color: var(--text); font-family: var(--fb); font-weight: 300; min-height: 100vh; }

/* NAV */
nav {
    position: sticky; top: 0; z-index: 1000;
    background: rgba(5,5,5,0.95); backdrop-filter: blur(16px);
    border-bottom: 1px solid var(--line); padding: 0.85rem 0;
}
.nav-inner {
    display: flex; justify-content: space-between; align-items: center;
    width: 92%; max-width: 1600px; margin: 0 auto; gap: 1rem;
}
.logo { font-family: var(--fh); font-size: clamp(1.1rem,1.8vw,1.5rem); letter-spacing: 3px; }
.nav-right { display: flex; align-items: center; gap: 1.5rem; flex-wrap: wrap; }
.nav-meta { font-size: 0.72rem; color: var(--muted); white-space: nowrap; }
.pulse-dot {
    display: inline-block; width: 7px; height: 7px; border-radius: 50%;
    background: var(--green); margin-right: 5px; animation: pulse 2s infinite;
}
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:0.25} }
.btn {
    font-family: var(--fh); font-size: 0.9rem; letter-spacing: 2px;
    border: 1px solid var(--dim); background: transparent; color: var(--muted);
    padding: 0.3rem 0.9rem; cursor: pointer; transition: all 0.25s; white-space: nowrap;
}
.btn:hover { border-color: var(--white); color: var(--white); }

/* LAYOUT */
.page { width: 92%; max-width: 1600px; margin: 0 auto; padding: 1.5rem 0 5rem; }

/* STATUS BANNER */
.status-banner {
    display: grid; grid-template-columns: auto 1fr auto;
    align-items: center; gap: 2rem;
    border: 1px solid var(--line); background: var(--card);
    padding: 1.8rem 2rem; margin-bottom: 1px;
}
@media(max-width:700px) { .status-banner { grid-template-columns: 1fr; gap: 0.8rem; } }
.status-main {
    font-family: var(--fh); font-size: clamp(3rem,8vw,6.5rem);
    line-height: 0.85; letter-spacing: 2px; color: <?= $status_color ?>;
}
.status-reason { font-size: 0.82rem; color: var(--muted); margin-top: 0.6rem; line-height: 1.5; }
.status-time { font-size: 0.7rem; color: var(--dim); text-align: right; white-space: nowrap; }

/* METRICS */
.metrics {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%,140px),1fr));
    gap: 1px; margin-bottom: 1px;
}
.metric {
    background: var(--card); border: 1px solid var(--line);
    padding: 1.3rem 1.2rem; transition: border-color 0.3s;
}
.metric:hover { border-color: rgba(255,255,255,0.18); }
.metric-val { font-family: var(--fh); font-size: clamp(2rem,4vw,3.2rem); line-height: 1; }
.metric-lbl { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 2px; color: var(--muted); margin-top: 0.3rem; }
.ratio-bar  { background: #1a1a1a; height: 3px; border-radius: 2px; margin-top: 0.6rem; }
.ratio-fill { height: 3px; border-radius: 2px; background: <?= $status_color ?>; width: <?= $ratio_pct ?>%; }

/* MAP + FEED */
.map-section {
    display: grid; grid-template-columns: 1fr 300px;
    gap: 1px; margin-bottom: 1px;
}
@media(max-width:1100px) { .map-section { grid-template-columns: 1fr; } }

.map-wrap { background: var(--card); border: 1px solid var(--line); }
.map-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.9rem 1.4rem; border-bottom: 1px solid var(--line); flex-wrap: wrap; gap: 0.5rem;
}
.panel-title { font-size: 0.62rem; text-transform: uppercase; letter-spacing: 2px; color: var(--muted); }
.map-legend { display: flex; gap: 1rem; font-size: 0.65rem; color: var(--muted); flex-wrap: wrap; }
.ld { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 3px; vertical-align: middle; }
#hormuz-map { width: 100%; height: 500px; }

.live-feed { background: var(--card); border: 1px solid var(--line); display: flex; flex-direction: column; }
.feed-header { padding: 0.9rem 1.4rem; border-bottom: 1px solid var(--line); flex-shrink: 0; }
.feed-scroll { overflow-y: auto; flex: 1; max-height: 500px; }
.feed-item {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 0.6rem 1.2rem; border-bottom: 1px solid rgba(255,255,255,0.04);
    transition: background 0.2s;
}
.feed-item:hover { background: rgba(255,255,255,0.02); }
.feed-name { font-size: 0.78rem; font-weight: 600; }
.feed-dir  { font-size: 0.65rem; font-weight: 600; margin-top: 2px; }
.feed-meta { font-size: 0.65rem; color: var(--dim); }
.feed-time { font-size: 0.65rem; color: var(--dim); white-space: nowrap; }
.d-out { color: var(--green); } .d-in { color: var(--blue); }
.d-anc { color: var(--red);  } .d-unk { color: var(--muted); }

/* CHARTS */
.charts-section {
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 1px; margin-bottom: 1px;
}
@media(max-width:900px) { .charts-section { grid-template-columns: 1fr; } }
.chart-panel { background: var(--card); border: 1px solid var(--line); padding: 1.3rem; }
.chart-hdr { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; }
.chart-wrap { position: relative; height: 220px; }

/* BOTTOM */
.bottom-section {
    display: grid; grid-template-columns: 1fr 340px;
    gap: 1px;
}
@media(max-width:1100px) { .bottom-section { grid-template-columns: 1fr; } }
.news-panel  { background: var(--card); border: 1px solid var(--line); padding: 1.3rem; }
.alerts-panel{ background: var(--card); border: 1px solid var(--line); padding: 1.3rem; }

.news-item { padding: 0.7rem 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
.news-item:last-child { border-bottom: none; }
.news-title a { font-size: 0.82rem; color: var(--text); text-decoration: none; line-height: 1.45; }
.news-title a:hover { color: var(--white); }
.news-meta { font-size: 0.65rem; color: var(--dim); margin-top: 0.2rem; }
.kw-high { color: var(--red); font-weight: 600; }
.kw-med  { color: var(--orange); }

.alert-item { padding: 0.6rem 0; border-bottom: 1px solid rgba(255,255,255,0.04); }
.alert-item:last-child { border-bottom: none; }
.badge {
    display: inline-block; font-size: 0.6rem; text-transform: uppercase; letter-spacing: 1px;
    padding: 0.15rem 0.5rem; border-radius: 2px; font-weight: 600; margin-right: 0.4rem;
}
.b3 { background: rgba(255,68,68,0.12); color:var(--red); border:1px solid rgba(255,68,68,0.25); }
.b2 { background: rgba(255,136,0,0.12); color:var(--orange); border:1px solid rgba(255,136,0,0.25); }
.b1 { background: rgba(68,136,255,0.12); color:var(--blue); border:1px solid rgba(68,136,255,0.25); }
.alert-msg  { font-size: 0.75rem; color: var(--muted); margin-top: 0.25rem; }
.alert-time { font-size: 0.63rem; color: var(--dim); }

.empty  { color: var(--dim); font-size: 0.78rem; padding: 1rem 0; }
.footer { text-align: center; color: var(--dim); font-size: 0.65rem; padding: 2rem 0 1rem; border-top: 1px solid var(--line); margin-top: 2rem; line-height: 2; }

/* Leaflet dark skin */
.leaflet-container { background: #080e14 !important; }
.leaflet-tile { filter: brightness(0.65) saturate(0.3) hue-rotate(185deg) invert(0.88); }
.leaflet-popup-content-wrapper {
    background: #111 !important; color: var(--text) !important;
    border: 1px solid rgba(255,255,255,0.1) !important; border-radius: 2px !important;
    font-family: var(--fb); font-size: 0.78rem; box-shadow: 0 4px 20px rgba(0,0,0,0.8) !important;
}
.leaflet-popup-tip { background: #111 !important; }
.leaflet-control-zoom a { background: #111 !important; color: #666 !important; border-color: #222 !important; }
.leaflet-control-zoom a:hover { color: #fff !important; border-color: #555 !important; }
.leaflet-control-attribution { background: rgba(0,0,0,0.7) !important; color: #333 !important; font-size: 9px !important; }
</style>
</head>
<body>

<nav>
  <div class="nav-inner">
    <div class="logo">HORMUZ MONITOR</div>
    <div class="nav-right">
      <div class="nav-meta"><span class="pulse-dot"></span>Live AIS + News · <?= date('d.m.Y H:i') ?></div>
      <button class="btn" onclick="location.reload()">↺ REFRESH</button>
    </div>
  </div>
</nav>

<div class="page">

  <!-- STATUS -->
  <div class="status-banner">
    <div class="status-main"><?= $status_label ?></div>
    <div><div class="status-reason"><?= htmlspecialchars($ais['reason'] ?: 'Kein Signal — Monitoring aktiv') ?></div></div>
    <div class="status-time"><?= date('H:i:s') ?><br><span style="color:var(--dim)">aktualisiert</span></div>
  </div>

  <!-- METRICS -->
  <div class="metrics">
    <div class="metric">
      <div class="metric-val" style="color:var(--white)"><?= $ais['total'] ?></div>
      <div class="metric-lbl">Tanker / 24h</div>
      <div class="ratio-bar"><div class="ratio-fill"></div></div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--white);font-size:clamp(1.5rem,3vw,2.5rem);padding-top:0.3rem"><?= $ratio_pct ?>%</div>
      <div class="metric-lbl">von Baseline (<?= BASELINE_TANKERS_PER_DAY ?>/Tag)</div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--green)"><?= $ais['outbound'] ?></div>
      <div class="metric-lbl">↗ Ausfahrend</div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--blue)"><?= $ais['inbound'] ?></div>
      <div class="metric-lbl">↙ Einfahrend</div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--red)"><?= $ais['anchored'] ?></div>
      <div class="metric-lbl">⚓ Ankernde</div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--orange)"><?= count($news) ?></div>
      <div class="metric-lbl">News Hits 24h</div>
    </div>
    <div class="metric">
      <div class="metric-val" style="color:var(--white)"><?= count($map_tankers) ?></div>
      <div class="metric-lbl">Schiffe auf Karte</div>
    </div>
  </div>

  <!-- MAP + LIVE FEED -->
  <div class="map-section">

    <div class="map-wrap">
      <div class="map-header">
        <div class="panel-title">Straße von Hormuz — Live Positionen (letzte 6h)</div>
        <div class="map-legend">
          <span><span class="ld" style="background:var(--green)"></span>Ausfahrend</span>
          <span><span class="ld" style="background:var(--blue)"></span>Einfahrend</span>
          <span><span class="ld" style="background:var(--red)"></span>Ankernd</span>
          <span><span class="ld" style="background:#666"></span>Unbekannt</span>
        </div>
      </div>
      <div id="hormuz-map"></div>
    </div>

    <div class="live-feed">
      <div class="feed-header"><div class="panel-title">Live Feed — Tanker Sichtungen</div></div>
      <div class="feed-scroll">
        <?php if (empty($live_feed)): ?>
          <div class="empty" style="padding:1.4rem">Noch keine Sichtungen<br>Collector läuft?</div>
        <?php else: ?>
          <?php foreach ($live_feed as $t): ?>
            <?php
              $dc = match($t['direction']) { 'OUTBOUND'=>'d-out','INBOUND'=>'d-in','ANCHORED'=>'d-anc',default=>'d-unk' };
              $dl = match($t['direction']) { 'OUTBOUND'=>'↗ AUSFAHRT','INBOUND'=>'↙ EINFAHRT','ANCHORED'=>'⚓ ANKER',default=>'? UNBEKANNT' };
            ?>
            <div class="feed-item">
              <div>
                <div class="feed-name"><?= htmlspecialchars($t['ship_name'] ?: 'UNKNOWN') ?></div>
                <div class="feed-dir <?= $dc ?>"><?= $dl ?></div>
                <div class="feed-meta">COG <?= round($t['cog']) ?>° · <?= $t['sog'] ?>kn</div>
              </div>
              <div class="feed-time"><?= time_ago($t['seen_at']) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- CHARTS -->
  <div class="charts-section">

    <div class="chart-panel">
      <div class="chart-hdr">
        <div class="panel-title">Traffic — 7 Tage (stündlich)</div>
      </div>
      <div class="chart-wrap"><canvas id="chart7d"></canvas></div>
    </div>

    <div class="chart-panel">
      <div class="chart-hdr">
        <div class="panel-title">Traffic — 30 Tage (täglich) · Historischer Verlauf</div>
      </div>
      <div class="chart-wrap"><canvas id="chart30d"></canvas></div>
    </div>

  </div>

  <!-- NEWS + ALERTS -->
  <div class="bottom-section">

    <div class="news-panel">
      <div class="panel-title" style="margin-bottom:1rem">News — Letzte 24h</div>
      <?php if (empty($news)): ?>
        <div class="empty">Keine relevanten News in letzten 24h</div>
      <?php else: ?>
        <?php foreach ($news as $n): ?>
          <div class="news-item">
            <div class="news-title">
              <a href="<?= htmlspecialchars($n['link']) ?>" target="_blank" rel="noopener">
                <?= htmlspecialchars($n['title']) ?>
              </a>
            </div>
            <div class="news-meta">
              <?= htmlspecialchars($n['feed_name']) ?> ·
              <span class="<?= $n['alert_level'] >= 2 ? 'kw-high' : 'kw-med' ?>"><?= htmlspecialchars($n['keyword']) ?></span>
              · <?= time_ago($n['seen_at']) ?>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="alerts-panel">
      <div class="panel-title" style="margin-bottom:1rem">Alert-Verlauf</div>
      <?php if (empty($sent_alerts)): ?>
        <div class="empty">Noch keine Alerts gesendet</div>
      <?php else: ?>
        <?php foreach ($sent_alerts as $a): ?>
          <div class="alert-item">
            <div>
              <span class="badge b<?= $a['alert_level'] ?>">Level <?= $a['alert_level'] ?></span>
              <span class="alert-time"><?= time_ago($a['sent_at']) ?></span>
            </div>
            <div class="alert-msg"><?= htmlspecialchars($a['message']) ?></div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div style="margin-top:1.5rem;padding-top:1rem;border-top:1px solid var(--line)">
        <div class="panel-title" style="margin-bottom:0.8rem">Alert-Schwellen</div>
        <div style="font-size:0.7rem;color:var(--muted);line-height:2.1">
          <span style="color:var(--blue)">●</span> Level 1 — Tanker +40% über Baseline<br>
          <span style="color:var(--orange)">●</span> Level 2 — 80% Baseline, mehrh. ausfahrend<br>
          <span style="color:var(--red)">●</span> Level 3 — Level 2 + Breaking News<br>
          <span style="color:var(--dim)">●</span> Spam-Schutz — max. 1 Alert/6h je Level
        </div>
      </div>
    </div>

  </div>

  <div class="footer">
    HORMUZ MONITOR — Automatisches Geopolitisches Signal-System<br>
    AIS: aisstream.io · Karte: © OpenStreetMap · News: Reuters, Al Jazeera, IRNA, AP<br>
    Kein Finanzberatung. Alle Daten zu Informationszwecken. Trade auf eigenes Risiko.<br>
    Auto-Refresh in <span id="cd">300</span>s
  </div>

</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
// ════════════════════════════════════════════
//  LEAFLET MAP
// ════════════════════════════════════════════
const tankers = <?= $map_json ?>;

const map = L.map('hormuz-map', {
    center: [26.35, 56.4],
    zoom: 8,
    zoomControl: true,
});

L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© <a href="https://openstreetmap.org">OpenStreetMap</a>',
    maxZoom: 18,
}).addTo(map);

// Monitoring-Bbox anzeigen
L.rectangle(
    [[<?= BBOX_SW_LAT ?>, <?= BBOX_SW_LON ?>], [<?= BBOX_NE_LAT ?>, <?= BBOX_NE_LON ?>]],
    { color: 'rgba(255,255,255,0.2)', weight: 1, fillOpacity: 0, dashArray: '5 5' }
).addTo(map).bindTooltip('Monitoring Zone', { permanent: false, className: '' });

// Tanker-Icon mit Richtungspfeil
function tankerIcon(dir, cog) {
    const clr = { OUTBOUND:'#44ff88', INBOUND:'#4488ff', ANCHORED:'#ff4444', UNKNOWN:'#888' }[dir] || '#888';
    const arrow = dir !== 'ANCHORED'
        ? `<line x1="12" y1="12" x2="12" y2="3" stroke="${clr}" stroke-width="2"/>
           <polygon points="9,5 12,0 15,5" fill="${clr}"/>`
        : `<text x="12" y="16" text-anchor="middle" font-size="10" fill="${clr}">⚓</text>`;
    return L.divIcon({
        className: '',
        html: `<div style="transform:rotate(${cog}deg);width:24px;height:24px">
                 <svg width="24" height="24" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                   <circle cx="12" cy="12" r="10" fill="${clr}" fill-opacity="0.15" stroke="${clr}" stroke-width="1.5"/>
                   <circle cx="12" cy="12" r="3" fill="${clr}"/>
                   ${arrow}
                 </svg>
               </div>`,
        iconSize: [24, 24],
        iconAnchor: [12, 12],
    });
}

tankers.forEach(t => {
    if (!t.lat || !t.lon) return;
    // Sanity check — Hormuz-Region
    if (t.lat < 22 || t.lat > 30 || t.lon < 52 || t.lon > 62) return;

    const clr = { OUTBOUND:'#44ff88', INBOUND:'#4488ff', ANCHORED:'#ff4444' }[t.direction] || '#888';
    const dLabel = { OUTBOUND:'↗ Ausfahrend', INBOUND:'↙ Einfahrend', ANCHORED:'⚓ Ankernde' }[t.direction] || 'Unbekannt';

    L.marker([t.lat, t.lon], { icon: tankerIcon(t.direction, t.cog) })
        .addTo(map)
        .bindPopup(`
            <div style="min-width:200px">
            <div style="font-family:'Bebas Neue',display;font-size:1.1rem;letter-spacing:1px;color:#fff;margin-bottom:8px">
              ${t.name}
            </div>
            <div style="color:#555;font-size:0.7rem;margin-bottom:10px">MMSI: ${t.mmsi}</div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:0.75rem">
              <div style="color:#555">Richtung</div>
              <div style="color:${clr};font-weight:600">${dLabel}</div>
              <div style="color:#555">Kurs (COG)</div>
              <div>${t.cog}°</div>
              <div style="color:#555">Geschwindigkeit</div>
              <div>${t.sog} kn</div>
              <div style="color:#555">Zuletzt gesehen</div>
              <div style="color:#555">${t.seen}</div>
            </div>
            </div>
        `);
});

if (tankers.length === 0) {
    L.popup({ closeButton: false })
        .setLatLng([26.35, 56.4])
        .setContent('<div style="color:#555;font-size:0.75rem;padding:4px">Keine Schiffe in DB — Collector läuft?</div>')
        .openOn(map);
}

// ════════════════════════════════════════════
//  CHARTS
// ════════════════════════════════════════════
Chart.defaults.color = '#444';
Chart.defaults.font.family = "'Manrope', sans-serif";
Chart.defaults.font.size = 10;

const gridC = 'rgba(255,255,255,0.05)';
const base  = <?= BASELINE_TANKERS_PER_DAY ?>;

// ── 7-Tage (stündlich) ──
new Chart(document.getElementById('chart7d'), {
    data: {
        labels: <?= $chart_7d_labels ?>,
        datasets: [
            {
                type: 'bar', label: 'Gesamt',
                data: <?= $chart_7d_total ?>,
                backgroundColor: 'rgba(68,136,255,0.2)',
                borderColor: 'rgba(68,136,255,0.5)', borderWidth: 1,
            },
            {
                type: 'bar', label: 'Ausfahrend',
                data: <?= $chart_7d_out ?>,
                backgroundColor: 'rgba(68,255,136,0.3)',
                borderColor: 'rgba(68,255,136,0.6)', borderWidth: 1,
            },
            {
                type: 'line', label: 'Ankernde',
                data: <?= $chart_7d_anc ?>,
                borderColor: 'rgba(255,68,68,0.7)', borderWidth: 1.5,
                pointRadius: 0, fill: false, tension: 0.4,
            },
            {
                type: 'line', label: `Baseline (${base}/h)`,
                data: Array(<?= count($history_7d) ?>).fill(base),
                borderColor: 'rgba(255,136,0,0.5)', borderDash: [4,4],
                borderWidth: 1, pointRadius: 0, fill: false,
            },
        ]
    },
    options: makeOpts(<?= count($history_7d) ?>, 12),
});

// ── 30-Tage (täglich) ──
new Chart(document.getElementById('chart30d'), {
    data: {
        labels: <?= $chart_30d_labels ?>,
        datasets: [
            {
                type: 'line', label: 'Gesamt/Tag',
                data: <?= $chart_30d_total ?>,
                borderColor: 'rgba(68,136,255,0.8)',
                backgroundColor: 'rgba(68,136,255,0.08)',
                borderWidth: 2, pointRadius: 3, fill: true, tension: 0.4,
            },
            {
                type: 'line', label: 'Ausfahrend/Tag',
                data: <?= $chart_30d_out ?>,
                borderColor: 'rgba(68,255,136,0.7)',
                borderWidth: 1.5, pointRadius: 2, fill: false, tension: 0.4,
            },
            {
                type: 'line', label: `Baseline (${base*24}/Tag)`,
                data: Array(<?= count($history_30d) ?>).fill(base * 24),
                borderColor: 'rgba(255,136,0,0.5)', borderDash: [4,4],
                borderWidth: 1, pointRadius: 0, fill: false,
            },
        ]
    },
    options: makeOpts(<?= count($history_30d) ?>, 8),
});

function makeOpts(n, maxTicks) {
    return {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { color: '#555', font: { size: 10 }, boxWidth: 10, padding: 10 } },
            tooltip: {
                backgroundColor: 'rgba(8,8,8,0.95)',
                borderColor: 'rgba(255,255,255,0.08)', borderWidth: 1,
                titleColor: '#666', bodyColor: '#aaa', padding: 10,
            }
        },
        scales: {
            x: { ticks: { color:'#3a3a3a', maxTicksLimit: maxTicks, font:{size:9} }, grid: { color: gridC } },
            y: { ticks: { color:'#444', font:{size:10} }, grid: { color: gridC }, min: 0 }
        }
    };
}

// ── Auto-Refresh Countdown ──
let s = 300;
const cd = document.getElementById('cd');
setInterval(() => { s--; if(cd) cd.textContent=s; if(s<=0) location.reload(); }, 1000);
</script>
</body>
</html>
