<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if (!is_dir(__DIR__ . '/logs')) {
    mkdir(__DIR__ . '/logs', 0755, true);
}

$db = get_db();
prune_old_data($db);

$phpBinary = trim((string)shell_exec('which php')) ?: '/usr/local/bin/php';
$basePath = __DIR__;

echo "\nHormuz monitor setup\n";
echo "====================\n\n";

echo "1) Configure .env:\n";
echo "   cp .env.example .env\n";
echo "   then set AISSTREAM_API_KEY\n\n";

echo "2) Install dependencies once:\n";
echo "   composer install --no-dev\n\n";

echo "3) cPanel cron (CLI only, every 15 minutes):\n";
echo "   */15 * * * * {$phpBinary} {$basePath}/collector.php --runtime=50 >> {$basePath}/logs/collector.log 2>&1\n\n";

echo "4) Dashboard URL:\n";
echo "   https://hormuz.markusschwinghammer.com/\n\n";

echo "5) UI refresh interval:\n";
echo "   DASHBOARD_REFRESH_SECONDS=900 in .env (15 minutes)\n\n";

echo "Done.\n";
