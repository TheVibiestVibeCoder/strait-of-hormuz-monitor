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

    $collectorState = read_collector_state();
    $apiKeyConfigured = AISSTREAM_API_KEY !== '' && AISSTREAM_API_KEY !== 'CHANGE_ME';
    $diagnosis = diagnose_collector_health(
        $apiKeyConfigured,
        $stats['active_total'],
        $delaySeconds,
        $collectorState
    );

    $response = [
        'generated_at' => gmdate('c'),
        'api_ok' => true,
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
            'collector_online' => $delaySeconds !== null && $delaySeconds <= 1800,
        ],
        'diagnosis' => $diagnosis,
        'collector_state' => $collectorState,
        'vessels' => $vessels,
    ];

    echo json_encode($response, JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode([
        'api_ok' => false,
        'error' => 'api_error',
        'message' => $error->getMessage(),
        'generated_at' => gmdate('c'),
    ], JSON_UNESCAPED_SLASHES);
}

function read_collector_state(): array {
    if (!is_file(COLLECTOR_STATE_PATH) || !is_readable(COLLECTOR_STATE_PATH)) {
        return [
            'available' => false,
            'status' => 'missing',
            'message' => 'collector_state.json not found yet. Run collector once.',
        ];
    }

    $raw = file_get_contents(COLLECTOR_STATE_PATH);
    if ($raw === false || trim($raw) === '') {
        return [
            'available' => false,
            'status' => 'missing',
            'message' => 'collector_state.json is empty.',
        ];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return [
            'available' => false,
            'status' => 'invalid',
            'message' => 'collector_state.json is not valid JSON.',
        ];
    }

    $decoded['available'] = true;
    return $decoded;
}

function diagnose_collector_health(
    bool $apiKeyConfigured,
    int $activeTotal,
    ?int $delaySeconds,
    array $collectorState
): array {
    $status = (string)($collectorState['status'] ?? 'unknown');
    $lastError = (string)($collectorState['last_error'] ?? '');
    $stateAvailable = (bool)($collectorState['available'] ?? false);

    $issueCode = 'healthy';
    $hint = 'Data pipeline looks healthy.';

    if (!$apiKeyConfigured) {
        $issueCode = 'key_missing';
        $hint = 'AIS API key is not configured in .env.';
    } elseif (!$stateAvailable) {
        $issueCode = 'collector_not_run';
        $hint = 'Collector has not produced a state file yet. Run cron/collector once.';
    } elseif (in_array($status, ['forbidden', 'error', 'fatal', 'connection_error'], true)) {
        if (looks_like_auth_error($lastError)) {
            $issueCode = 'key_auth_problem';
            $hint = 'Collector reports an authentication/authorization issue. Verify AISSTREAM_API_KEY.';
        } else {
            $issueCode = 'collector_error';
            $hint = 'Collector reported an error. Check logs and collector_state details.';
        }
    } elseif ($delaySeconds === null) {
        $issueCode = 'no_ship_data_yet';
        $hint = 'Collector has run, but no tanker positions have been stored yet.';
    } elseif ($delaySeconds > 3600) {
        $issueCode = 'collector_stale';
        $hint = 'No fresh tanker updates for over 60 minutes. Check cron and collector logs.';
    } elseif ($activeTotal === 0) {
        $issueCode = 'no_active_tankers';
        $hint = 'API and collector are working; there are currently no active tankers in the window.';
    }

    return [
        'issue_code' => $issueCode,
        'hint' => $hint,
        'api_key_configured' => $apiKeyConfigured,
        'collector_status' => $status,
        'collector_last_error' => $lastError,
    ];
}

function looks_like_auth_error(string $message): bool {
    if ($message === '') {
        return false;
    }

    $m = strtolower($message);
    return str_contains($m, '401')
        || str_contains($m, '403')
        || str_contains($m, 'forbidden')
        || str_contains($m, 'unauthor')
        || str_contains($m, 'invalid key')
        || str_contains($m, 'apikey');
}
