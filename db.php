<?php
require_once __DIR__ . '/config.php';

function get_db(): PDO {
    static $db = null;

    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        init_db($db);
    }

    return $db;
}

function init_db(PDO $db): void {
    $db->exec(
        "CREATE TABLE IF NOT EXISTS tanker_sightings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            mmsi TEXT NOT NULL,
            ship_name TEXT,
            ship_type INTEGER DEFAULT 0,
            latitude REAL DEFAULT 0,
            longitude REAL DEFAULT 0,
            cog REAL DEFAULT 0,
            sog REAL DEFAULT 0,
            nav_status INTEGER DEFAULT 0,
            direction TEXT DEFAULT 'UNKNOWN',
            seen_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS vessel_latest (
            mmsi TEXT PRIMARY KEY,
            ship_name TEXT,
            ship_type INTEGER DEFAULT 0,
            latitude REAL DEFAULT 0,
            longitude REAL DEFAULT 0,
            cog REAL DEFAULT 0,
            sog REAL DEFAULT 0,
            nav_status INTEGER DEFAULT 0,
            direction TEXT DEFAULT 'UNKNOWN',
            seen_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );

    // Compatibility tables from the previous version.
    $db->exec(
        "CREATE TABLE IF NOT EXISTS hourly_stats (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            hour_bucket DATETIME UNIQUE,
            tanker_count INTEGER DEFAULT 0,
            outbound_count INTEGER DEFAULT 0,
            inbound_count INTEGER DEFAULT 0,
            anchored_count INTEGER DEFAULT 0,
            alert_level INTEGER DEFAULT 0
        )"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS news_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            feed_name TEXT,
            title TEXT,
            link TEXT UNIQUE,
            pub_date DATETIME,
            keyword TEXT,
            alert_level INTEGER DEFAULT 0,
            seen_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $db->exec(
        "CREATE TABLE IF NOT EXISTS alerts_sent (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            alert_type TEXT,
            alert_level INTEGER,
            message TEXT,
            sent_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )"
    );

    $db->exec('CREATE INDEX IF NOT EXISTS idx_sightings_seen_at ON tanker_sightings(seen_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sightings_mmsi_seen_at ON tanker_sightings(mmsi, seen_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_sightings_ship_type_seen_at ON tanker_sightings(ship_type, seen_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_latest_seen_at ON vessel_latest(seen_at DESC)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_news_seen_at ON news_items(seen_at DESC)');
}

function get_direction(float $cog, int $nav_status): string {
    if ($nav_status === 1 || $nav_status === 5) {
        return 'ANCHORED';
    }

    if ($cog >= 180.0 && $cog <= 360.0) {
        return 'OUTBOUND';
    }

    if ($cog >= 0.0 && $cog < 180.0) {
        return 'INBOUND';
    }

    return 'UNKNOWN';
}

function is_tanker(int $type): bool {
    return $type >= 80 && $type <= 89;
}

function calculate_alert_level(PDO $db): array {
    $since = date('Y-m-d H:i:s', strtotime('-24 hours'));

    $stmt = $db->prepare(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN direction = 'OUTBOUND' THEN 1 ELSE 0 END) AS outbound,
            SUM(CASE WHEN direction = 'INBOUND' THEN 1 ELSE 0 END) AS inbound,
            SUM(CASE WHEN direction = 'ANCHORED' THEN 1 ELSE 0 END) AS anchored
        FROM vessel_latest
        WHERE ship_type BETWEEN 80 AND 89
          AND seen_at > ?"
    );
    $stmt->execute([$since]);
    $stats = $stmt->fetch() ?: [];

    $total = (int)($stats['total'] ?? 0);
    $outbound = (int)($stats['outbound'] ?? 0);
    $inbound = (int)($stats['inbound'] ?? 0);
    $anchored = (int)($stats['anchored'] ?? 0);
    $baseline = BASELINE_TANKERS_PER_DAY;

    $ratio = $baseline > 0 ? $total / $baseline : 0.0;

    $level = 0;
    $reason = '';

    if ($ratio >= ALERT_L2_MULTIPLIER && $total > 0 && $outbound > ($total * 0.5)) {
        $level = 2;
        $reason = 'Strong outbound flow vs baseline';
    } elseif ($ratio >= ALERT_L1_MULTIPLIER) {
        $level = 1;
        $reason = 'Traffic above baseline';
    } elseif ($ratio < 0.3 && $total > 0) {
        $level = -1;
        $reason = 'Traffic far below baseline';
    }

    return [
        'level' => $level,
        'reason' => $reason,
        'total' => $total,
        'outbound' => $outbound,
        'inbound' => $inbound,
        'anchored' => $anchored,
        'ratio' => $ratio,
    ];
}

function alert_already_sent(PDO $db, string $type, int $level): bool {
    $stmt = $db->prepare(
        "SELECT COUNT(*)
         FROM alerts_sent
         WHERE alert_type = ?
           AND alert_level = ?
           AND sent_at > datetime('now', '-6 hours')"
    );
    $stmt->execute([$type, $level]);

    return (int)$stmt->fetchColumn() > 0;
}

function prune_old_data(PDO $db): void {
    $retention = '-' . max(1, DATA_RETENTION_DAYS) . ' days';

    $deleteSightings = $db->prepare("DELETE FROM tanker_sightings WHERE seen_at < datetime('now', ?)");
    $deleteSightings->execute([$retention]);

    $deleteLatest = $db->prepare("DELETE FROM vessel_latest WHERE seen_at < datetime('now', '-24 hours')");
    $deleteLatest->execute();
}
