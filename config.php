<?php
// Core credentials.
define('AISSTREAM_API_KEY', getenv('AISSTREAM_API_KEY') ?: 'CHANGE_ME');
define('ALERT_EMAIL', getenv('ALERT_EMAIL') ?: 'alerts@example.com');
define('FROM_EMAIL', getenv('FROM_EMAIL') ?: 'hormuz-monitor@example.com');

// SQLite database path.
define('DB_PATH', __DIR__ . '/hormuz.db');

// Strait of Hormuz monitoring box.
define('BBOX_SW_LAT', 25.5);
define('BBOX_SW_LON', 55.0);
define('BBOX_NE_LAT', 27.0);
define('BBOX_NE_LON', 58.0);

// Collector runtime and performance tuning.
define('COLLECTOR_RUNTIME', (int)(getenv('COLLECTOR_RUNTIME') ?: 50));
define('COLLECTOR_TIMEOUT_SECONDS', 25);
define('COLLECTOR_WEB_TOKEN', getenv('COLLECTOR_WEB_TOKEN') ?: 'CHANGE_ME');
define('MIN_SECONDS_BETWEEN_SIGHTINGS', 120);
define('ACTIVE_VESSEL_WINDOW_MINUTES', 90);
define('DATA_RETENTION_DAYS', 14);
define('DASHBOARD_REFRESH_SECONDS', 15);

// Signal thresholds.
define('BASELINE_TANKERS_PER_DAY', 16);
define('ALERT_L1_MULTIPLIER', 1.4);
define('ALERT_L2_MULTIPLIER', 0.8);

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
