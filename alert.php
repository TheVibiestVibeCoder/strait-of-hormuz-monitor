#!/usr/bin/env php
<?php
/**
 * HORMUZ MONITOR — ALERT ENGINE
 * 
 * Analysiert AIS + News Daten und sendet Email-Alerts.
 * 
 * Cron: 0 * * * * /usr/bin/php /path/to/alert.php >> /path/to/logs/alerts.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$log_prefix = '[' . date('Y-m-d H:i:s') . '] ALERT';
echo "{$log_prefix} Analyse läuft...\n";

$db = get_db();

// ─── AIS Alert Level berechnen ────────────────────────────────────────────────
$ais = calculate_alert_level($db);
echo "{$log_prefix} AIS Level {$ais['level']}: {$ais['reason']}\n";

// ─── Neue High-Priority News in letzter Stunde ────────────────────────────────
$news_stmt = $db->prepare("
    SELECT * FROM news_items 
    WHERE alert_level = 2 AND seen_at > datetime('now', '-1 hour')
    ORDER BY seen_at DESC
");
$news_stmt->execute();
$breaking_news = $news_stmt->fetchAll(PDO::FETCH_ASSOC);

echo "{$log_prefix} Breaking News: " . count($breaking_news) . " neue High-Priority Items\n";

// ─── Alert Level 3: AIS + News kombiniert ─────────────────────────────────────
$combined_level = 0;
if ($ais['level'] >= 2 && count($breaking_news) > 0) {
    $combined_level = 3;
} elseif ($ais['level'] >= 2 || count($breaking_news) > 0) {
    $combined_level = max($ais['level'], count($breaking_news) > 0 ? 2 : 0);
} elseif ($ais['level'] >= 1) {
    $combined_level = 1;
}

// ─── Email senden wenn nötig ──────────────────────────────────────────────────
if ($combined_level >= 1) {
    $alert_type = match($combined_level) {
        3 => 'LEVEL3_COMBINED',
        2 => count($breaking_news) > 0 ? 'LEVEL2_NEWS' : 'LEVEL2_AIS',
        1 => 'LEVEL1_AIS',
        default => 'UNKNOWN',
    };

    if (!alert_already_sent($db, $alert_type, $combined_level)) {
        $sent = send_alert_email($db, $combined_level, $ais, $breaking_news);
        if ($sent) {
            $db->prepare("
                INSERT INTO alerts_sent (alert_type, alert_level, message)
                VALUES (?, ?, ?)
            ")->execute([$alert_type, $combined_level, $ais['reason']]);
            echo "{$log_prefix} ✅ Email gesendet: Level {$combined_level}\n";
        } else {
            echo "{$log_prefix} ❌ Email-Fehler!\n";
        }
    } else {
        echo "{$log_prefix} ⏭ Alert bereits in letzten 6h gesendet, skip\n";
    }
} else {
    echo "{$log_prefix} Kein Alert nötig (Level 0)\n";
}

// ─── Email-Funktion ───────────────────────────────────────────────────────────
function send_alert_email(PDO $db, int $level, array $ais, array $news): bool {
    $level_label = match($level) {
        3 => '🚨 LEVEL 3 — MAXIMALES SIGNAL',
        2 => '⚠️ LEVEL 2 — STARKES SIGNAL',
        1 => '📡 LEVEL 1 — MÖGLICHE NORMALISIERUNG',
        default => 'INFO',
    };

    $level_desc = match($level) {
        3 => 'AIS-Daten UND Breaking News signalisieren gleichzeitig Veränderungen. Höchste Priorität.',
        2 => 'Entweder AIS-Tankerverkehr oder Breaking News zeigen bedeutende Veränderungen.',
        1 => 'Der Tankerverkehr durch die Straße von Hormuz steigt über Baseline.',
        default => '',
    };

    // Letzte 10 Tanker-Sichtungen
    $recent_tankers = $db->query("
        SELECT ship_name, direction, cog, sog, seen_at 
        FROM tanker_sightings 
        WHERE ship_type BETWEEN 80 AND 89
        ORDER BY seen_at DESC LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Email-Body zusammenbauen
    $subject = "[HORMUZ MONITOR] {$level_label} — " . date('d.m.Y H:i');

    $body = "
<!DOCTYPE html>
<html>
<head>
<meta charset='UTF-8'>
<style>
  body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #0a0a0a; color: #e0e0e0; margin: 0; padding: 20px; }
  .container { max-width: 680px; margin: 0 auto; }
  .header { background: #111; border-left: 4px solid " . ($level >= 3 ? '#ff4444' : ($level >= 2 ? '#ff8800' : '#44aaff')) . "; padding: 24px; margin-bottom: 24px; }
  .header h1 { font-size: 22px; margin: 0 0 8px; color: #fff; }
  .header p { margin: 0; color: #888; font-size: 14px; }
  .section { background: #111; padding: 20px; margin-bottom: 16px; border: 1px solid #222; }
  .section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: 2px; color: #666; margin: 0 0 16px; }
  .metric { display: inline-block; margin-right: 24px; margin-bottom: 12px; }
  .metric .val { font-size: 28px; font-weight: bold; color: #fff; }
  .metric .lbl { font-size: 12px; color: #666; text-transform: uppercase; }
  .tanker-row { border-bottom: 1px solid #1a1a1a; padding: 8px 0; font-size: 13px; }
  .outbound { color: #44ff88; }
  .inbound  { color: #4488ff; }
  .anchored { color: #ff4444; }
  .news-item { padding: 10px 0; border-bottom: 1px solid #1a1a1a; }
  .news-item a { color: #88aaff; text-decoration: none; font-size: 14px; }
  .news-item .meta { font-size: 11px; color: #555; margin-top: 4px; }
  .footer { text-align: center; color: #333; font-size: 12px; margin-top: 32px; }
  .ratio-bar { background: #1a1a1a; height: 8px; border-radius: 4px; margin-top: 8px; }
  .ratio-fill { background: " . ($ais['ratio'] >= 0.8 ? '#44ff88' : ($ais['ratio'] >= 0.4 ? '#ff8800' : '#ff4444')) . "; height: 8px; border-radius: 4px; width: " . min(100, round($ais['ratio'] * 100)) . "%; }
</style>
</head>
<body>
<div class='container'>

  <div class='header'>
    <h1>{$level_label}</h1>
    <p>{$level_desc}</p>
    <p style='margin-top:8px;color:#555;'>" . date('d. F Y, H:i') . " Uhr</p>
  </div>

  <div class='section'>
    <h2>AIS Tanker-Traffic — Straße von Hormuz (24h)</h2>
    <div>
      <div class='metric'><div class='val'>{$ais['total']}</div><div class='lbl'>Tanker gesamt</div></div>
      <div class='metric'><div class='val outbound'>{$ais['outbound']}</div><div class='lbl'>Ausfahrend</div></div>
      <div class='metric'><div class='val inbound'>{$ais['inbound']}</div><div class='lbl'>Einfahrend</div></div>
      <div class='metric'><div class='val anchored'>{$ais['anchored']}</div><div class='lbl'>Ankernde</div></div>
    </div>
    <div style='margin-top:12px;font-size:13px;color:#666;'>
      Baseline: " . BASELINE_TANKERS_PER_DAY . " Tanker/Tag · Aktuell: " . round($ais['ratio'] * 100) . "%
      <div class='ratio-bar'><div class='ratio-fill'></div></div>
    </div>
  </div>";

    if (!empty($recent_tankers)) {
        $body .= "
  <div class='section'>
    <h2>Letzte Tanker-Sichtungen</h2>";
        foreach ($recent_tankers as $t) {
            $dir_class = strtolower($t['direction']);
            $dir_icon = match($t['direction']) {
                'OUTBOUND' => '↗ AUSFAHRT',
                'INBOUND'  => '↙ EINFAHRT',
                'ANCHORED' => '⚓ ANKER',
                default    => '? UNBEKANNT',
            };
            $time_ago = human_time_ago($t['seen_at']);
            $body .= "
    <div class='tanker-row'>
      <span class='{$dir_class}'><strong>{$dir_icon}</strong></span> &nbsp;
      <strong style='color:#ddd;'>" . htmlspecialchars($t['ship_name']) . "</strong>
      &nbsp;<span style='color:#555;'>COG: {$t['cog']}° · SOG: {$t['sog']}kn · vor {$time_ago}</span>
    </div>";
        }
        $body .= "
  </div>";
    }

    if (!empty($news)) {
        $body .= "
  <div class='section'>
    <h2>🔴 Breaking News — High Priority</h2>";
        foreach ($news as $n) {
            $time_ago = human_time_ago($n['seen_at']);
            $body .= "
    <div class='news-item'>
      <a href='" . htmlspecialchars($n['link']) . "' target='_blank'>" . htmlspecialchars($n['title']) . "</a>
      <div class='meta'>" . htmlspecialchars($n['feed_name']) . " · Keyword: <em>" . htmlspecialchars($n['keyword']) . "</em> · vor {$time_ago}</div>
    </div>";
        }
        $body .= "
  </div>";
    }

    $dashboard_url = 'http://DEIN-SERVER/hormuz-monitor/dashboard.php';
    $body .= "
  <div class='footer'>
    <a href='{$dashboard_url}' style='color:#444;'>→ Dashboard öffnen</a><br><br>
    Hormuz Monitor — Automatisches Signal-System<br>
    Kein Finanzberatung. Trade auf eigenes Risiko.
  </div>

</div>
</body>
</html>";

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Hormuz Monitor <" . FROM_EMAIL . ">\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();

    return mail(ALERT_EMAIL, $subject, $body, $headers);
}

function human_time_ago(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 60)   return "{$diff}s";
    if ($diff < 3600) return round($diff / 60) . "min";
    return round($diff / 3600) . "h";
}
