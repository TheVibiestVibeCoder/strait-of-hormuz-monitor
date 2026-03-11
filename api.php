<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    $includeVerboseDebug = isset($_GET['debug']) && (string)$_GET['debug'] === '1';
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
        'debug' => build_debug_payload(
            $db,
            $collectorState,
            $diagnosis,
            $delaySeconds,
            $stats,
            $includeVerboseDebug
        ),
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

function build_debug_payload(
    PDO $db,
    array $collectorState,
    array $diagnosis,
    ?int $delaySeconds,
    array $stats,
    bool $includeVerboseDebug
): array {
    $rootPath = __DIR__;
    $logsDir = $rootPath . '/logs';
    $logPath = $logsDir . '/collector.log';

    $stateUpdatedAt = (string)($collectorState['updated_at'] ?? '');
    $stateFinishedAt = (string)($collectorState['finished_at'] ?? '');
    $stateAgeSeconds = iso_to_age_seconds($stateUpdatedAt);

    $dbLatestTotal = (int)$db->query("SELECT COUNT(*) FROM vessel_latest")->fetchColumn();
    $dbSightingsTotal = (int)$db->query("SELECT COUNT(*) FROM tanker_sightings")->fetchColumn();
    $dbLatestTankerTotal = (int)$db->query(
        "SELECT COUNT(*) FROM vessel_latest WHERE ship_type BETWEEN 80 AND 89"
    )->fetchColumn();
    $dbLastAnySeen = (string)($db->query("SELECT MAX(seen_at) FROM vessel_latest")->fetchColumn() ?: '');
    $dbLastTankerSeen = (string)($db->query(
        "SELECT MAX(seen_at) FROM vessel_latest WHERE ship_type BETWEEN 80 AND 89"
    )->fetchColumn() ?: '');

    $fileChecks = [
        'project_root' => probe_path($rootPath),
        'db_path' => probe_path(DB_PATH),
        'collector_state_path' => probe_path(COLLECTOR_STATE_PATH),
        'logs_dir' => probe_path($logsDir),
        'collector_log_path' => probe_path($logPath),
    ];

    $terminalLines = [
        '[' . gmdate('Y-m-d H:i:s') . ' UTC] Debug snapshot generated',
        'Issue: ' . ((string)($diagnosis['issue_code'] ?? 'unknown')),
        'Hint: ' . ((string)($diagnosis['hint'] ?? '-')),
        'Collector status: ' . ((string)($diagnosis['collector_status'] ?? 'unknown')),
        'Collector state available: ' . (((bool)($collectorState['available'] ?? false)) ? 'yes' : 'no'),
        'State updated_at: ' . ($stateUpdatedAt !== '' ? $stateUpdatedAt : '-'),
        'State age: ' . ($stateAgeSeconds !== null ? ($stateAgeSeconds . 's') : '-'),
        'Collector delay (tanker data): ' . ($delaySeconds !== null ? ($delaySeconds . 's') : '-'),
        'DB vessel_latest rows: ' . $dbLatestTotal,
        'DB tanker_sightings rows: ' . $dbSightingsTotal,
        'Active tankers in window: ' . ((int)($stats['active_total'] ?? 0)),
        'Position messages: ' . ((int)($collectorState['position_messages'] ?? 0)),
        'Static messages: ' . ((int)($collectorState['static_messages'] ?? 0)),
        'Skipped non-tanker: ' . ((int)($collectorState['skipped_non_tanker'] ?? 0)),
        'Type backfill hits: ' . ((int)($collectorState['type_backfill_hits'] ?? 0)),
        'Last collector error: ' . ((string)($diagnosis['collector_last_error'] ?? '-')),
        'API key configured: ' . (((bool)($diagnosis['api_key_configured'] ?? false)) ? 'true' : 'false'),
    ];

    $logTail = [
        'available' => false,
        'line_count' => 0,
        'lines' => [],
    ];

    if ($includeVerboseDebug) {
        $logTail = read_log_tail($logPath, 14000, 120);
        if ((bool)$logTail['available']) {
            $terminalLines[] = 'Collector log lines loaded: ' . (int)$logTail['line_count'];
            $tailLines = (array)($logTail['lines'] ?? []);
            foreach (array_slice($tailLines, -8) as $line) {
                $terminalLines[] = 'log> ' . $line;
            }
        } else {
            $terminalLines[] = 'Collector log is not available yet.';
        }
    }

    $recommendations = build_debug_recommendations(
        $diagnosis,
        $collectorState,
        $fileChecks,
        $delaySeconds,
        $dbLatestTotal,
        $dbSightingsTotal,
        $logTail
    );

    return [
        'verbose' => $includeVerboseDebug,
        'system' => [
            'server_time_utc' => gmdate('c'),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'php_binary' => defined('PHP_BINARY') ? (string)PHP_BINARY : '',
            'project_root' => $rootPath,
            'timezone' => (string)date_default_timezone_get(),
        ],
        'collector' => [
            'status' => (string)($collectorState['status'] ?? 'missing'),
            'run_id' => (string)($collectorState['run_id'] ?? ''),
            'runtime_seconds' => (int)($collectorState['runtime_seconds'] ?? 0),
            'websocket_connected' => (bool)($collectorState['websocket_connected'] ?? false),
            'subscription_sent' => (bool)($collectorState['subscription_sent'] ?? false),
            'seen_messages' => (int)($collectorState['seen_messages'] ?? 0),
            'saved_latest' => (int)($collectorState['saved_latest'] ?? 0),
            'saved_snapshots' => (int)($collectorState['saved_snapshots'] ?? 0),
            'position_messages' => (int)($collectorState['position_messages'] ?? 0),
            'static_messages' => (int)($collectorState['static_messages'] ?? 0),
            'skipped_invalid_payload' => (int)($collectorState['skipped_invalid_payload'] ?? 0),
            'skipped_no_mmsi' => (int)($collectorState['skipped_no_mmsi'] ?? 0),
            'skipped_no_coords' => (int)($collectorState['skipped_no_coords'] ?? 0),
            'skipped_non_tanker' => (int)($collectorState['skipped_non_tanker'] ?? 0),
            'type_backfill_hits' => (int)($collectorState['type_backfill_hits'] ?? 0),
            'started_at' => (string)($collectorState['started_at'] ?? ''),
            'finished_at' => $stateFinishedAt,
            'updated_at' => $stateUpdatedAt,
            'state_age_seconds' => $stateAgeSeconds,
            'last_error' => (string)($collectorState['last_error'] ?? ''),
            'api_key_configured' => (bool)($collectorState['api_key_configured'] ?? ($diagnosis['api_key_configured'] ?? false)),
            'api_key_fingerprint' => (string)($collectorState['api_key_fingerprint'] ?? 'unknown'),
        ],
        'database' => [
            'db_path' => DB_PATH,
            'rows_vessel_latest' => $dbLatestTotal,
            'rows_tanker_sightings' => $dbSightingsTotal,
            'rows_tanker_latest' => $dbLatestTankerTotal,
            'last_seen_any_vessel' => $dbLastAnySeen,
            'last_seen_tanker' => $dbLastTankerSeen,
            'active_total_window' => (int)($stats['active_total'] ?? 0),
            'active_outbound_window' => (int)($stats['active_outbound'] ?? 0),
            'active_inbound_window' => (int)($stats['active_inbound'] ?? 0),
            'active_anchored_window' => (int)($stats['active_anchored'] ?? 0),
        ],
        'files' => $fileChecks,
        'log_tail' => $logTail,
        'recommended_actions' => $recommendations,
        'terminal_lines' => $terminalLines,
    ];
}

function probe_path(string $path): array {
    $exists = file_exists($path);
    $isDir = $exists && is_dir($path);
    $size = null;
    $modifiedAt = '';

    if ($exists) {
        $mtime = @filemtime($path);
        if ($mtime !== false) {
            $modifiedAt = gmdate('c', (int)$mtime);
        }

        if (!$isDir) {
            $rawSize = @filesize($path);
            if ($rawSize !== false) {
                $size = (int)$rawSize;
            }
        }
    }

    return [
        'path' => $path,
        'exists' => $exists,
        'is_dir' => $isDir,
        'readable' => $exists ? is_readable($path) : false,
        'writable' => $exists ? is_writable($path) : false,
        'size_bytes' => $size,
        'modified_at' => $modifiedAt,
    ];
}

function iso_to_age_seconds(string $iso): ?int {
    if ($iso === '') {
        return null;
    }

    $ts = strtotime($iso);
    if ($ts === false) {
        return null;
    }

    return max(0, time() - (int)$ts);
}

function read_log_tail(string $path, int $maxBytes, int $maxLines): array {
    if (!is_file($path) || !is_readable($path)) {
        return [
            'available' => false,
            'line_count' => 0,
            'lines' => [],
            'message' => 'collector log missing or unreadable',
        ];
    }

    $size = @filesize($path);
    if ($size === false || (int)$size <= 0) {
        return [
            'available' => true,
            'line_count' => 0,
            'lines' => [],
            'message' => 'collector log is empty',
        ];
    }

    $fp = @fopen($path, 'rb');
    if (!is_resource($fp)) {
        return [
            'available' => false,
            'line_count' => 0,
            'lines' => [],
            'message' => 'failed to open collector log',
        ];
    }

    $sizeInt = (int)$size;
    $readFrom = max(0, $sizeInt - max(2048, $maxBytes));
    if (fseek($fp, $readFrom, SEEK_SET) !== 0) {
        fclose($fp);
        return [
            'available' => false,
            'line_count' => 0,
            'lines' => [],
            'message' => 'failed to seek collector log',
        ];
    }

    $raw = stream_get_contents($fp);
    fclose($fp);
    if ($raw === false) {
        return [
            'available' => false,
            'line_count' => 0,
            'lines' => [],
            'message' => 'failed to read collector log',
        ];
    }

    $raw = trim((string)$raw);
    if ($raw === '') {
        return [
            'available' => true,
            'line_count' => 0,
            'lines' => [],
            'message' => 'collector log has no text lines',
        ];
    }

    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $lines = array_values(array_filter(array_map('trim', $lines), static function ($line): bool {
        return $line !== '';
    }));
    if (count($lines) > $maxLines) {
        $lines = array_slice($lines, -$maxLines);
    }

    return [
        'available' => true,
        'line_count' => count($lines),
        'lines' => $lines,
        'message' => '',
    ];
}

function build_debug_recommendations(
    array $diagnosis,
    array $collectorState,
    array $fileChecks,
    ?int $delaySeconds,
    int $dbLatestTotal,
    int $dbSightingsTotal,
    array $logTail
): array {
    $actions = [];
    $issue = (string)($diagnosis['issue_code'] ?? '');

    if (!(bool)($diagnosis['api_key_configured'] ?? false)) {
        $actions[] = 'Set AISSTREAM_API_KEY in .env (exact key, no quotes/spaces).';
    }

    if ($issue === 'collector_not_run') {
        $actions[] = 'Verify cron command uses /usr/local/bin/lsphp and points to this exact folder.';
        $actions[] = 'Confirm collector.php is executed by shell cron command, not via HTTP/curl URL.';
        $actions[] = 'For immediate validation, press "Run Collector Now" in the dashboard once and re-check debug metrics.';
    }

    $tailLines = array_map('strtolower', (array)($logTail['lines'] ?? []));
    foreach ($tailLines as $line) {
        if (str_contains($line, 'must run') && str_contains($line, 'not via http')) {
            $actions[] = 'Current trigger is being treated as web request. Use cron command: /usr/local/bin/lsphp /home/markussc/hormuz.markusschwinghammer.com/collector.php --runtime=50';
            $actions[] = 'If using lsphp cron and this persists, keep latest collector.php (it now accepts shell-invoked lsphp/cg-fcgi).';
            break;
        }
    }

    if (in_array($issue, ['collector_error', 'key_auth_problem', 'collector_stale'], true)) {
        $actions[] = 'Open logs/collector.log in File Manager and inspect latest lines for websocket/auth errors.';
    }

    if ((bool)($collectorState['available'] ?? false)) {
        if (!(bool)($collectorState['websocket_connected'] ?? false)) {
            $actions[] = 'Collector did not confirm websocket connection; check outbound WSS access to stream.aisstream.io:443.';
        }
        if ((bool)($collectorState['websocket_connected'] ?? false) && !(bool)($collectorState['subscription_sent'] ?? false)) {
            $actions[] = 'Collector connected but subscription was not sent; check collector runtime errors in logs.';
        }
        if ((int)($collectorState['seen_messages'] ?? 0) === 0) {
            $actions[] = 'Collector received zero messages. Keep cron at 1-minute test interval for 5-10 minutes to validate.';
        }
        if ((int)($collectorState['seen_messages'] ?? 0) > 0 && (int)($collectorState['saved_latest'] ?? 0) === 0) {
            $actions[] = 'Collector sees stream traffic but stores 0 tankers. Check skipped_non_tanker and bounding box/type filters.';
        }
        if ((int)($collectorState['position_messages'] ?? 0) > 0
            && (int)($collectorState['type_backfill_hits'] ?? 0) === 0
            && (int)($collectorState['skipped_non_tanker'] ?? 0) > 0) {
            $actions[] = 'Many position messages have unknown/non-tanker type. Increase runtime to 55s so ShipStaticData can enrich types.';
        }
    }

    if ($delaySeconds !== null && $delaySeconds > 3600) {
        $actions[] = 'Data is stale (>60m). Confirm cron frequency and check if collector.lock is stuck.';
    }

    if ($dbLatestTotal === 0 && $dbSightingsTotal === 0) {
        $actions[] = 'Database has no vessel rows yet. This indicates collector has not saved any tanker messages.';
    }

    $stateFile = $fileChecks['collector_state_path'] ?? [];
    if (!(bool)($stateFile['exists'] ?? false)) {
        $actions[] = 'collector_state.json is missing; cron has likely not executed collector.php in this directory.';
    } elseif (!(bool)($stateFile['writable'] ?? true)) {
        $actions[] = 'collector_state.json is not writable by PHP user. Fix file permissions/ownership.';
    }

    $logFile = $fileChecks['collector_log_path'] ?? [];
    if (!(bool)($logFile['exists'] ?? false)) {
        $actions[] = 'collector.log does not exist yet. Ensure logs/ is writable and cron redirects output there.';
    }

    if ($actions === []) {
        $actions[] = 'No blocking issue detected from API diagnostics.';
    }

    return $actions;
}
