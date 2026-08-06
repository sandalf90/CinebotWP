# Cinebot WP

WordPress 6 plugin that imports Cinebot schedules into custom relational tables, provides native WordPress administration, and renders public schedules through shortcodes.

## Installation

1. Build the ZIP artifact: `docker compose run --rm php composer build`
2. Upload `dist/cinebot-wp.zip` via WordPress admin (Plugins → Add New → Upload)
3. Activate the plugin
4. Navigate to Cinebot → API in the admin menu
5. Enter your Cinebot API credentials and configure sync frequency
6. Enable scheduled synchronization

## Docker Commands

```bash
docker compose build                    # Build PHP image
docker compose run --rm php composer install  # Install dependencies
docker compose run --rm php composer test      # Run all tests
docker compose run --rm php composer lint       # WPCS lint
docker compose run --rm php composer analyse    # PHPStan analysis
docker compose run --rm php composer build      # Build ZIP artifact
docker compose run --rm php composer check      # Full gate (lint + analyse + test + build)
```

## Shortcodes

### `[cinebot_programmazione]`

Display upcoming events as filterable cards.

**Attributes:**
- `tipo` — Event type code (e.g. `45` for Teatro Prosa)
- `locale` — Venue ID
- `comune` — City name
- `from` — Start date (YYYY-MM-DD, default: today)
- `to` — End date
- `limit` — Max results (1-100, default: 50)
- `orderby` — Sort field (`inizio` or `titolo`, default: `inizio`)
- `order` — Sort direction (`ASC` or `DESC`, default: `ASC`)
- `show_filters` — Show AJAX filter form (default: true)
- `show_desc` — Show description in cards (default: false)

**Examples:**
```
[cinebot_programmazione tipo="45" from="2026-10-01" limit="20"]
[cinebot_programmazione comune="Bassano del Grappa" show_desc="true"]
```

### `[cinebot_titolo id="123"]`

Display a single title with full details.

## Admin Sections

1. **Dashboard** — Sync status, counters, recent logs, quick links
2. **API** — Credentials, frontend ID, sync frequency, enable/disable cron, test connection, sync now
3. **Programmazioni** — CRUD for titles with nested events/sectors/prices
4. **Locali** — Venue CRUD
5. **Tipologie evento** — Event type management (62 predefined + custom)
6. **Log sincronizzazioni** — Sync history with >30-day cleanup

## Key Features

- **Read-only sync**: Import only; never writes back to Cinebot API
- **Reconciliation**: API rows absent from sync are deactivated, not deleted; reactivated on return
- **Manual ownership**: Records created manually in WP are never modified by sync
- **Atomic transactions**: All hierarchy saves and syncs use InnoDB transactions
- **Encrypted credentials**: AES-256-CBC with HMAC authentication
- **WP-Cron**: Configurable hourly/daily/weekly sync with atomic lock
- **State-3 visibility**: Only events with API status `3` are shown publicly
- **Single-site only**: WordPress Multisite is out of scope for v1.0

## Cron Note

WordPress WP-Cron is traffic-driven: scheduled syncs fire when the site receives visits. For reliable scheduling, consider configuring a server-side cron job that hits `wp-cron.php`.

## Uninstall

Uninstalling from WordPress removes all 7 plugin tables, options, transients, and scheduled events. Deactivation only clears the cron; data is preserved.

## Tech Stack

- PHP 7.4+, WordPress 6.0+
- MySQL/MariaDB InnoDB via `$wpdb`
- Composer (dev only), PSR-4 autoloader committed in plugin
- PHPUnit 9, WPCS, PHPStan for WordPress
- Vanilla JavaScript (no build tool)
