<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "collector.php must run via CLI cron, not via HTTP." . PHP_EOL;
    exit(1);
}

$state = [
    'run_id' => uniqid('collector_', true),
    'mode' => $isCli ? 'cli' : 'web',
    'status' => 'starting',
    'started_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'runtime_seconds' => COLLECTOR_RUNTIME,
    'websocket_connected' => false,
    'subscription_sent' => false,
    'seen_messages' => 0,
    'saved_latest' => 0,
    'saved_snapshots' => 0,
    'last_error' => null,
    'api_key_configured' => AISSTREAM_API_KEY !== '' && AISSTREAM_API_KEY !== 'CHANGE_ME',
    'api_key_fingerprint' => key_fingerprint(AISSTREAM_API_KEY),
];
write_collector_state($state);

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    finalize_and_exit($state, 'error', 'Missing vendor/autoload.php. Run: composer install --no-dev', 1);
}
require_once $autoload;

use WebSocket\Client;

if (AISSTREAM_API_KEY === '' || AISSTREAM_API_KEY === 'CHANGE_ME') {
    finalize_and_exit($state, 'error', 'AISSTREAM_API_KEY is not configured in .env.', 1);
}

$runtime = cli_option_runtime($argv, COLLECTOR_RUNTIME);

$runtime = max(15, min(55, $runtime));
$state['runtime_seconds'] = $runtime;
$state['status'] = 'bootstrapping';
$state['updated_at'] = gmdate('c');
write_collector_state($state);

$lockFile = __DIR__ . '/collector.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    finalize_and_exit($state, 'error', 'Could not open lock file.', 1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $state['status'] = 'skipped_lock';
    $state['finished_at'] = gmdate('c');
    $state['updated_at'] = gmdate('c');
    write_collector_state($state);
    output_line('Collector already running, skipping this trigger.');
    fclose($lockHandle);
    exit(0);
}

try {
    $db = get_db();
    prune_old_data($db);
} catch (Throwable $error) {
    finalize_and_exit($state, 'error', 'DB init failed: ' . $error->getMessage(), 1, $lockHandle);
}

$upsertLatestStmt = $db->prepare(
    "INSERT INTO vessel_latest
        (mmsi, ship_name, ship_type, latitude, longitude, cog, sog, nav_status, direction, seen_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
     ON CONFLICT(mmsi) DO UPDATE SET
        ship_name = excluded.ship_name,
        ship_type = excluded.ship_type,
        latitude = excluded.latitude,
        longitude = excluded.longitude,
        cog = excluded.cog,
        sog = excluded.sog,
        nav_status = excluded.nav_status,
        direction = excluded.direction,
        seen_at = datetime('now'),
        updated_at = datetime('now')"
);

$insertSnapshotStmt = $db->prepare(
    "INSERT INTO tanker_sightings
        (mmsi, ship_name, ship_type, latitude, longitude, cog, sog, nav_status, direction, seen_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, datetime('now'))"
);

$lastSnapshotLookupStmt = $db->prepare(
    "SELECT seen_at
     FROM tanker_sightings
     WHERE mmsi = ?
     ORDER BY seen_at DESC
     LIMIT 1"
);

$latestTypeLookupStmt = $db->prepare(
    "SELECT ship_type, ship_name
     FROM vessel_latest
     WHERE mmsi = ?
     LIMIT 1"
);

$staticUpsertStmt = $db->prepare(
    "INSERT INTO vessel_latest
        (mmsi, ship_name, ship_type, latitude, longitude, cog, sog, nav_status, direction, seen_at, updated_at)
     VALUES (?, ?, ?, 0, 0, 0, 0, 0, 'UNKNOWN', datetime('now'), datetime('now'))
     ON CONFLICT(mmsi) DO UPDATE SET
        ship_type = CASE
            WHEN vessel_latest.ship_type = 0 THEN excluded.ship_type
            ELSE vessel_latest.ship_type
        END,
        ship_name = CASE
            WHEN vessel_latest.ship_name IS NULL OR vessel_latest.ship_name = '' OR vessel_latest.ship_name = 'UNKNOWN'
                THEN excluded.ship_name
            ELSE vessel_latest.ship_name
        END,
        updated_at = datetime('now')"
);

$startTs = time();
$endTs = $startTs + $runtime;
$seenMessages = 0;
$savedLatest = 0;
$savedSnapshots = 0;
$knownTypes = [];
$lastLatestWriteTs = [];
$lastSnapshotTs = [];
$connectionError = null;

$state['status'] = 'running';
$state['updated_at'] = gmdate('c');
write_collector_state($state);
output_line('Collector start. Runtime: ' . $runtime . 's');

try {
    $client = new Client('wss://stream.aisstream.io/v0/stream', [
        'timeout' => COLLECTOR_TIMEOUT_SECONDS,
    ]);

    $state['websocket_connected'] = true;
    $state['status'] = 'connected';
    $state['updated_at'] = gmdate('c');
    write_collector_state($state);

    $subscription = [
        'APIKey' => AISSTREAM_API_KEY,
        'BoundingBoxes' => [[
            [BBOX_SW_LAT, BBOX_SW_LON],
            [BBOX_NE_LAT, BBOX_NE_LON],
        ]],
        'FilterMessageTypes' => ['PositionReport', 'ShipStaticData'],
    ];

    $client->send(json_encode($subscription));
    $state['subscription_sent'] = true;
    $state['status'] = 'streaming';
    $state['updated_at'] = gmdate('c');
    write_collector_state($state);
    output_line('WebSocket connected and subscribed.');

    while (time() < $endTs) {
        try {
            $raw = $client->receive();
            if ($raw === null || $raw === '') {
                continue;
            }

            $msg = json_decode($raw, true);
            if (!is_array($msg) || !isset($msg['MessageType'])) {
                continue;
            }

            $seenMessages++;
            $type = $msg['MessageType'];
            $meta = $msg['MetaData'] ?? [];
            $mmsi = isset($meta['MMSI']) ? (string)$meta['MMSI'] : '';
            if ($mmsi === '') {
                continue;
            }

            if ($type === 'PositionReport') {
                $position = $msg['Message']['PositionReport'] ?? [];

                $lat = (float)($meta['latitude'] ?? $position['Latitude'] ?? 0.0);
                $lon = (float)($meta['longitude'] ?? $position['Longitude'] ?? 0.0);
                $cog = (float)($position['Cog'] ?? 0.0);
                $sog = (float)($position['Sog'] ?? 0.0);
                $navStatus = (int)($position['NavigationalStatus'] ?? 0);
                $shipName = clean_ship_name($meta['ShipName'] ?? '');
                $shipType = (int)($meta['ShipType'] ?? 0);

                if ($lat <= 0.0 || $lon <= 0.0) {
                    continue;
                }

                if ($shipType <= 0) {
                    if (isset($knownTypes[$mmsi])) {
                        $shipType = $knownTypes[$mmsi];
                    } else {
                        $latestTypeLookupStmt->execute([$mmsi]);
                        $lastKnown = $latestTypeLookupStmt->fetch();
                        if ($lastKnown) {
                            $shipType = (int)($lastKnown['ship_type'] ?? 0);
                            if ($shipName === 'UNKNOWN') {
                                $shipName = clean_ship_name($lastKnown['ship_name'] ?? '');
                            }
                        }
                    }
                }

                if (!is_tanker($shipType)) {
                    continue;
                }

                $knownTypes[$mmsi] = $shipType;
                $direction = get_direction($cog, $navStatus);
                $now = time();

                if (!isset($lastLatestWriteTs[$mmsi]) || ($now - $lastLatestWriteTs[$mmsi]) >= 12) {
                    $upsertLatestStmt->execute([
                        $mmsi,
                        $shipName,
                        $shipType,
                        $lat,
                        $lon,
                        $cog,
                        $sog,
                        $navStatus,
                        $direction,
                    ]);
                    $lastLatestWriteTs[$mmsi] = $now;
                    $savedLatest++;
                }

                if (!isset($lastSnapshotTs[$mmsi])) {
                    $lastSnapshotLookupStmt->execute([$mmsi]);
                    $lastSeen = $lastSnapshotLookupStmt->fetchColumn();
                    $lastSnapshotTs[$mmsi] = $lastSeen ? strtotime((string)$lastSeen) : 0;
                }

                if (($now - $lastSnapshotTs[$mmsi]) >= MIN_SECONDS_BETWEEN_SIGHTINGS) {
                    $insertSnapshotStmt->execute([
                        $mmsi,
                        $shipName,
                        $shipType,
                        $lat,
                        $lon,
                        $cog,
                        $sog,
                        $navStatus,
                        $direction,
                    ]);
                    $lastSnapshotTs[$mmsi] = $now;
                    $savedSnapshots++;
                }
            } elseif ($type === 'ShipStaticData') {
                $static = $msg['Message']['ShipStaticData'] ?? [];
                $shipType = (int)($static['Type'] ?? 0);
                $shipName = clean_ship_name($static['Name'] ?? '');

                if (!is_tanker($shipType)) {
                    continue;
                }

                $knownTypes[$mmsi] = $shipType;
                $staticUpsertStmt->execute([$mmsi, $shipName, $shipType]);
            }

            if (($seenMessages % 25) === 0) {
                $state['seen_messages'] = $seenMessages;
                $state['saved_latest'] = $savedLatest;
                $state['saved_snapshots'] = $savedSnapshots;
                $state['updated_at'] = gmdate('c');
                write_collector_state($state);
            }
        } catch (\WebSocket\TimeoutException $timeout) {
            continue;
        } catch (\WebSocket\ConnectionException $connError) {
            $connectionError = $connError->getMessage();
            output_line('WebSocket connection error: ' . $connectionError);
            break;
        }
    }

    $client->close();
} catch (Throwable $error) {
    finalize_and_exit($state, 'fatal', 'Collector fatal error: ' . $error->getMessage(), 1, $lockHandle, [
        'seen_messages' => $seenMessages,
        'saved_latest' => $savedLatest,
        'saved_snapshots' => $savedSnapshots,
        'websocket_connected' => $state['websocket_connected'] ?? false,
    ]);
}

prune_old_data($db);

$state['seen_messages'] = $seenMessages;
$state['saved_latest'] = $savedLatest;
$state['saved_snapshots'] = $savedSnapshots;
$state['finished_at'] = gmdate('c');
$state['duration_seconds'] = max(0, time() - $startTs);
$state['updated_at'] = gmdate('c');

if ($connectionError !== null) {
    $state['status'] = 'connection_error';
    $state['last_error'] = $connectionError;
} elseif ($seenMessages === 0) {
    $state['status'] = 'ok_no_messages';
    $state['last_error'] = null;
} else {
    $state['status'] = 'ok';
    $state['last_error'] = null;
}

write_collector_state($state);

output_line(
    'Collector finished. Status: ' . $state['status'] .
    ', messages: ' . $seenMessages .
    ', latest updates: ' . $savedLatest .
    ', snapshots: ' . $savedSnapshots
);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

function cli_option_runtime(array $argv, int $default): int {
    foreach ($argv as $arg) {
        if (str_starts_with($arg, '--runtime=')) {
            return (int)substr($arg, 10);
        }
    }

    return $default;
}

function clean_ship_name(string $shipName): string {
    $shipName = trim($shipName);
    return $shipName === '' ? 'UNKNOWN' : $shipName;
}

function output_line(string $message): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function write_collector_state(array $state): void {
    $state['updated_at'] = gmdate('c');
    @file_put_contents(COLLECTOR_STATE_PATH, json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function finalize_and_exit(
    array &$state,
    string $status,
    string $message,
    int $exitCode,
    $lockHandle = null,
    array $extra = []
): void {
    foreach ($extra as $k => $v) {
        $state[$k] = $v;
    }

    $state['status'] = $status;
    $state['last_error'] = $message;
    $state['finished_at'] = gmdate('c');
    $state['updated_at'] = gmdate('c');
    write_collector_state($state);

    output_line($message);

    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }

    exit($exitCode);
}

function key_fingerprint(string $key): string {
    $key = trim($key);
    if ($key === '' || $key === 'CHANGE_ME') {
        return 'not-set';
    }

    $len = strlen($key);
    if ($len <= 6) {
        return str_repeat('*', $len);
    }

    return substr($key, 0, 2) . str_repeat('*', $len - 4) . substr($key, -2);
}
