#!/usr/bin/env php
<?php
/**
 * HORMUZ MONITOR — AIS COLLECTOR
 * 
 * Verbindet sich mit aisstream.io WebSocket, sammelt Tanker-Daten
 * aus der Hormuz Bounding Box und speichert sie in SQLite.
 * 
 * Cron: * /15 * * * * /usr/bin/php /path/to/collector.php >> /path/to/logs/collector.log 2>&1
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

use WebSocket\Client;

$log_prefix = '[' . date('Y-m-d H:i:s') . '] COLLECTOR';
echo "{$log_prefix} Start — sammle {" . COLLECTOR_RUNTIME . "}s lang\n";

$db = get_db();
$start_time  = time();
$seen_count  = 0;
$tanker_count = 0;

try {
    $client = new Client('wss://stream.aisstream.io/v0/stream', [
        'timeout' => 30,
    ]);

    // Subscription Message — nur Tanker (ship_type 80-89) im Hormuz-Gebiet
    $subscription = [
        'Apikey'             => AISSTREAM_API_KEY,
        'BoundingBoxes'      => [[
            [BBOX_SW_LAT, BBOX_SW_LON],
            [BBOX_NE_LAT, BBOX_NE_LON],
        ]],
        'FilterMessageTypes' => ['PositionReport', 'ShipStaticData'],
    ];

    $client->send(json_encode($subscription));
    echo "{$log_prefix} WebSocket verbunden, Subscription gesendet\n";

    while ((time() - $start_time) < COLLECTOR_RUNTIME) {
        try {
            $message_raw = $client->receive();
            if (!$message_raw) continue;

            $msg = json_decode($message_raw, true);
            if (!$msg || !isset($msg['MessageType'])) continue;

            $meta = $msg['MetaData'] ?? [];
            $mmsi = $meta['MMSI'] ?? null;
            if (!$mmsi) continue;

            $seen_count++;

            // Position Report verarbeiten
            if ($msg['MessageType'] === 'PositionReport') {
                $pos       = $msg['Message']['PositionReport'] ?? [];
                $lat       = (float)($meta['latitude']  ?? $pos['Latitude']  ?? 0);
                $lon       = (float)($meta['longitude'] ?? $pos['Longitude'] ?? 0);
                $cog       = (float)($pos['Cog']        ?? 0);
                $sog       = (float)($pos['Sog']        ?? 0);
                $nav_status = (int)($pos['NavigationalStatus'] ?? 0);
                $ship_name = trim($meta['ShipName'] ?? 'UNKNOWN');

                // Ship Type aus MetaData (kommt manchmal mit)
                $ship_type = (int)($meta['ShipType'] ?? 0);

                // Nur Tanker speichern (wenn Typ bekannt)
                // Wenn Typ 0 (unbekannt), trotzdem speichern — wird später gefiltert
                if ($ship_type > 0 && !is_tanker($ship_type)) continue;

                $direction = get_direction($cog, $nav_status);

                if ($ship_type > 0) $tanker_count++;

                // In DB einfügen (nur wenn letzter Eintrag für dieses Schiff älter als 30min)
                $stmt = $db->prepare("
                    SELECT seen_at FROM tanker_sightings 
                    WHERE mmsi = ? ORDER BY seen_at DESC LIMIT 1
                ");
                $stmt->execute([$mmsi]);
                $last = $stmt->fetchColumn();

                if (!$last || (time() - strtotime($last)) > 1800) {
                    $insert = $db->prepare("
                        INSERT INTO tanker_sightings 
                            (mmsi, ship_name, ship_type, latitude, longitude, cog, sog, nav_status, direction)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $insert->execute([
                        $mmsi, $ship_name, $ship_type,
                        $lat, $lon, $cog, $sog, $nav_status, $direction
                    ]);

                    $icon = match($direction) {
                        'OUTBOUND' => '🟢',
                        'INBOUND'  => '🔵',
                        'ANCHORED' => '🔴',
                        default    => '⚪',
                    };
                    echo "{$log_prefix} {$icon} {$ship_name} (MMSI:{$mmsi}) Type:{$ship_type} COG:{$cog}° → {$direction}\n";
                }
            }

            // ShipStaticData — Update Ship Type wenn bekannt
            if ($msg['MessageType'] === 'ShipStaticData') {
                $static    = $msg['Message']['ShipStaticData'] ?? [];
                $ship_type = (int)($static['Type'] ?? 0);
                $ship_name = trim($static['Name'] ?? '');

                if ($ship_type > 0 && $ship_name) {
                    $db->prepare("
                        UPDATE tanker_sightings SET ship_type = ?, ship_name = ?
                        WHERE mmsi = ? AND ship_type = 0
                    ")->execute([$ship_type, $ship_name, $mmsi]);
                }
            }

        } catch (\WebSocket\TimeoutException $e) {
            // Normal bei wenig Traffic — einfach weitermachen
            continue;
        } catch (\WebSocket\ConnectionException $e) {
            echo "{$log_prefix} ERROR WebSocket: " . $e->getMessage() . "\n";
            break;
        }
    }

    $client->close();

} catch (\Exception $e) {
    echo "{$log_prefix} FATAL: " . $e->getMessage() . "\n";
    exit(1);
}

// Stündliche Stats aktualisieren
$hour_bucket = date('Y-m-d H:00:00');
$since = date('Y-m-d H:i:s', strtotime('-1 hour'));

$stats_stmt = $db->prepare("
    SELECT 
        COUNT(DISTINCT mmsi) as total,
        SUM(CASE WHEN direction='OUTBOUND' THEN 1 ELSE 0 END) as outbound,
        SUM(CASE WHEN direction='INBOUND'  THEN 1 ELSE 0 END) as inbound,
        SUM(CASE WHEN direction='ANCHORED' THEN 1 ELSE 0 END) as anchored
    FROM tanker_sightings 
    WHERE seen_at > ? AND ship_type BETWEEN 80 AND 89
");
$stats_stmt->execute([$since]);
$hour_stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

$db->prepare("
    INSERT OR REPLACE INTO hourly_stats 
        (hour_bucket, tanker_count, outbound_count, inbound_count, anchored_count)
    VALUES (?, ?, ?, ?, ?)
")->execute([
    $hour_bucket,
    $hour_stats['total']    ?? 0,
    $hour_stats['outbound'] ?? 0,
    $hour_stats['inbound']  ?? 0,
    $hour_stats['anchored'] ?? 0,
]);

echo "{$log_prefix} Fertig — {$seen_count} Messages, {$tanker_count} neue Tanker gespeichert\n";
