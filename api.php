<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $db = get_db();

    $windowExpr = '-' . ACTIVE_VESSEL_WINDOW_MINUTES . ' minutes';
    $latMin = BBOX_SW_LAT - 0.8;
    $latMax = BBOX_NE_LAT + 0.8;
    $lonMin = BBOX_SW_LON - 1.2;
    $lonMax = BBOX_NE_LON + 1.2;

    $vesselStmt = $db->prepare(
        "SELECT mmsi, ship_name, ship_type, latitude, longitude, cog, sog, nav_status, direction, seen_at
         FROM vessel_latest
         WHERE ship_type BETWEEN 80 AND 89
           AND seen_at > datetime('now', ?)
           AND latitude BETWEEN ? AND ?
           AND longitude BETWEEN ? AND ?
         ORDER BY seen_at DESC
         LIMIT 250"
    );

    $vesselStmt->execute([$windowExpr, $latMin, $latMax, $lonMin, $lonMax]);
    $rows = $vesselStmt->fetchAll();

    $stats = [
        'active_total' => 0,
        'active_outbound' => 0,
        'active_inbound' => 0,
        'active_anchored' => 0,
    ];

    $vessels = [];
    foreach ($rows as $row) {
        $dir = (string)($row['direction'] ?? 'UNKNOWN');
        $stats['active_total']++;

        if ($dir === 'OUTBOUND') {
            $stats['active_outbound']++;
        } elseif ($dir === 'INBOUND') {
            $stats['active_inbound']++;
        } elseif ($dir === 'ANCHORED') {
            $stats['active_anchored']++;
        }

        $vessels[] = [
            'mmsi' => (string)$row['mmsi'],
            'name' => trim((string)($row['ship_name'] ?? '')) ?: 'UNKNOWN',
            'ship_type' => (int)($row['ship_type'] ?? 0),
            'lat' => (float)($row['latitude'] ?? 0),
            'lon' => (float)($row['longitude'] ?? 0),
            'cog' => (float)($row['cog'] ?? 0),
            'sog' => (float)($row['sog'] ?? 0),
            'direction' => $dir,
            'seen_at' => (string)$row['seen_at'],
        ];
    }

    $unique24h = (int)$db->query(
        "SELECT COUNT(DISTINCT mmsi)
         FROM tanker_sightings
         WHERE ship_type BETWEEN 80 AND 89
           AND seen_at > datetime('now', '-24 hours')"
    )->fetchColumn();

    $flow1h = (int)$db->query(
        "SELECT COUNT(DISTINCT mmsi)
         FROM tanker_sightings
         WHERE ship_type BETWEEN 80 AND 89
           AND direction IN ('INBOUND', 'OUTBOUND')
           AND seen_at > datetime('now', '-1 hour')"
    )->fetchColumn();

    $lastSeen = (string)($db->query(
        "SELECT MAX(seen_at)
         FROM vessel_latest
         WHERE ship_type BETWEEN 80 AND 89"
    )->fetchColumn() ?: '');

    $delaySeconds = $lastSeen === '' ? null : max(0, time() - strtotime($lastSeen));

    $response = [
        'generated_at' => gmdate('c'),
        'refresh_seconds' => DASHBOARD_REFRESH_SECONDS,
        'stats' => [
            'active_total' => $stats['active_total'],
            'active_outbound' => $stats['active_outbound'],
            'active_inbound' => $stats['active_inbound'],
            'active_anchored' => $stats['active_anchored'],
            'unique_24h' => $unique24h,
            'flow_1h' => $flow1h,
        ],
        'health' => [
            'last_seen_at' => $lastSeen,
            'collector_delay_seconds' => $delaySeconds,
            'collector_online' => $delaySeconds !== null && $delaySeconds <= 180,
        ],
        'vessels' => $vessels,
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'error' => 'api_error',
        'message' => $error->getMessage(),
    ], JSON_UNESCAPED_SLASHES);
}
