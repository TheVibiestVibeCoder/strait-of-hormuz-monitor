<?php

load_env_file(__DIR__ . '/.env');

// Core credentials.
define('AISSTREAM_API_KEY', env_str('AISSTREAM_API_KEY', 'CHANGE_ME'));
define('ALERT_EMAIL', env_str('ALERT_EMAIL', 'alerts@example.com'));
define('FROM_EMAIL', env_str('FROM_EMAIL', 'hormuz-monitor@example.com'));

define('COLLECTOR_WEB_TOKEN', env_str('COLLECTOR_WEB_TOKEN', 'CHANGE_ME'));

// SQLite database path.
define('DB_PATH', env_path('DB_PATH', __DIR__ . '/hormuz.db'));

// Strait of Hormuz monitoring box.
define('BBOX_SW_LAT', env_float('BBOX_SW_LAT', 25.5));
define('BBOX_SW_LON', env_float('BBOX_SW_LON', 55.0));
define('BBOX_NE_LAT', env_float('BBOX_NE_LAT', 27.0));
define('BBOX_NE_LON', env_float('BBOX_NE_LON', 58.0));

// Collector runtime and performance tuning.
define('COLLECTOR_RUNTIME', env_int('COLLECTOR_RUNTIME', 50));
define('COLLECTOR_TIMEOUT_SECONDS', env_int('COLLECTOR_TIMEOUT_SECONDS', 25));
define('MIN_SECONDS_BETWEEN_SIGHTINGS', env_int('MIN_SECONDS_BETWEEN_SIGHTINGS', 120));
define('ACTIVE_VESSEL_WINDOW_MINUTES', env_int('ACTIVE_VESSEL_WINDOW_MINUTES', 90));
define('DATA_RETENTION_DAYS', env_int('DATA_RETENTION_DAYS', 14));
define('DASHBOARD_REFRESH_SECONDS', env_int('DASHBOARD_REFRESH_SECONDS', 900));

// Signal thresholds.
define('BASELINE_TANKERS_PER_DAY', env_int('BASELINE_TANKERS_PER_DAY', 16));
define('ALERT_L1_MULTIPLIER', env_float('ALERT_L1_MULTIPLIER', 1.4));
define('ALERT_L2_MULTIPLIER', env_float('ALERT_L2_MULTIPLIER', 0.8));

// Optional news monitoring config (kept for compatibility).
define('RSS_FEEDS', [
    'Reuters World' => 'https://feeds.reuters.com/reuters/worldNews',
    'Reuters Middle East' => 'https://feeds.reuters.com/reuters/middleEastNews',
    'Al Jazeera' => 'https://www.aljazeera.com/xml/rss/all.xml',
    'IRNA English' => 'https://en.irna.ir/rss',
    'AP Top News' => 'https://feeds.apnews.com/rss/apf-topnews',
]);

define('NEWS_KEYWORDS_HIGH', [
    'hormuz', 'strait of hormuz', 'ceasefire', 'cease fire',
    'iran deal', 'peace talks', 'negotiations iran', 'oman mediator',
    'iran surrender', 'hormuz open', 'tanker passage',
    'iran nuclear deal',
]);

define('NEWS_KEYWORDS_MEDIUM', [
    'iran', 'brent crude', 'opec', 'oil supply',
    'persian gulf', 'gulf shipping', 'iraq production',
    'kuwait oil', 'irgc', 'revolutionary guard',
]);

function load_env_file(string $path): void {
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        if ($key === '') {
            continue;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function env_str(string $key, string $default): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    return (string)$value;
}

function env_int(string $key, int $default): int {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (int)$value;
}

function env_float(string $key, float $default): float {
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (float)$value;
}

function env_path(string $key, string $default): string {
    $raw = env_str($key, $default);

    if (preg_match('/^[a-zA-Z]:\\\\/', $raw) === 1 || str_starts_with($raw, '/')) {
        return $raw;
    }

    return __DIR__ . '/' . ltrim($raw, '/\\');
}
