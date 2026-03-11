# Hormuz Oil Flow Monitor

Lightweight PHP + SQLite dashboard for near-real-time tanker flow in the Strait of Hormuz.

## What this version focuses on

- Very lightweight frontend (no Leaflet, no chart libraries).
- Near-real-time updates via `api.php` polling.
- Fast data model with:
  - `vessel_latest` (one live row per tanker)
  - `tanker_sightings` (snapshots for 1h / 24h flow stats)
- cPanel-friendly deployment and cron operation.

## Files

- `collector.php`: AIS websocket collector (CLI and secured web mode).
- `api.php`: JSON endpoint for current state.
- `dashboard.php`: black/white radar UI.
- `index.php`: routes root to dashboard.
- `db.php`: schema + indexes + retention cleanup.

## cPanel deployment

1. Upload repo to your subdomain document root, for example:
   - `/home/<cpanel-user>/public_html/` for `hormuz.markusschwinghammer.com`
2. Set values in `config.php`:
   - `AISSTREAM_API_KEY`
   - `COLLECTOR_WEB_TOKEN` (only if using curl cron)
3. Install dependency once:

```bash
composer install --no-dev
```

4. Ensure `logs/` is writable by PHP.

## Cron setup (exact lines)

Use one of these two patterns.

### A) Preferred: CLI cron every minute

```cron
* * * * * /usr/local/bin/php /home/<cpanel-user>/public_html/collector.php --runtime=50 >> /home/<cpanel-user>/public_html/logs/collector.log 2>&1
```

### B) If you strongly prefer curl cron

```cron
* * * * * /usr/bin/curl -fsS "https://hormuz.markusschwinghammer.com/collector.php?token=<YOUR_COLLECTOR_WEB_TOKEN>&runtime=45" >/dev/null
```

Notes:
- Runtime is capped in code to avoid overlaps.
- Collector lock file prevents double runs.

## Dashboard URL

- `https://hormuz.markusschwinghammer.com/`

## Quick verification

- Open `https://hormuz.markusschwinghammer.com/api.php`
- Check `collector_online` and `collector_delay_seconds` in JSON.
- Open dashboard and confirm dots appear on map when collector is running.
