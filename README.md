# Cinebot WP

WordPress plugin that imports event schedules from the Cinebot API into custom relational tables, provides native admin CRUD, and renders public schedules and detail pages via shortcodes.

## Features

- **Read-only sync** — imports from Cinebot API; never writes back
- **Reconciliation** — API rows absent from a sync are deactivated, not deleted; reactivated on return
- **Manual ownership** — records created manually in WP are never overwritten by sync
- **Atomic transactions** — all hierarchy saves and syncs use InnoDB transactions
- **Encrypted credentials** — AES-256-CBC with HMAC authentication
- **WP-Cron** — configurable hourly/daily/weekly sync with atomic lock
- **State-3 visibility** — only events with API status `3` are shown publicly
- **Filterable frontend** — AJAX filtering by date range and venue, with URL sync

## Requirements

- PHP 7.4+
- WordPress 6.0+
- MySQL/MariaDB with InnoDB

## Installation

1. Build the ZIP artifact: `docker compose run --rm php composer build`
2. Upload `dist/cinebot-wp.zip` via WordPress admin (Plugins → Add New → Upload)
3. Activate the plugin
4. Navigate to **Cinebot → API** in the admin menu
5. Enter your Cinebot API credentials and configure sync frequency
6. Enable scheduled synchronization

## Shortcodes

### `[cinebot_programmazione]`

Display upcoming events as filterable cards.

**Attributes:**

| Attribute | Description | Default |
|---|---|---|
| `from` | Start date (YYYY-MM-DD) | today |
| `to` | End date (YYYY-MM-DD) | — |
| `locale` | Venue ID | 0 (all) |
| `limit` | Max results per page (1-100) | 50 |
| `orderby` | Sort field: `inizio` or `titolo` | `inizio` |
| `order` | Sort direction: `ASC` or `DESC` | `ASC` |
| `show_filters` | Show AJAX filter form | `true` |
| `show_desc` | Show description in cards | `false` |
| `pagination` | `ajax` or `numbered` | `ajax` |
| `per_page` | Items per page (numbered pagination) | `limit` |
| `more_url` | URL for "Vedi altro" link | — |
| `more_label` | Label for "Vedi altro" link | `Vedi altro` |
| `detail_url` | Detail page URL (relative or absolute) | — |
| `detail_page_id` | WordPress page ID for detail page | — |

**Examples:**
```
[cinebot_programmazione from="2026-10-01" limit="20"]
[cinebot_programmazione locale="3" show_desc="true"]
[cinebot_programmazione detail_url="/spettacolo"]
```

### `[cinebot_titolo]`

Display a single title with full details (two-column layout: poster + info, icon list, events table).

**Attributes:**
- `id` — Title ID (optional; auto-detected from `titolo_id` URL parameter on detail pages)

**Example:**
```
[cinebot_titolo id="123"]
```

### Detail page granular shortcodes

For custom detail page layouts, individual fields are available as separate shortcodes. They auto-detect the title ID from the `titolo_id` URL parameter.

| Shortcode | Output |
|---|---|
| `[cinebot_titolo_titolo]` | Title text |
| `[cinebot_titolo_autore]` | Author |
| `[cinebot_titolo_esecutore]` | Performer |
| `[cinebot_titolo_giorno]` | Day(s) — single: "Giovedì 18/10"; range: "Da ... a ..." |
| `[cinebot_titolo_durata]` | Duration — single event: "dalle 21:00 alle 23:00"; multiple: "120 minuti" |
| `[cinebot_titolo_prezzo]` | Price — single: "€ 20.00 + d.d.p."; range: "Da € 25.00 a € 35.00 +d.d.p." |
| `[cinebot_titolo_locale]` | Venue name(s) |
| `[cinebot_titolo_descrizione]` | Description (rich text) |
| `[cinebot_titolo_immagine]` | Poster image (`class` and `alt` attributes supported) |
| `[cinebot_titolo_eventi]` | Events table (locale, data, ora, link acquisto) |

## Admin Sections

1. **Dashboard** — sync status, counters, recent logs, quick links
2. **API** — credentials, frontend ID, sync frequency, enable/disable cron, test connection, sync now, GitHub token for updates
3. **Programmazioni** — CRUD for titles with nested events/sectors/prices
4. **Locali** — venue CRUD
5. **Tipologie evento** — event type management (62 predefined + custom)
6. **Log sincronizzazioni** — sync history with >30-day cleanup

## Automatic Updates

The plugin checks for updates from GitHub Releases automatically. For private repositories, a GitHub Personal Access Token (scope: `repo`) is required:

1. Generate a token at [GitHub Settings → Tokens](https://github.com/settings/tokens)
2. Navigate to **Cinebot → API** in WordPress admin
3. Enter the token in the **GitHub Token** field
4. Save settings

When a new release is published on GitHub, the plugin will show an update notification in the WordPress admin dashboard.

## Versioning

This project uses [Conventional Commits](https://www.conventionalcommits.org/) and [release-please](https://github.com/googleapis/release-please) for automated versioning:

- `feat:` commits trigger a **minor** version bump
- `fix:` commits trigger a **patch** version bump
- `BREAKING CHANGE:` or `feat!:` triggers a **major** version bump
- Other commit types (`chore:`, `docs:`, etc.) do not trigger a release

When changes are merged to `main`, release-please automatically creates a release PR with the changelog and version bump. Merging that PR creates a Git tag and GitHub Release with the built ZIP attached.

## Development

```bash
docker compose build                         # Build PHP image
docker compose run --rm php composer install # Install dependencies
docker compose run --rm php composer test    # Run all tests
docker compose run --rm php composer lint    # WPCS lint
docker compose run --rm php composer analyse # PHPStan analysis
docker compose run --rm php composer build    # Build ZIP artifact
docker compose run --rm php composer check    # Full gate (lint + analyse + test + build)
```

## Tech Stack

- PHP 7.4+, WordPress 6.0+
- MySQL/MariaDB InnoDB via `$wpdb`
- Composer (dev only), PSR-4 autoloader committed in plugin
- PHPUnit 9, WPCS, PHPStan for WordPress
- Vanilla JavaScript (no build tool)

## Notes

- **WP-Cron** is traffic-driven: scheduled syncs fire when the site receives visits. For reliable scheduling, configure a server-side cron job that hits `wp-cron.php`.
- **Single-site only**: WordPress Multisite is out of scope for v1.0.
- **Uninstalling** from WordPress removes all 7 plugin tables, options, transients, and scheduled events. Deactivation only clears the cron; data is preserved.
