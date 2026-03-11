<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    output_line('Missing vendor/autoload.php. Run: composer install --no-dev');
    exit(1);
}
require_once $autoload;

use WebSocket\Client;

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

if (AISSTREAM_API_KEY === '' || AISSTREAM_API_KEY === 'CHANGE_ME') {
    output_line('AISSTREAM_API_KEY is not configured in config.php/env.');
    exit(1);
}

$runtime = COLLECTOR_RUNTIME;
if ($isCli) {
    $runtime = cli_option_runtime($argv, $runtime);
} else {
    $runtime = isset($_GET['runtime']) ? (int)$_GET['runtime'] : $runtime;
    $token = isset($_GET['token']) ? (string)$_GET['token'] : '';

    if (COLLECTOR_WEB_TOKEN === 'CHANGE_ME' || COLLECTOR_WEB_TOKEN === '') {
        http_response_code(403);
        output_line('COLLECTOR_WEB_TOKEN must be set before using web mode.');
        exit(1);
    }
    if (!hash_equals(COLLECTOR_WEB_TOKEN, $token)) {
        http_response_code(403);
        output_line('Invalid token.');
        exit(1);
    }

    @set_time_limit(max(30, $runtime + 10));
}

$runtime = max(15, min(55, $runtime));

$lockFile = __DIR__ . '/collector.lock';
$lockHandle = fopen($lockFile, 'c');
if ($lockHandle === false) {
    output_line('Could not open lock file.');
    exit(1);
}
if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    output_line('Collector already running, skipping this trigger.');
    exit(0);
}

$db = get_db();
prune_old_data($db);

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

output_line('Collector start. Runtime: ' . $runtime . 's');

try {
    $client = new Client('wss://stream.aisstream.io/v0/stream', [
        'timeout' => COLLECTOR_TIMEOUT_SECONDS,
    ]);

    $subscription = [
        'Apikey' => AISSTREAM_API_KEY,
        'BoundingBoxes' => [[
            [BBOX_SW_LAT, BBOX_SW_LON],
            [BBOX_NE_LAT, BBOX_NE_LON],
        ]],
        'FilterMessageTypes' => ['PositionReport', 'ShipStaticData'],
    ];

    $client->send(json_encode($subscription));
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
        } catch (\WebSocket\TimeoutException $timeout) {
            continue;
        } catch (\WebSocket\ConnectionException $connError) {
            output_line('WebSocket connection error: ' . $connError->getMessage());
            break;
        }
    }

    $client->close();
} catch (Throwable $error) {
    output_line('Collector fatal error: ' . $error->getMessage());
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

prune_old_data($db);

output_line(
    'Collector finished. Messages: ' . $seenMessages .
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
