<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$isCronProcess = is_shell_invocation();
$isManualWebTrigger = is_manual_web_trigger_request();
if (!$isCronProcess && !$isManualWebTrigger) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "collector.php is blocked for regular web calls." . PHP_EOL;
    echo "Use shell cron, or POST collector.php?manual=1 for explicit manual trigger." . PHP_EOL;
    echo "Detected sapi=" . PHP_SAPI . ", request_method=" . (string)($_SERVER['REQUEST_METHOD'] ?? '') . PHP_EOL;
    exit(1);
}
if ($isManualWebTrigger) {
    header('Content-Type: text/plain; charset=utf-8');
}

$state = [
    'run_id' => uniqid('collector_', true),
    'mode' => $isManualWebTrigger ? 'manual-web' : 'cron-shell',
    'status' => 'starting',
    'started_at' => gmdate('c'),
    'updated_at' => gmdate('c'),
    'runtime_seconds' => COLLECTOR_RUNTIME,
    'websocket_connected' => false,
    'subscription_sent' => false,
    'seen_messages' => 0,
    'saved_latest' => 0,
    'saved_snapshots' => 0,
    'position_messages' => 0,
    'static_messages' => 0,
    'skipped_invalid_payload' => 0,
    'skipped_no_mmsi' => 0,
    'skipped_no_coords' => 0,
    'skipped_non_tanker' => 0,
    'type_backfill_hits' => 0,
    'last_error' => null,
    'api_key_configured' => AISSTREAM_API_KEY !== '' && AISSTREAM_API_KEY !== 'CHANGE_ME',
    'api_key_fingerprint' => key_fingerprint(AISSTREAM_API_KEY),
];
write_collector_state($state);

if (AISSTREAM_API_KEY === '' || AISSTREAM_API_KEY === 'CHANGE_ME') {
    finalize_and_exit($state, 'error', 'AISSTREAM_API_KEY is not configured in .env.', 1);
}

$argvList = (isset($argv) && is_array($argv)) ? $argv : [];
$runtimeDefault = $isManualWebTrigger ? MANUAL_WEB_RUNTIME : COLLECTOR_RUNTIME;
$runtime = $isManualWebTrigger
    ? web_option_runtime($runtimeDefault)
    : cli_option_runtime($argvList, $runtimeDefault);

if ($isManualWebTrigger) {
    $runtime = max(8, min(35, $runtime));
} else {
    $runtime = max(15, min(55, $runtime));
}
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
$positionMessages = 0;
$staticMessages = 0;
$skippedInvalidPayload = 0;
$skippedNoMmsi = 0;
$skippedNoCoords = 0;
$skippedNonTanker = 0;
$typeBackfillHits = 0;

$state['status'] = 'running';
$state['updated_at'] = gmdate('c');
write_collector_state($state);
output_line('Collector start. Runtime: ' . $runtime . 's');

try {
    $client = new SimpleWebSocketClient(
        'wss://stream.aisstream.io/v0/stream',
        COLLECTOR_TIMEOUT_SECONDS
    );

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
                $skippedInvalidPayload++;
                continue;
            }

            $seenMessages++;
            $type = $msg['MessageType'];
            $meta = $msg['MetaData'] ?? [];
            $mmsi = isset($meta['MMSI']) ? (string)$meta['MMSI'] : '';
            if ($mmsi === '') {
                $skippedNoMmsi++;
                continue;
            }

            if ($type === 'PositionReport') {
                $positionMessages++;
                $position = $msg['Message']['PositionReport'] ?? [];

                $lat = (float)($meta['latitude'] ?? $position['Latitude'] ?? 0.0);
                $lon = (float)($meta['longitude'] ?? $position['Longitude'] ?? 0.0);
                $cog = (float)($position['Cog'] ?? 0.0);
                $sog = (float)($position['Sog'] ?? 0.0);
                $navStatus = (int)($position['NavigationalStatus'] ?? 0);
                $shipName = clean_ship_name($meta['ShipName'] ?? '');
                $shipType = (int)($meta['ShipType'] ?? 0);

                if ($lat <= 0.0 || $lon <= 0.0) {
                    $skippedNoCoords++;
                    continue;
                }

                if ($shipType <= 0) {
                    if (isset($knownTypes[$mmsi])) {
                        $shipType = $knownTypes[$mmsi];
                        if ($shipType > 0) {
                            $typeBackfillHits++;
                        }
                    } else {
                        $latestTypeLookupStmt->execute([$mmsi]);
                        $lastKnown = $latestTypeLookupStmt->fetch();
                        if ($lastKnown) {
                            $shipType = (int)($lastKnown['ship_type'] ?? 0);
                            if ($shipType > 0) {
                                $typeBackfillHits++;
                            }
                            if ($shipName === 'UNKNOWN') {
                                $shipName = clean_ship_name($lastKnown['ship_name'] ?? '');
                            }
                        }
                    }
                }

                if (!is_tanker($shipType)) {
                    $skippedNonTanker++;
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
                $staticMessages++;
                $static = $msg['Message']['ShipStaticData'] ?? [];
                $shipType = (int)($static['Type'] ?? 0);
                $shipName = clean_ship_name($static['Name'] ?? '');

                if (!is_tanker($shipType)) {
                    $skippedNonTanker++;
                    continue;
                }

                $knownTypes[$mmsi] = $shipType;
                $staticUpsertStmt->execute([$mmsi, $shipName, $shipType]);
            }

            if (($seenMessages % 25) === 0) {
                $state['seen_messages'] = $seenMessages;
                $state['saved_latest'] = $savedLatest;
                $state['saved_snapshots'] = $savedSnapshots;
                $state['position_messages'] = $positionMessages;
                $state['static_messages'] = $staticMessages;
                $state['skipped_invalid_payload'] = $skippedInvalidPayload;
                $state['skipped_no_mmsi'] = $skippedNoMmsi;
                $state['skipped_no_coords'] = $skippedNoCoords;
                $state['skipped_non_tanker'] = $skippedNonTanker;
                $state['type_backfill_hits'] = $typeBackfillHits;
                $state['updated_at'] = gmdate('c');
                write_collector_state($state);
            }
        } catch (SimpleWebSocketTimeoutException $timeout) {
            continue;
        } catch (SimpleWebSocketConnectionException $connError) {
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
        'position_messages' => $positionMessages,
        'static_messages' => $staticMessages,
        'skipped_invalid_payload' => $skippedInvalidPayload,
        'skipped_no_mmsi' => $skippedNoMmsi,
        'skipped_no_coords' => $skippedNoCoords,
        'skipped_non_tanker' => $skippedNonTanker,
        'type_backfill_hits' => $typeBackfillHits,
    ]);
}

prune_old_data($db);

$state['seen_messages'] = $seenMessages;
$state['saved_latest'] = $savedLatest;
$state['saved_snapshots'] = $savedSnapshots;
$state['position_messages'] = $positionMessages;
$state['static_messages'] = $staticMessages;
$state['skipped_invalid_payload'] = $skippedInvalidPayload;
$state['skipped_no_mmsi'] = $skippedNoMmsi;
$state['skipped_no_coords'] = $skippedNoCoords;
$state['skipped_non_tanker'] = $skippedNonTanker;
$state['type_backfill_hits'] = $typeBackfillHits;
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

function web_option_runtime(int $default): int {
    $raw = (string)($_POST['runtime'] ?? $_GET['runtime'] ?? '');
    if ($raw === '' || !is_numeric($raw)) {
        return $default;
    }

    return (int)$raw;
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

function is_shell_invocation(): bool {
    if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
        return true;
    }

    if (isset($_SERVER['REQUEST_METHOD']) && (string)$_SERVER['REQUEST_METHOD'] !== '') {
        return false;
    }

    if (isset($_SERVER['REMOTE_ADDR']) && (string)$_SERVER['REMOTE_ADDR'] !== '') {
        return false;
    }

    if (isset($_SERVER['HTTP_HOST']) && (string)$_SERVER['HTTP_HOST'] !== '') {
        return false;
    }

    return true;
}

function is_manual_web_trigger_request(): bool {
    if (!ALLOW_WEB_MANUAL_TRIGGER) {
        return false;
    }

    $manual = (string)($_POST['manual'] ?? $_GET['manual'] ?? '');
    if ($manual !== '1' && strtolower($manual) !== 'true') {
        return false;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? ''));
    return $method === 'POST';
}

final class SimpleWebSocketConnectionException extends RuntimeException {}
final class SimpleWebSocketTimeoutException extends RuntimeException {}

final class SimpleWebSocketClient {
    /** @var resource */
    private $stream;
    private string $readBuffer = '';
    private bool $closed = false;

    public function __construct(string $url, int $timeoutSeconds) {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            throw new SimpleWebSocketConnectionException('Invalid websocket URL.');
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = (string)($parts['host'] ?? '');
        $path = (string)($parts['path'] ?? '/');
        $query = (string)($parts['query'] ?? '');

        if ($scheme === '' || $host === '') {
            throw new SimpleWebSocketConnectionException('Websocket URL must include scheme and host.');
        }

        if ($path === '') {
            $path = '/';
        }

        if ($query !== '') {
            $path .= '?' . $query;
        }

        $port = (int)($parts['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $remoteScheme = $scheme === 'wss' ? 'ssl' : 'tcp';
        $remote = $remoteScheme . '://' . $host . ':' . $port;

        $timeout = max(1, $timeoutSeconds);
        $contextOptions = [];
        if ($scheme === 'wss') {
            $contextOptions['ssl'] = [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ];
        }

        $context = stream_context_create($contextOptions);
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($stream)) {
            throw new SimpleWebSocketConnectionException(
                'Could not connect to AISStream websocket: ' . trim($errstr) . ' (' . $errno . ')'
            );
        }

        stream_set_timeout($stream, $timeout);
        stream_set_blocking($stream, true);

        $this->stream = $stream;
        $this->handshake($host, $port, $path);
    }

    public function send(string $payload): void {
        $this->writeFrame(0x1, $payload);
    }

    public function receive(): string {
        while (true) {
            [$opcode, $fin, $payload] = $this->readFrame();

            if ($opcode === 0x1) { // Text frame
                if ($fin) {
                    return $payload;
                }

                $message = $payload;
                while (true) {
                    [$nextOpcode, $nextFin, $nextPayload] = $this->readFrame();
                    if ($nextOpcode === 0x0) { // Continuation frame
                        $message .= $nextPayload;
                        if ($nextFin) {
                            return $message;
                        }
                        continue;
                    }

                    if ($nextOpcode === 0x9) { // Ping
                        $this->writeFrame(0xA, $nextPayload);
                        continue;
                    }

                    if ($nextOpcode === 0x8) {
                        throw new SimpleWebSocketConnectionException('WebSocket closed by remote peer.');
                    }
                }
            }

            if ($opcode === 0x9) { // Ping
                $this->writeFrame(0xA, $payload);
                continue;
            }

            if ($opcode === 0x8) { // Close
                throw new SimpleWebSocketConnectionException('WebSocket closed by remote peer.');
            }
        }
    }

    public function close(): void {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }

    private function handshake(string $host, int $port, string $path): void {
        $secKey = base64_encode(random_bytes(16));
        $hostHeader = $host;
        if ($port !== 80 && $port !== 443) {
            $hostHeader .= ':' . $port;
        }

        $request =
            "GET {$path} HTTP/1.1\r\n" .
            "Host: {$hostHeader}\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Key: {$secKey}\r\n" .
            "User-Agent: hormuz-monitor/1.0\r\n\r\n";

        $this->writeAll($request);
        $headersRaw = $this->readHttpHeaders();
        $lines = preg_split("/\r\n/", trim($headersRaw));
        $statusLine = $lines[0] ?? '';
        if (stripos($statusLine, '101') === false) {
            throw new SimpleWebSocketConnectionException(
                'WebSocket handshake failed. Response: ' . $statusLine
            );
        }

        $headers = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }

            $k = strtolower(trim(substr($line, 0, $pos)));
            $v = trim(substr($line, $pos + 1));
            $headers[$k] = $v;
        }

        $accept = strtolower((string)($headers['sec-websocket-accept'] ?? ''));
        $expected = strtolower(base64_encode(
            sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true)
        ));

        if ($accept === '' || !hash_equals($expected, $accept)) {
            throw new SimpleWebSocketConnectionException('Invalid websocket handshake signature.');
        }
    }

    private function readHttpHeaders(): string {
        $data = '';
        $maxLen = 65536;

        while (strpos($data, "\r\n\r\n") === false) {
            $chunk = fread($this->stream, 1024);
            if ($chunk === false) {
                throw new SimpleWebSocketConnectionException('Failed reading websocket handshake response.');
            }

            if ($chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out'])) {
                    throw new SimpleWebSocketTimeoutException('Timed out waiting for websocket handshake.');
                }
                if (feof($this->stream)) {
                    throw new SimpleWebSocketConnectionException('Socket closed during websocket handshake.');
                }
                continue;
            }

            $data .= $chunk;
            if (strlen($data) > $maxLen) {
                throw new SimpleWebSocketConnectionException('Websocket handshake headers exceeded maximum size.');
            }
        }

        $sepPos = strpos($data, "\r\n\r\n");
        if ($sepPos === false) {
            throw new SimpleWebSocketConnectionException('Websocket handshake was incomplete.');
        }

        $headerPart = substr($data, 0, $sepPos + 4);
        $remaining = substr($data, $sepPos + 4);
        if ($remaining !== false && $remaining !== '') {
            $this->readBuffer = $remaining;
        }

        return $headerPart;
    }

    private function writeFrame(int $opcode, string $payload): void {
        $finBit = 0x80;
        $firstByte = chr($finBit | ($opcode & 0x0F));
        $payloadLen = strlen($payload);
        $mask = random_bytes(4);
        $maskBit = 0x80;

        if ($payloadLen <= 125) {
            $header = $firstByte . chr($maskBit | $payloadLen);
        } elseif ($payloadLen <= 65535) {
            $header = $firstByte . chr($maskBit | 126) . pack('n', $payloadLen);
        } else {
            $header = $firstByte . chr($maskBit | 127) . pack('NN', ($payloadLen >> 32) & 0xFFFFFFFF, $payloadLen & 0xFFFFFFFF);
        }

        $maskedPayload = $this->applyMask($payload, $mask);
        $this->writeAll($header . $mask . $maskedPayload);
    }

    private function writeAll(string $data): void {
        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $n = fwrite($this->stream, substr($data, $written));
            if ($n === false || $n === 0) {
                throw new SimpleWebSocketConnectionException('Failed writing to websocket stream.');
            }
            $written += $n;
        }
    }

    /**
     * @return array{0:int,1:bool,2:string}
     */
    private function readFrame(): array {
        $header = $this->readExactly(2);
        $b1 = ord($header[0]);
        $b2 = ord($header[1]);

        $fin = (($b1 & 0x80) === 0x80);
        $opcode = $b1 & 0x0F;
        $masked = (($b2 & 0x80) === 0x80);
        $len = $b2 & 0x7F;

        if ($len === 126) {
            $ext = $this->readExactly(2);
            $unpacked = unpack('nlen', $ext);
            $len = (int)$unpacked['len'];
        } elseif ($len === 127) {
            $ext = $this->readExactly(8);
            $parts = unpack('Nhigh/Nlow', $ext);
            $len = ((int)$parts['high'] * 4294967296) + (int)$parts['low'];
        }

        $mask = $masked ? $this->readExactly(4) : '';
        $payload = $len > 0 ? $this->readExactly($len) : '';
        if ($masked) {
            $payload = $this->applyMask($payload, $mask);
        }

        return [$opcode, $fin, $payload];
    }

    private function readExactly(int $length): string {
        $data = '';

        if ($this->readBuffer !== '') {
            $take = min($length, strlen($this->readBuffer));
            $data = substr($this->readBuffer, 0, $take);
            $this->readBuffer = (string)substr($this->readBuffer, $take);
        }

        while (strlen($data) < $length) {
            $chunk = fread($this->stream, $length - strlen($data));
            if ($chunk === false) {
                throw new SimpleWebSocketConnectionException('Failed reading websocket frame.');
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($this->stream);
                if (!empty($meta['timed_out'])) {
                    throw new SimpleWebSocketTimeoutException('Timed out waiting for websocket frame.');
                }
                if (feof($this->stream)) {
                    throw new SimpleWebSocketConnectionException('Websocket connection closed.');
                }
                continue;
            }

            $data .= $chunk;
        }

        return $data;
    }

    private function applyMask(string $data, string $mask): string {
        $out = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $out .= chr(ord($data[$i]) ^ ord($mask[$i % 4]));
        }

        return $out;
    }
}
