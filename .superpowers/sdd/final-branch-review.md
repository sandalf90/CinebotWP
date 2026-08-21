# Cinebot WP — Final Branch Review

**Reviewer:** Final code reviewer
**Date:** 2026-08-08
**Branch:** `feat/cinebot-wp`
**Range:** `cdc8021..e9b4159` (31 commits, 100 files, ~15,657 insertions)
**Method:** Static review only (no Docker/PHPUnit/WPCS/PHPStan execution)

---

## Critical (Must fix before merge)

### C1. Missing `titolo-card-list` template — AJAX filter returns empty HTML

**File:** `includes/Frontend/ShortcodeHandler.php:67`, `templates/` directory

The AJAX filter handler calls `$this->renderer->render('titolo-card-list', ...)`, but only three templates exist: `programmazione-cards.php`, `titolo-card.php`, and `dettaglio-titolo.php`. `TemplateRenderer::resolve()` returns `null` for the missing template, and `render()` returns `''`. Every AJAX filter request returns `html: ''` in the JSON response, making the frontend filter/load-more feature completely non-functional.

**Fix:** Create `templates/titolo-card-list.php` that iterates `$cards` and renders each via individual `titolo-card.php` includes, or change the AJAX handler to render each card and concatenate.

### C2. `LocaliListPage` calls non-existent `countEvents()` method — fatal error

**File:** `includes/Admin/Pages/LocaliListPage.php:87`

The venue list table's `column_default` calls `$this->venues->countEvents((int) $item->id)` to display the event count per venue. `LocaleRepository` has no `countEvents` method — only `count(array $filters)` and `countByLocaleId` exists on `EventoRepository`. Visiting the Locali admin page triggers a fatal error.

**Fix:** Inject `EventoRepository` into `LocaliListPage` and call `$this->events->countByLocaleId((int) $item->id)`, or add a `countEvents` proxy to `LocaleRepository`.

### C3. DashboardPage constructor mismatch in tests — TypeError prevents test execution

**Files:** `tests/Integration/ApiAdminPageTest.php:65`, `tests/Integration/TitoliListPageTest.php:318`, `tests/Integration/TitoloEditPageTest.php:556`

`DashboardPage::__construct()` requires three arguments: `SettingsService $settings, TitoloRepository $titles, SyncLogRepository $logs`. All three test files construct it with only one argument: `new DashboardPage($settings)`. PHP 7.4 throws a `TypeError` on the missing required parameters, preventing these test classes from running at all.

**Fix:** Pass all three required arguments in each test, e.g. `new DashboardPage($settings, new TitoloRepository($wpdb), new SyncLogRepository($wpdb))`.

### C4. AdminMenu constructor called with 4 of 9 required arguments in tests — TypeError

**Files:** `tests/Integration/TitoliListPageTest.php:559`, `tests/Integration/TitoloEditPageTest.php:559`

`AdminMenu::__construct()` requires 9 arguments (DashboardPage, ApiPage, TitoliListPage, TitoloEditPage, LocaliListPage, LocaleEditPage, TipologieListPage, TipologiaEditPage, SyncLogPage). Both test files call `new AdminMenu($dashboard, $api_page, $this->list_page, $this->page)` — only 4 arguments. This produces a `TypeError` that prevents the admin menu integration tests from executing.

**Fix:** Construct all 9 page dependencies and pass them to `AdminMenu`, or refactor `AdminMenu` to accept a page registry/array.

---

## Important (Should fix before merge)

### I1. Missing planned test files — frontend and monitoring layers untested

**Plan reference:** Tasks 15, 16, 17

Three test files specified in the implementation plan were never created:
- `tests/Integration/ShortcodeHandlerTest.php` (Task 16)
- `tests/Integration/FrontendAjaxTest.php` (Task 17)
- `tests/Integration/MonitoringAdminPagesTest.php` (Task 15)

The shortcode rendering, AJAX filter, Dashboard, and SyncLog page behaviors have no test coverage. Combined with C1 (broken AJAX), this means the frontend feature was shipped without any verification.

**Fix:** Create all three test files with the assertions specified in the plan.

### I2. End-to-end test is incomplete — core scenarios not verified

**File:** `tests/Integration/CinebotEndToEndTest.php`

The plan's Task 19 specifies 11 verification steps including importing the sample fixture, asserting Dashboard counters, rendering shortcode with data, testing reconciliation (deactivate/reactivate), and stato!=3 exclusion. The actual test only covers:
- Activation (tables + 62 types) ✓
- Settings save ✓
- Shortcode render with no data ✓
- Deactivation (data remains + cron removed) ✓
- Manual title preservation ✓

Missing: actual `syncPayload()` import, shortcode with real data, reconciliation round-trip, stato!=3 filtering.

**Fix:** Implement all 11 steps from the plan's Task 19 Step 1.

### I3. `renderTitolo()` does not load events — detail shortcode is incomplete

**File:** `includes/Frontend/ShortcodeHandler.php:131-132`

The `[cinebot_titolo]` shortcode handler passes `'events' => array()` with a comment `// Events loaded via EventoRepository in full implementation`. The `dettaglio-titolo.php` template receives an empty events array, so the detail page never shows any event listings.

**Fix:** Inject `EventoRepository` into `ShortcodeHandler` and call `findByTitoloId($id)` to load events (and optionally sectors/prices) for the detail template.

### I4. `TemplateRenderer::render()` does not clean output buffer on failure

**File:** `includes/Frontend/TemplateRenderer.php:27-30`

```php
ob_start();
require $path;
return (string) ob_get_clean();
```

If `require $path` throws an exception, `ob_get_clean()` is never called and the output buffer leaks. The plan specifies "always cleans the buffer on failure."

**Fix:** Wrap in try/finally:
```php
ob_start();
try {
    require $path;
    return (string) ob_get_clean();
} catch ( Throwable $e ) {
    ob_end_clean();
    throw $e;
}
```

### I5. `programmazione-cards.php` creates `WP_Query` for non-existent post type

**File:** `templates/programmazione-cards.php:18`

```php
<?php $types = new WP_Query( array( 'post_type' => 'cinebot_tipologia' ) ); ?>
```

Event types are stored in a custom table (`cinebot_tipologie_eventi`), not as WordPress posts. This `WP_Query` always returns empty results, wastes a database query, and the `$types` variable is never used afterward (the filter uses a text input, not a select).

**Fix:** Remove the `WP_Query` line entirely. If a type `<select>` is desired, pass active types from `ShortcodeHandler` via the template context.

### I6. Price template has invalid HTML — hidden input as direct child of `<tr>`

**File:** `includes/Admin/Pages/TitoloEditPage.php:478-479`

In the `render_templates()` method, the price clone template places `<input type="hidden">` directly inside `<tr>` before the first `<td>`:

```html
<tr class="cinebot-price-row" data-price-key="__INDEX__">
    <input type="hidden" name="..." value="0" />
    <td>...</td>
```

This is invalid HTML. Browsers may relocate the input outside the table, causing the hidden field's value to not be submitted with the form. The `render_price_row()` method (for existing prices) correctly places the hidden input inside a `<td>`, creating an inconsistency between static and dynamic rows.

**Fix:** Move the `<input type="hidden">` inside the first `<td>` in the template, matching the structure of `render_price_row()`.

### I7. `TitoloEditPage::validate()` does not validate sector names or non-negative prices

**File:** `includes/Admin/Pages/TitoloEditPage.php:638-655`

The plan requires validation of "required title, event date, venue, sector name, and non-negative prices." The `validate()` method only checks:
- Title is non-empty ✓
- Event has a start time ✓
- Event has a venue ✓

Missing:
- Sector names are not validated (empty sector names pass through)
- Prices are not validated for non-negative values (invalid decimals silently become NULL via `nullable_decimal()`)

**Fix:** Add validation for sector `nome` (non-empty when sector row is present) and price `importo`/`prevendita` (non-negative decimal). Return errors and abort the save before opening the transaction.

### I8. Frontend JS load-more uses hardcoded page size of 20

**File:** `assets/js/cinebot-frontend.js:51`

```javascript
params.append( 'offset', String( ( page - 1 ) * 20 ) );
```

The shortcode's `limit` attribute can be 1–100, but the JavaScript load-more always calculates offset as `(page - 1) * 20`. If the shortcode uses `limit="50"`, the first page shows 50 items but load-more requests offset 20, causing overlapping or skipped results.

**Fix:** Read the limit from the server-rendered data attribute or the AJAX response, and use it for offset calculation. Alternatively, have the server return the next offset in the JSON response.

### I9. `LocaliListPage` has bulk delete action but no handler

**File:** `includes/Admin/Pages/LocaliListPage.php:93-95`

The venue list table declares `get_bulk_actions()` returning `array('delete' => 'Delete')`, but there is no `handle_actions()` or form submission handler to process the deletion. Selecting venues and clicking "Delete" submits a form with no effect.

**Fix:** Add a deletion handler with nonce verification, capability check, and referential integrity check (prevent deleting venues referenced by events, or cascade-delete/reassign as appropriate).

### I10. `SyncLogPage` has bulk delete action but no handler

**File:** `includes/Admin/Pages/SyncLogPage.php:83-85`

Same pattern as I9: `get_bulk_actions()` returns `array('delete' => 'Delete')` but no handler processes bulk log deletion. Only the "Pulisci log > 30 giorni" button (via `deleteOld()`) is functional.

**Fix:** Add a bulk delete handler with nonce and capability check, calling `SyncLogRepository` to delete selected IDs.

---

## Minor (Can defer)

### M1. Composition root creates duplicate service instances

**File:** `includes/Plugin.php:96-162`

The `scheduler()`, `admin_menu()`, and `shortcodes()` private methods each construct their own `SettingsService`, `ApiClient`, `SyncService`, and repositories. The plan specifies "Plugin::boot() wires exactly one instance of every repository/service/page." While functionally correct (all are stateless), this creates unnecessary objects on every page load.

**Fix:** Construct all dependencies once in `boot()` and pass them to each component.

### M2. `LocaleEditPage::render()` and `TipologiaEditPage::render()` use ternary as void statement

**Files:** `includes/Admin/Pages/LocaleEditPage.php:42`, `includes/Admin/Pages/TipologiaEditPage.php:40`

```php
<?php $id > 0 ? esc_html_e( 'Edit locale', 'cinebot-wp' ) : esc_html_e( 'New locale', 'cinebot-wp' ); ?>
```

`esc_html_e()` echoes and returns void. The ternary expression evaluates to null (discarded), but the side-effect (echoing) works correctly. This is unusual style and may trigger linting warnings. Use `if/else` or `echo $id > 0 ? esc_html__(...) : esc_html__(...)`.

### M3. `disableButtons()` in frontend JS only disables the first instance's submit button

**File:** `assets/js/cinebot-frontend.js:95-98`

```javascript
function disableButtons( disabled ) {
    var btn = document.querySelector( '.cinebot-filters [type="submit"]' );
    if ( btn ) { btn.disabled = disabled; }
}
```

`querySelector` returns the first match in the entire document. If two shortcodes are on the same page, only the first one's submit button is disabled during AJAX. Should scope to the container instance.

**Fix:** Pass the container element to `disableButtons` and use `container.querySelector`.

### M4. Shortcode AJAX handler omits `to` date filter

**File:** `includes/Frontend/ShortcodeHandler.php:53-62`

The `ajaxFilter()` method normalizes `tipo`, `comune`, `from`, `locale`, `limit`, `offset`, `order`, `orderby` from `$_POST` but does not include `to`. The shortcode supports `to` as a date filter, but AJAX filtering cannot use it.

**Fix:** Add `'to' => isset($_POST['to']) ? sanitize_text_field(wp_unslash($_POST['to'])) : ''` to the AJAX attributes.

### M5. `CronScheduler::reschedule()` registered with `accepted_args=3` but accepts only 2

**File:** `includes/Services/CronScheduler.php:39`

```php
add_action( 'update_option_cinebot_wp_settings', array( $this, 'reschedule' ), 10, 3 );
```

The `update_option_{$option}` hook passes `(mixed $old_value, mixed $value, string $option_name)` — 3 arguments. But `reschedule(array $old, array $new)` declares only 2 parameters. PHP 7.4 silently ignores extra arguments, so this works, but the third argument (option name) is wasted. Either change `accepted_args` to 2, or accept the third parameter for future use.

### M6. `languages/cinebot-wp.pot` is essentially empty (8 lines)

**File:** `languages/cinebot-wp.pot`

The `.pot` file contains only a header with no translation strings. The plan calls for generating it with WP-CLI i18n tooling. All user-facing strings use `__()`/`esc_html__()` with text domain `cinebot-wp`, but the translation template hasn't been generated.

**Fix:** Run `wp i18n make-pot . languages/cinebot-wp.pot` and commit the result.

### M7. `TipologieListPage` toggle nonce does not include the `attivo` value

**File:** `includes/Admin/Pages/TipologieListPage.php:80-87`

The toggle URL includes `id` and `attivo` in the query string, but the nonce is `cinebot_toggle_tipologia_{$id}` — it doesn't bind the `attivo` value. An attacker who can view the nonce URL could change the `attivo` parameter to the opposite value without invalidating the nonce. The impact is limited (attivo is cast to bool, only enabling/disabling an event type) and requires an authenticated admin, but the nonce should ideally bind all action parameters.

**Fix:** Include `attivo` in the nonce action: `'cinebot_toggle_tipologia_' . $id . '_' . $attivo`.

### M8. `TipologieListPage` uses `$_REQUEST['filter']` without unslashing/sanitizing in `selected()`

**File:** `includes/Admin/Pages/TipologieListPage.php:107`

```php
<option value="active" <?php selected( $_REQUEST['filter'] ?? '', 'active' ); ?>>
```

Direct access to `$_REQUEST` without `wp_unslash` and `sanitize_text_field`. WPCS would flag this. The `selected()` function does string comparison, so the risk is low, but it violates the project's input sanitization conventions.

**Fix:** `$current_filter = isset($_REQUEST['filter']) ? sanitize_text_field(wp_unslash($_REQUEST['filter'])) : '';` then use `selected($current_filter, 'active')`.

---

## Strengths

### Architecture
- Clean OOP composition root with repository/service/page separation matching the approved design
- Namespace `CinebotWp\` with committed PSR-4 autoloader — no runtime Composer dependency
- Repositories return domain DTOs, not raw rows (except the named `ProgrammazioneCard` projection boundary)
- Dependency injection throughout: `wpdb`, `SettingsService`, `ApiClient`, repositories all injected via constructors
- `Plugin::boot()` is idempotent with a booted guard and `cinebot_wp_booted` action hook

### Security
- API password encrypted at rest with AES-256-CBC + random IV + HMAC-SHA256 authentication (encrypt-then-MAC)
- Separate encryption and authentication keys derived from `AUTH_SALT` + project salt via HMAC
- `hash_equals()` used for HMAC comparison (timing-safe)
- Password never rendered in HTML — `get()` returns `has_password` boolean only
- All SQL uses `$wpdb->prepare()` with explicit format strings; table names are trusted interpolated fragments only
- Nonce verification on all admin form submissions (`check_admin_referer`) and AJAX handlers (`check_ajax_referer`)
- Capability check `manage_options` (filtered via `cinebot_wp_capability`) on every admin page and AJAX handler
- Output escaping consistently applied: `esc_html`, `esc_attr`, `esc_url`, `esc_textarea`, `wp_kses_post`
- API errors use generic safe messages — no credential or response body leakage in exceptions
- `LocandinaService` validates host/path segments against SSRF and path traversal

### Data Integrity
- All seven tables use `ENGINE=InnoDB` with `dbDelta()` for idempotent installation
- InnoDB support checked before table creation — throws if unavailable
- Schema seeding wrapped in explicit transaction with rollback on failure
- Sync operation uses single transaction with proper rollback on `Throwable`
- Reconciliation cascades: title → events → sectors → prices, using `deactivateBy*Ids()` methods
- Empty ID arrays are no-ops — never generate `IN ()` SQL
- Source validation on update: `source_for_id()` check prevents source mutation via update path
- Manual rows always skipped during sync (`source === 'manual'` early return at every hierarchy level)
- Manual rows always get `sync_active=1` and `last_seen_sync=null` on save
- Atomic sync lock via `add_option()` (atomic create) with token-based ownership and TTL expiry
- Non-owner cannot release lock (`hash_equals` token comparison)
- Expired lock can be reclaimed via compare-and-delete (`DELETE WHERE option_value = exact_stored`)

### Synchronization
- Payload validation before any persistence: structure, types, positive integer IDs
- Canonical hash for payload deduplication (sorted recursive keys)
- Title hash excludes children so child-only changes don't count as title updates
- Cache invalidation (`_transient_cinebot_prog_*`) after committed sync via prepared DELETE
- Sync log lifecycle: start → running, finish → success/error/partial with sanitized error messages

### PHP 7.4 Compatibility
- No PHP 8.0+ syntax found: no `str_contains`/`str_starts_with`/`str_ends_with`, no `match`, no constructor property promotion, no named arguments, no union types, no `readonly`
- Typed properties (PHP 7.4 feature) used correctly throughout models

### WordPress Conventions
- Hook names consistently prefixed `cinebot_wp_`
- Option names consistently prefixed `cinebot_wp_`
- Text domain `cinebot-wp` used in all i18n calls
- `apply_filters('cinebot_wp_capability', 'manage_options')` applied consistently
- Custom weekly cron schedule registered via `cron_schedules` filter
- `WP_List_Table` used for admin lists with proper pagination
- Admin assets enqueued only on Cinebot screens (`strpos($hook, 'cinebot-wp')` check)
- `uninstall.php` properly guards with `WP_UNINSTALL_PLUGIN`, drops tables, deletes options, clears transients and cron
- Deactivation only clears cron — no data deletion (matches design)

### CI/Distribution
- Docker Compose with MySQL 8 + PHP service, healthcheck, and WordPress test suite provisioning
- CI matrix with exact PHP/WP pairs: 7.4/6.0.12, 8.0/6.4.8, 8.1/6.8.6, 8.2/6.9.5
- CI runs lint, analyse, test, build, and vendor-free ZIP verification
- `tools/build.php` uses `ZipArchive` with error checking and cleanup on failure
- Distribution ZIP excludes dev-only directories (`.git`, `docker`, `tests`, `tools`, `vendor`, etc.)
- WPCS and PHPStan configuration properly set up with WordPress-specific rules

---

## Overall Assessment

**NEEDS FIXES**

The implementation demonstrates strong architectural discipline, thorough security practices, and careful data integrity handling. The synchronization engine with its atomic lock, transactional cascade reconciliation, and manual ownership preservation is well-designed. PHP 7.4 compatibility is clean throughout.

However, four critical issues block merge:

1. **Broken AJAX filter** (C1) — the `titolo-card-list` template was never created, making the entire frontend filtering feature non-functional
2. **Fatal error on venue list page** (C2) — `countEvents()` method doesn't exist
3. **Test constructor mismatches** (C3, C4) — `DashboardPage` and `AdminMenu` are constructed with fewer arguments than required, causing `TypeError` in at least 5 test classes

These critical issues suggest that the frontend/admin integration layers and the test suite were not verified end-to-end before the final commit. The missing planned test files (I1) and incomplete end-to-end test (I2) further indicate that the verification gates specified in the plan were not fully executed.

The important issues (I3–I10) represent incomplete features and validation gaps that should be addressed before release but are not merge-blockers on their own.

**Recommendation:** Fix all 4 critical issues, create the missing test files (I1), complete the end-to-end test (I2), and run the full Docker test suite (`composer check`) to verify before merge. The important issues can be addressed in a follow-up commit if time-constrained, but I3 (detail shortcode), I4 (buffer leak), I5 (WP_Query), I6 (invalid HTML), and I7 (missing validation) should ideally be included in the merge.
