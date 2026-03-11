#!/usr/bin/env php
<?php
/**
 * HORMUZ MONITOR — SETUP
 * Einmalig ausführen: php setup.php
 */

echo "
╔══════════════════════════════════════════════════════╗
║           HORMUZ MONITOR — SETUP                    ║
╚══════════════════════════════════════════════════════╝

";

// 1. Composer check
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "📦 Installiere Dependencies...\n";
    passthru('cd ' . __DIR__ . ' && composer install --no-dev -q');
    if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
        die("❌ Composer-Installation fehlgeschlagen. Ist Composer installiert?\n   curl -sS https://getcomposer.org/installer | php\n");
    }
    echo "✅ Dependencies installiert\n\n";
} else {
    echo "✅ Dependencies bereits vorhanden\n\n";
}

// 2. Config check
require_once __DIR__ . '/config.php';

if (AISSTREAM_API_KEY === 'DEIN_API_KEY_HIER') {
    echo "⚠️  AISSTREAM API KEY fehlt!\n";
    echo "   → Geh auf https://aisstream.io und registriere dich (kostenlos)\n";
    echo "   → Trag den Key in config.php ein\n\n";
} else {
    echo "✅ AISSTREAM API KEY gesetzt\n\n";
}

if (ALERT_EMAIL === 'deine@email.com') {
    echo "⚠️  ALERT_EMAIL nicht konfiguriert!\n";
    echo "   → Trag deine Email in config.php ein\n\n";
} else {
    echo "✅ Alert Email: " . ALERT_EMAIL . "\n\n";
}

// 3. DB initialisieren
require_once __DIR__ . '/db.php';
get_db();
echo "✅ Datenbank initialisiert: " . DB_PATH . "\n\n";

// 4. Logs-Verzeichnis
if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
    echo "✅ Logs-Verzeichnis erstellt\n\n";
}

// 5. Crontab ausgeben
$php = trim(shell_exec('which php'));
$dir = __DIR__;

echo "
══════════════════════════════════════════════════════
  CRONTAB EINTRÄGE (crontab -e)
══════════════════════════════════════════════════════

# Hormuz Monitor
# Collector: alle 15min, 4min Laufzeit
*/15 * * * * {$php} {$dir}/collector.php >> {$dir}/logs/collector.log 2>&1

# News Monitor: alle 30min
*/30 * * * * {$php} {$dir}/news_monitor.php >> {$dir}/logs/news.log 2>&1

# Alert Engine: jede Stunde
0 * * * * {$php} {$dir}/alert.php >> {$dir}/logs/alerts.log 2>&1

══════════════════════════════════════════════════════

Dashboard erreichbar unter:
  http://DEIN-SERVER/" . basename($dir) . "/dashboard.php

Setup abgeschlossen! ✅
";
