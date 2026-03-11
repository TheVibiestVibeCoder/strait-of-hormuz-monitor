<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        init_db($db);
    }
    return $db;
}

function init_db(PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS tanker_sightings (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            mmsi        TEXT NOT NULL,
            ship_name   TEXT,
            ship_type   INTEGER,
            latitude    REAL,
            longitude   REAL,
            cog         REAL,   -- Course Over Ground (Grad)
            sog         REAL,   -- Speed Over Ground (Knoten)
            nav_status  INTEGER,
            direction   TEXT,   -- 'INBOUND', 'OUTBOUND', 'ANCHORED', 'UNKNOWN'
            seen_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS hourly_stats (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            hour_bucket     DATETIME UNIQUE,
            tanker_count    INTEGER DEFAULT 0,
            outbound_count  INTEGER DEFAULT 0,
            inbound_count   INTEGER DEFAULT 0,
            anchored_count  INTEGER DEFAULT 0,
            alert_level     INTEGER DEFAULT 0
        );

        CREATE TABLE IF NOT EXISTS news_items (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_name   TEXT,
            title       TEXT,
            link        TEXT UNIQUE,
            pub_date    DATETIME,
            keyword     TEXT,
            alert_level INTEGER DEFAULT 0,  -- 1=medium, 2=high
            seen_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS alerts_sent (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            alert_type  TEXT,
            alert_level INTEGER,
            message     TEXT,
            sent_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
}

/**
 * Bestimmt Fahrtrichtung basierend auf COG (Course Over Ground)
 * Hormuz liegt ungefähr West-Ost orientiert
 * Ausfahrt Richtung Arabisches Meer = Kurs West/Südwest (180°-360°)
 * Einfahrt in den Golf = Kurs Ost/Nordost (0°-180°)
 */
function get_direction(float $cog, int $nav_status): string {
    if ($nav_status === 1 || $nav_status === 5) return 'ANCHORED'; // Ankern/Mooring
    if ($cog >= 180 && $cog <= 360) return 'OUTBOUND'; // Raus Richtung Arabisches Meer
    if ($cog >= 0   && $cog <  180) return 'INBOUND';  // Rein in den Golf
    return 'UNKNOWN';
}

/**
 * AIS Ship Type → ist das ein Tanker?
 * Types 80-89 = Tanker, speziell 80=Tanker, 81-89=Subtypes
 */
function is_tanker(int $type): bool {
    return ($type >= 80 && $type <= 89);
}

/**
 * Berechnet Alert Level basierend auf den letzten 24h
 */
function calculate_alert_level(PDO $db): array {
    $since = date('Y-m-d H:i:s', strtotime('-24 hours'));
    
    $stmt = $db->prepare("
        SELECT 
            COUNT(DISTINCT mmsi) as total,
            SUM(CASE WHEN direction='OUTBOUND' THEN 1 ELSE 0 END) as outbound,
            SUM(CASE WHEN direction='INBOUND'  THEN 1 ELSE 0 END) as inbound,
            SUM(CASE WHEN direction='ANCHORED' THEN 1 ELSE 0 END) as anchored
        FROM tanker_sightings 
        WHERE seen_at > ? AND ship_type BETWEEN 80 AND 89
    ");
    $stmt->execute([$since]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $total    = (int)($stats['total']    ?? 0);
    $outbound = (int)($stats['outbound'] ?? 0);
    $baseline = BASELINE_TANKERS_PER_DAY;
    
    $ratio = $baseline > 0 ? $total / $baseline : 0;
    
    $level = 0;
    $reason = '';
    
    if ($ratio >= ALERT_L2_MULTIPLIER && $outbound > ($total * 0.5)) {
        $level  = 2;
        $reason = "STARKES SIGNAL: {$total} Tanker in 24h ({$outbound} ausfahrend) = " . round($ratio * 100) . "% der Baseline";
    } elseif ($ratio >= ALERT_L1_MULTIPLIER) {
        $level  = 1;
        $reason = "Verkehr steigt: {$total} Tanker in 24h = " . round($ratio * 100) . "% der Baseline";
    } elseif ($ratio < 0.3 && $total > 0) {
        $level  = -1; // Blockade-Signal
        $reason = "BLOCKADE: Nur {$total} Tanker in 24h = " . round($ratio * 100) . "% der Baseline";
    }
    
    return [
        'level'    => $level,
        'reason'   => $reason,
        'total'    => $total,
        'outbound' => $outbound,
        'inbound'  => $inbound ?? 0,
        'anchored' => $anchored ?? 0,
        'ratio'    => $ratio,
    ];
}

/**
 * Prüft ob ein Alert dieses Typs heute schon gesendet wurde (Spam-Schutz)
 */
function alert_already_sent(PDO $db, string $type, int $level): bool {
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM alerts_sent 
        WHERE alert_type = ? AND alert_level = ? AND sent_at > datetime('now', '-6 hours')
    ");
    $stmt->execute([$type, $level]);
    return (int)$stmt->fetchColumn() > 0;
}
