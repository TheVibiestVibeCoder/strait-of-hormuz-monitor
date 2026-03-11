<?php
/**
 * HORMUZ MONITOR — CONNECTION DIAGNOSTIC
 * Run via SSH/cPanel Terminal: php diag.php
 * DO NOT leave this file on the server after debugging.
 */

$host    = 'stream.aisstream.io';
$port    = 443;
$timeout = 12;

echo "=== HORMUZ DIAG ===\n";
echo "PHP " . PHP_VERSION . " | SAPI: " . PHP_SAPI . "\n";
echo "OpenSSL: " . (defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'n/a') . "\n\n";

// ── 1. DNS ────────────────────────────────────────────────────────────────────
echo "── 1. DNS ──\n";
$ip = gethostbyname($host);
if ($ip === $host) {
    echo "FAIL  Cannot resolve {$host}\n\n";
} else {
    echo "OK    {$host} => {$ip}\n\n";
}

// ── 2. Plain TCP (no TLS) ─────────────────────────────────────────────────────
echo "── 2. Plain TCP to {$host}:{$port} ──\n";
$t0  = microtime(true);
$tcp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $timeout);
$ms  = round((microtime(true) - $t0) * 1000);
if (!$tcp) {
    echo "FAIL  {$errstr} ({$errno})\n\n";
} else {
    echo "OK    Connected in {$ms}ms\n\n";
    fclose($tcp);
}

// ── 3. TLS — verify ON ───────────────────────────────────────────────────────
echo "── 3. TLS ssl:// verify_peer=true ──\n";
$ctx = stream_context_create(['ssl' => [
    'verify_peer'      => true,
    'verify_peer_name' => true,
    'SNI_enabled'      => true,
    'peer_name'        => $host,
]]);
$t0  = microtime(true);
$tls = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
$ms  = round((microtime(true) - $t0) * 1000);
if (!$tls) {
    echo "FAIL  {$errstr} ({$errno}) — try verify=false next\n\n";
} else {
    $meta = stream_context_get_options($tls);
    echo "OK    TLS connected in {$ms}ms\n\n";
    fclose($tls);
}

// ── 4. TLS — verify OFF ──────────────────────────────────────────────────────
echo "── 4. TLS ssl:// verify_peer=false ──\n";
$ctx2 = stream_context_create(['ssl' => [
    'verify_peer'      => false,
    'verify_peer_name' => false,
    'SNI_enabled'      => true,
    'peer_name'        => $host,
    'allow_self_signed'=> true,
]]);
$t0   = microtime(true);
$tls2 = @stream_socket_client("ssl://{$host}:{$port}", $errno2, $errstr2, $timeout, STREAM_CLIENT_CONNECT, $ctx2);
$ms   = round((microtime(true) - $t0) * 1000);
if (!$tls2) {
    echo "FAIL  {$errstr2} ({$errno2})\n";
    echo "      => TLS itself is blocked. Contact host to allow outbound SSL to {$host}:443.\n\n";
} else {
    echo "OK    TLS (no-verify) connected in {$ms}ms — will test HTTP upgrade next\n\n";
}

// ── 5. WebSocket HTTP Upgrade ─────────────────────────────────────────────────
echo "── 5. WebSocket HTTP/1.1 Upgrade (verify=false) ──\n";
if (!isset($tls2) || !is_resource($tls2)) {
    echo "SKIP  TLS connection failed above\n\n";
} else {
    stream_set_timeout($tls2, $timeout);
    stream_set_blocking($tls2, true);

    $secKey = base64_encode(random_bytes(16));
    $req    =
        "GET /v0/stream HTTP/1.1\r\n" .
        "Host: {$host}\r\n" .
        "Upgrade: websocket\r\n" .
        "Connection: Upgrade\r\n" .
        "Sec-WebSocket-Version: 13\r\n" .
        "Sec-WebSocket-Key: {$secKey}\r\n" .
        "Origin: https://hormuz.markusschwinghammer.com\r\n" .
        "User-Agent: hormuz-monitor-diag/1.0\r\n\r\n";

    // Send the request
    $written = fwrite($tls2, $req);
    echo "      Sent {$written} bytes HTTP upgrade request\n";

    // Read response with a hard deadline
    $deadline = microtime(true) + $timeout;
    $buf      = '';
    $gotHeaders = false;
    while (microtime(true) < $deadline) {
        $chunk = fread($tls2, 2048);
        if ($chunk === false) {
            $m = stream_get_meta_data($tls2);
            if (!empty($m['timed_out'])) {
                echo "FAIL  Read timed out waiting for HTTP response\n";
                echo "      => Server got the upgrade request but sent nothing back.\n";
                echo "      => Likely a host-level WebSocket block or proxy stripping Upgrade header.\n";
            } else {
                echo "FAIL  fread returned false (eof=" . (int)feof($tls2) . ")\n";
            }
            break;
        }
        if ($chunk === '') {
            if (feof($tls2)) {
                echo "FAIL  Connection closed by server with no response\n";
                echo "      => Server rejected the WebSocket upgrade silently.\n";
                break;
            }
            continue;
        }
        $buf .= $chunk;
        if (strpos($buf, "\r\n\r\n") !== false) {
            $gotHeaders = true;
            break;
        }
    }

    if ($gotHeaders) {
        $lines      = explode("\r\n", $buf);
        $statusLine = $lines[0] ?? '';
        echo "OK    Got HTTP response: {$statusLine}\n";
        if (stripos($statusLine, '101') !== false) {
            echo "      => WebSocket upgrade accepted! Handshake works.\n";
            echo "      => Issue is downstream (auth, bounding box, or ship type filter).\n";
        } else {
            echo "      => Server replied but rejected upgrade. Full response:\n";
            foreach (array_slice($lines, 0, 12) as $l) {
                echo "         {$l}\n";
            }
        }
    } elseif (!$gotHeaders && microtime(true) >= $deadline) {
        echo "FAIL  Deadline exceeded — no HTTP response in {$timeout}s\n";
    }

    fclose($tls2);
    echo "\n";
}

// ── 6. curl fallback ─────────────────────────────────────────────────────────
echo "── 6. curl HTTPS to api.aisstream.io ──\n";
if (!function_exists('curl_init')) {
    echo "SKIP  curl not available\n\n";
} else {
    $ch = curl_init("https://api.aisstream.io/");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_USERAGENT      => 'hormuz-diag/1.0',
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_HEADER         => true,
    ]);
    $t0   = microtime(true);
    $out  = curl_exec($ch);
    $ms   = round((microtime(true) - $t0) * 1000);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($err) {
        echo "FAIL  curl: {$err}\n\n";
    } else {
        echo "OK    curl HTTP {$code} in {$ms}ms\n\n";
    }
}

// ── 7. .env / config sanity ──────────────────────────────────────────────────
echo "── 7. Config sanity ──\n";
$envPath = __DIR__ . '/.env';
if (!is_file($envPath)) {
    echo "WARN  .env not found at {$envPath}\n";
} else {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $tlsLine = '';
    foreach ($lines as $l) {
        if (stripos($l, 'AISSTREAM_TLS_VERIFY_PEER') !== false) {
            $tlsLine = trim($l);
        }
    }
    echo ($tlsLine !== '')
        ? "OK    .env contains: {$tlsLine}\n"
        : "WARN  AISSTREAM_TLS_VERIFY_PEER not found in .env\n";
}

if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    echo "OK    AISSTREAM_API_KEY configured: " . (AISSTREAM_API_KEY !== 'CHANGE_ME' && AISSTREAM_API_KEY !== '' ? 'yes' : 'NO — set key in .env!') . "\n";
    echo "OK    AISSTREAM_TLS_VERIFY_PEER constant: " . (defined('AISSTREAM_TLS_VERIFY_PEER') ? var_export(AISSTREAM_TLS_VERIFY_PEER, true) : 'NOT DEFINED') . "\n";
}
echo "\n";

// ── 8. CA bundle ─────────────────────────────────────────────────────────────
echo "── 8. CA bundle ──\n";
$caBundles = [
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/ca-bundle.pem',
    '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
];
foreach ($caBundles as $f) {
    if (is_readable($f)) {
        echo "OK    Found CA bundle: {$f}\n";
    }
}
$iniCaFile = ini_get('openssl.cafile');
$iniCaPath = ini_get('openssl.capath');
echo "      php.ini openssl.cafile: " . ($iniCaFile ?: '(empty)') . "\n";
echo "      php.ini openssl.capath: " . ($iniCaPath ?: '(empty)') . "\n";
echo "\n";

echo "=== DONE ===\n";