<?php
// ============================================================
//  HORMUZ MONITOR — CONFIG
//  Füll diese Werte aus bevor du die Scripts startest
// ============================================================

define('AISSTREAM_API_KEY', 'DEIN_API_KEY_HIER');   // https://aisstream.io → kostenloser Account
define('ALERT_EMAIL',       'deine@email.com');      // Wohin die Alerts gehen
define('FROM_EMAIL',        'hormuz@deinserver.com'); // Absender-Email

// Datenbank (SQLite, wird automatisch erstellt)
define('DB_PATH', __DIR__ . '/hormuz.db');

// Hormuz Bounding Box
// Southwest corner → Northeast corner
define('BBOX_SW_LAT',  25.5);
define('BBOX_SW_LON',  55.0);
define('BBOX_NE_LAT',  27.0);
define('BBOX_NE_LON',  58.0);

// Alert-Schwellenwerte
define('BASELINE_TANKERS_PER_DAY', 16);   // Normaler Durchschnitt Hormuz
define('ALERT_L1_MULTIPLIER',      1.4);  // +40% = Level 1 Alert
define('ALERT_L2_MULTIPLIER',      0.8);  // 80% der Baseline wieder = Level 2

// Wie lange der Collector läuft (Sekunden) — Cronjob alle 15min
define('COLLECTOR_RUNTIME', 240); // 4 Minuten sammeln, 11 Minuten Pause

// News RSS Feeds
define('RSS_FEEDS', [
    'Reuters World'  => 'https://feeds.reuters.com/reuters/worldNews',
    'Reuters Mideast'=> 'https://feeds.reuters.com/reuters/middleEastNews',
    'Al Jazeera'     => 'https://www.aljazeera.com/xml/rss/all.xml',
    'IRNA English'   => 'https://en.irna.ir/rss',
    'AP Top News'    => 'https://feeds.apnews.com/rss/apf-topnews',
]);

// Keywords die einen News-Alert triggern
define('NEWS_KEYWORDS_HIGH', [
    'hormuz', 'strait of hormuz', 'ceasefire', 'cease fire',
    'iran deal', 'peace talks', 'negotiations iran', 'oman mediator',
    'iran surrender', 'hormuz open', 'tanker passage',
    'trump iran deal', 'iran nuclear deal',
]);

define('NEWS_KEYWORDS_MEDIUM', [
    'iran', 'brent crude', 'opec', 'oil supply',
    'persian gulf', 'gulf shipping', 'iraq production',
    'kuwait oil', 'irgc', 'revolutionary guard',
]);
