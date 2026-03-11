#!/usr/bin/env php
<?php
/**
 * HORMUZ MONITOR — NEWS MONITOR
 * 
 * Checkt RSS-Feeds auf relevante Keywords und speichert in DB.
 * 
 * Cron: * /30 * * * * /usr/bin/php /path/to/news_monitor.php >> /path/to/logs/news.log 2>&1
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$log_prefix = '[' . date('Y-m-d H:i:s') . '] NEWS';
echo "{$log_prefix} Starte RSS-Scan...\n";

$db  = get_db();
$new_items = 0;
$alerts    = 0;

foreach (RSS_FEEDS as $feed_name => $feed_url) {
    echo "{$log_prefix} Checke: {$feed_name}\n";
    
    try {
        $ctx = stream_context_create([
            'http' => [
                'timeout'    => 10,
                'user_agent' => 'HormuzMonitor/1.0 (geopolitical research tool)',
            ]
        ]);

        $xml_raw = @file_get_contents($feed_url, false, $ctx);
        if (!$xml_raw) {
            echo "{$log_prefix} WARN: Konnte {$feed_url} nicht laden\n";
            continue;
        }

        // XML parsen (Fehler unterdrücken für kaputte Feeds)
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_raw);
        if (!$xml) {
            echo "{$log_prefix} WARN: XML Parse-Fehler bei {$feed_name}\n";
            continue;
        }

        // Sowohl RSS als auch Atom unterstützen
        $items = $xml->channel->item ?? $xml->entry ?? [];

        foreach ($items as $item) {
            $title   = (string)($item->title ?? '');
            $link    = (string)($item->link  ?? $item->id ?? '');
            $pub_date_raw = (string)($item->pubDate ?? $item->published ?? date('r'));
            $pub_date = date('Y-m-d H:i:s', strtotime($pub_date_raw));

            if (!$title || !$link) continue;

            // Keyword-Check (case-insensitive)
            $title_lower = strtolower($title);
            $matched_keyword = null;
            $alert_level     = 0;

            foreach (NEWS_KEYWORDS_HIGH as $kw) {
                if (str_contains($title_lower, strtolower($kw))) {
                    $matched_keyword = $kw;
                    $alert_level     = 2;
                    break;
                }
            }

            if (!$matched_keyword) {
                foreach (NEWS_KEYWORDS_MEDIUM as $kw) {
                    if (str_contains($title_lower, strtolower($kw))) {
                        $matched_keyword = $kw;
                        $alert_level     = 1;
                        break;
                    }
                }
            }

            if (!$matched_keyword) continue;

            // In DB einfügen (UNIQUE auf link → kein Doppelt)
            try {
                $db->prepare("
                    INSERT OR IGNORE INTO news_items 
                        (feed_name, title, link, pub_date, keyword, alert_level)
                    VALUES (?, ?, ?, ?, ?, ?)
                ")->execute([$feed_name, $title, $link, $pub_date, $matched_keyword, $alert_level]);

                if ($db->lastInsertId()) {
                    $new_items++;
                    $icon = $alert_level === 2 ? '🔴' : '🟡';
                    echo "{$log_prefix} {$icon} [{$feed_name}] {$title} (Keyword: {$matched_keyword})\n";
                    if ($alert_level === 2) $alerts++;
                }
            } catch (PDOException $e) {
                // UNIQUE constraint — bereits bekannt, skip
            }
        }

    } catch (\Exception $e) {
        echo "{$log_prefix} ERROR bei {$feed_name}: " . $e->getMessage() . "\n";
    }
}

echo "{$log_prefix} Fertig — {$new_items} neue Items, {$alerts} High-Priority\n";
