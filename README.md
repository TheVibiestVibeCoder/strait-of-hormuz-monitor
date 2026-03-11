# Hormuz Oil Flow Monitor

Lightweight PHP + SQLite dashboard for tanker flow in the Strait of Hormuz.

## What this version focuses on

- Clean dashboard stats + live map.
- Leaflet map with the monitoring box drawn in.
- Manual refresh button.
- Collapsible debug panel (default collapsed) with API/collector diagnostics.
- Auto-refresh every 15 minutes (configurable).
- cPanel-friendly deployment and cron operation.

## Files

- `collector.php`: AIS websocket collector (CLI cron worker).
- `api.php`: JSON endpoint for current state.
- `dashboard.php`: map + stats UI.
- `index.php`: routes root to dashboard.
- `db.php`: schema + indexes + retention cleanup.
- `.env.example`: environment template.

## cPanel deployment

1. Upload repo to your subdomain document root.
2. Create env file:

```bash
cp .env.example .env
```

3. Set at least this value in `.env`:
- `AISSTREAM_API_KEY`

4. Install dependency once:

```bash
composer install --no-dev
```

5. Ensure `logs/` is writable by PHP.

## Cron setup (exact lines)

Use CLI cron to execute `collector.php`. Do not use `curl` for AISStream.
`collector.php` is intentionally blocked for HTTP access and must run from CLI.

### cPanel cron every 15 minutes

```cron
*/15 * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/collector.php --runtime=50 >> /home/<cpanel-user>/public_html/logs/collector.log 2>&1
```

Alternative PHP path (if needed):
```cron
*/15 * * * * /usr/bin/php /home/<cpanel-user>/public_html/collector.php --runtime=50 >> /home/<cpanel-user>/public_html/logs/collector.log 2>&1
```

## Dashboard refresh behavior

- Auto refresh is controlled by `DASHBOARD_REFRESH_SECONDS`.
- Default is `900` seconds (15 minutes).
- Manual refresh button: `Refresh now` in the top bar.

## Dashboard URL

- `https://hormuz.markusschwinghammer.com/`

## Quick verification

- Open `https://hormuz.markusschwinghammer.com/api.php`
- Check `collector_online` and `collector_delay_seconds` in JSON.
- Open dashboard and verify marker updates.
