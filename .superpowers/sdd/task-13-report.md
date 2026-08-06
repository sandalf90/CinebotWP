# Task 13 Report — Nested title/event editor

## Status

**Complete** (pending CI verification — Docker gates deferred per convention).

## Files

### Created
- `includes/Admin/Pages/TitoloEditPage.php` — nested editor page (render + atomic save)
- `tests/Integration/TitoloEditPageTest.php` — 22 integration tests

### Modified
- `assets/js/cinebot-admin.js` — event/sector/price clone-remove controls
- `assets/css/cinebot-admin.css` — fieldset and nested-table styles
- `includes/Admin/Pages/TitoliListPage.php` — "Nuovo titolo" button + "Modifica" row action
- `includes/Admin/AdminMenu.php` — edit submenu + admin_post save handler
- `includes/Plugin.php` — compose TitoloEditPage with LocaleRepository
- `tests/Integration/TitoliListPageTest.php` — 4-arg AdminMenu, updated edit/new action assertions
- `tests/Integration/ApiAdminPageTest.php` — 4-arg AdminMenu

## Design

### Rendering
- `render(?int $id = null)` reads ID from parameter or `$_GET['id']`.
- New form: empty Titolo DTO, no events, templates ready for cloning.
- Edit form: loads full hierarchy (title → events → sectors → prices), pre-fills all fields.
- Three `<template>` elements: event (`__INDEX__`), sector (`__EVENT_INDEX__` + `__INDEX__`), price (`__EVENT_INDEX__` + `__SECTOR_INDEX__` + `__INDEX__`).
- `data-next-index` on each container provides monotonically increasing keys that never reuse.

### Save
- `save()` registered as `admin_post_cinebot_wp_save_titolo`.
- Capability (`manage_options`) + nonce (`cinebot_wp_save_titolo`) checked first.
- Full validation before transaction: required `titolo`, required event `inizio` + `locale_id`.
- One InnoDB transaction: title → events → sectors → prices.
- Existing IDs verified via ownership maps (loaded from `findByTitoloId` / `findByEventoId` / `findBySettoreId`). Submitted ID not in map → `RuntimeException` → rollback.
- New rows: `source=manual`, null remote IDs (`idtitolo`/`idevento`/`idsettore`/`idprezzo`), `syncActive=1`, null `lastSeenSync`.
- Existing rows: source and remote ID preserved from stored DTO; only editable fields updated.
- Removed children deleted in price → sector → event order with cascade.
- `wp_kses_post` for descrizione, `esc_url_raw` for URLs, tags parsed to unique JSON array.

### JavaScript
- Event delegation on `document.click` for add/remove buttons.
- `nextIndex()` reads and increments `data-next-index` — keys never reused.
- `cloneTemplate()` uses `<template>.content.firstElementChild.cloneNode(true)`.
- Placeholder replacement via regex: `__INDEX__`, `__EVENT_INDEX__`, `__SECTOR_INDEX__`.
- Original API page AJAX code preserved; early return removed so both sections run independently.

## Verification

### Docker
Docker is available (`v29.6.2`) but no `docker-compose.yml`, `bash`, or local PHP/composer exist. Per convention ("dynamic Docker gates deferred to CI by user decision"), tests, WPCS, PHPStan, and build are deferred to CI.

### Static review
- All output escaped: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_nonce_field`, `selected()`.
- All dynamic SQL via repository prepared statements (`$wpdb->prepare`).
- Nonce + capability on every mutation path.
- Transaction rollback on any `Throwable` within persist.
- No credentials exposed, no manual records overwritten, no unprepared SQL, no deactivation data removal.

## Test coverage (22 tests)

| Test | Requirement |
|------|-------------|
| `test_new_form_renders_with_nonce_and_all_fields` | New form has nonce + all declared fields + templates |
| `test_edit_form_renders_loaded_hierarchy` | Edit form pre-fills title + events + sectors + prices |
| `test_new_title_save_creates_with_source_manual` | New title: source=manual, null idtitolo/frontendId |
| `test_edit_api_title_keeps_source_api` | Edit API title: source=api, idtitolo preserved |
| `test_required_titolo_validation_prevents_save` | Empty titolo → no save |
| `test_atomic_save_rollback_on_invalid_child` | Event from other title → rollback, title unchanged |
| `test_removed_events_deleted` | Events not in form → deleted with cascade |
| `test_removed_sectors_deleted_under_correct_event` | Sector deletion scoped to correct event |
| `test_removed_prices_deleted_under_correct_sector` | Price deletion scoped to correct sector |
| `test_descrizione_sanitized_with_wp_kses_post` | Script/iframe stripped, p preserved |
| `test_url_escaping` | `esc_url_raw` preserves query params |
| `test_tags_become_unique_json_array` | Comma-separated → unique deduplicated array |
| `test_new_event_gets_source_manual` | New event: source=manual, null idevento |
| `test_edit_api_event_keeps_source_api` | Edit API event: source=api, idevento preserved |
| `test_new_sector_gets_source_manual` | New sector: source=manual, null idsettore |
| `test_new_price_gets_source_manual` | New price: source=manual, null idprezzo |
| `test_save_denies_non_admin` | Capability check |
| `test_save_rejects_missing_nonce` | Nonce check |
| `test_admin_menu_routes_edit_page` | `admin_post_cinebot_wp_save_titolo` registered |
| `test_list_page_has_new_and_edit_actions` | "Nuovo titolo" + "Modifica" in list page |
| `test_plugin_composes_titolo_edit_page` | Plugin injects TitoloEditPage via reflection |
| `test_list_page_edit_link_contains_id` | Edit URL contains title ID |

## Concerns

1. **No local test execution** — Docker/PHP not runnable locally; all test verification deferred to CI. Code reviewed statically for syntax, logic, escaping, and convention compliance.
2. **Hidden submenu** — edit page registered with empty menu title; standard WordPress pattern for hidden admin pages. May show as blank entry in some themes; can add `remove_submenu_page` if needed.
3. **`wp_editor()` not used** — descrizione uses a plain textarea per "wysiwyg/textarea" in brief. Can upgrade to `wp_editor()` in a future iteration if rich text editing is required.
4. **Venue pagination** — venue select loads up to 500 venues. If the venue count grows beyond this, pagination or search would be needed.
