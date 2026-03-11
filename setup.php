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
$cronToken = 'REPLACE_WITH_LONG_RANDOM_TOKEN';

echo "\nHormuz monitor setup\n";
echo "====================\n\n";

echo "1) Configure secrets in config.php (or env):\n";
echo "   - AISSTREAM_API_KEY\n";
echo "   - COLLECTOR_WEB_TOKEN (only needed for curl cron mode)\n";
echo "\n";

echo "2) Install dependencies once:\n";
echo "   composer install --no-dev\n\n";

echo "3) Recommended cPanel cron (CLI, every minute):\n";
echo "   * * * * * {$phpBinary} {$basePath}/collector.php --runtime=50 >> {$basePath}/logs/collector.log 2>&1\n\n";

echo "4) Alternative cPanel cron (curl mode, every minute):\n";
echo "   * * * * * /usr/bin/curl -fsS \"https://hormuz.markusschwinghammer.com/collector.php?token={$cronToken}&runtime=45\" >/dev/null\n\n";

echo "5) Dashboard URL:\n";
echo "   https://hormuz.markusschwinghammer.com/\n\n";

echo "Done.\n";
