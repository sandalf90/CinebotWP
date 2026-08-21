# Task 11 Review — Admin menu, API settings page, sync controls

**Reviewer:** Fresh reviewer (clean context)
**Commit:** `fd86a2e` (parent: `3c7f0e9`)
**Date:** 2026-08-06

## Scope

Reviewed files only:
- `includes/Admin/AdminMenu.php`
- `includes/Admin/Pages/DashboardPage.php`
- `includes/Admin/Pages/ApiPage.php`
- `assets/js/cinebot-admin.js`
- `assets/css/cinebot-admin.css`
- `tests/Integration/ApiAdminPageTest.php`
- `includes/Plugin.php` (changes)

Cross-referenced against: `CONVENTIONS.md`, `.superpowers/sdd/task-11-brief.md`, `SettingsService.php`, `CronScheduler.php`, `SyncService.php`, `SyncResult.php`, `ApiClient.php`, `ApiException.php`, `SyncLock.php`.

Docker gates were NOT run per instructions. Findings are from static analysis.

---

## Spec compliance

| Brief requirement | Status | Notes |
|---|---|---|
| Top-level Cinebot menu + Dashboard + API submenu only | PASS | No later-task pages registered |
| Capability `apply_filters('cinebot_wp_capability', 'manage_options')` | PASS | Both AdminMenu and ApiPage use filtered capability |
| API form: username, password, frontend, frequency, enable checkbox | PASS | All fields present, types correct |
| Password hidden when set, empty preserves existing | PASS | No `value` attr on password input; SettingsService preserves existing on empty |
| Nonce + capability on save | PASS | `check_admin_referer` + `current_user_can` |
| Nonce + capability on AJAX handlers | PASS | `check_ajax_referer` + `current_user_can`, 403 on failure |
| Save through SettingsService then CronScheduler::reschedule() | PASS | Integration calls correct, though double-fired (see C-4) |
| Test connection: ApiClient with current settings, no persistence | PASS | `new ApiClient($this->settings)`, no save call |
| Sync now: SyncService::sync(), SyncLock rate limit | PARTIAL | Calls sync() correctly, but SyncService lacks ApiClient (see S-1) |
| Assets enqueued only on Cinebot screens | PASS | `strpos($hook, 'cinebot-wp')` guard |
| JS uses fetch() + FormData, ARIA live region | PASS | `role="status" aria-live="polite"` on status div |
| DashboardPage concrete (not placeholder) | PASS | Renders sync status + API link |
| All output escaped | PARTIAL | DashboardPage `$next_label` false branch unescaped (see S-2) |
| No credentials in HTML / no Authorization in responses | PASS | Password never rendered; AJAX responses carry safe messages only |
| AJAX actions registered and reachable | **FAIL** | Actions registered inside `admin_menu` callback, never fire during AJAX/admin-post requests (see M-1) |
| PHP 7.4 / WPCS | PASS | No 8.0+ syntax; tabs, snake_case, docblocks |

**Spec: FAIL** — the AJAX and admin-post actions are unreachable in production (M-1).

---

## Findings

### Must-fix (1)

#### M-1: AJAX and admin-post actions registered inside `admin_menu` callback — unreachable in production

**Location:** `includes/Admin/AdminMenu.php:69-71`

```php
add_action( 'admin_post_cinebot_wp_save_api', array( $this->api_page, 'save' ) );
add_action( 'wp_ajax_cinebot_wp_test_connection', array( $this->api_page, 'testConnection' ) );
add_action( 'wp_ajax_cinebot_wp_sync_now', array( $this->api_page, 'syncNow' ) );
```

These three `add_action` calls live inside `AdminMenu::add_menu()`, which is the callback for the `admin_menu` hook (registered in `register()` at line 33). The `admin_menu` hook fires in `wp-admin/menu.php`, which is loaded by `wp-admin/admin.php` during normal admin page rendering.

Neither `wp-admin/admin-ajax.php` nor `wp-admin/admin-post.php` loads `wp-admin/admin.php`. Consequently:

- **Save (admin-post.php):** When the form submits to `admin-post.php`, the `admin_menu` hook has NOT fired, so `admin_post_cinebot_wp_save_api` is not registered. WordPress returns an empty response. Settings are never saved.
- **Test connection (admin-ajax.php):** The `wp_ajax_cinebot_wp_test_connection` action is not registered. WordPress returns `0`.
- **Sync now (admin-ajax.php):** Same — `wp_ajax_cinebot_wp_sync_now` is not registered. Returns `0`.

The hooks ARE registered during a normal admin page visit (when `admin_menu` fires), but WordPress's hook system is per-request — hooks registered in one request are not available in the next. The AJAX/form-submission requests are separate HTTP requests that never trigger `admin_menu`.

**Fix:** Move the three `add_action` calls from `add_menu()` to `register()`:

```php
public function register(): void {
    add_action( 'admin_menu', array( $this, 'add_menu' ) );
    add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    add_action( 'admin_post_cinebot_wp_save_api', array( $this->api_page, 'save' ) );
    add_action( 'wp_ajax_cinebot_wp_test_connection', array( $this->api_page, 'testConnection' ) );
    add_action( 'wp_ajax_cinebot_wp_sync_now', array( $this->api_page, 'syncNow' ) );
}
```

---

### Should-fix (7)

#### S-1: SyncService created without ApiClient — "Sync now" always fails

**Location:** `includes/Plugin.php:94`

```php
new ApiPage( $settings, $scheduler, new SyncService( $wpdb ) )
```

`SyncService::__construct()` accepts `?ApiClient $api = null`. When `null`, `sync()` throws `RuntimeException('Synchronization API client is unavailable.')` internally, which is caught and returns `SyncResult('error', [], 'Schedule synchronization failed.')`. The AJAX handler then returns `{success: false}`.

The user clicks "Synchronize now" and always receives an error, even with valid stored credentials. The same issue affects the cron scheduler composition (`scheduler()` at line 82), but that is pre-existing. Task 11 exposes it through a user-facing button.

**Fix:** Inject an ApiClient: `new SyncService( $wpdb, new ApiClient( $settings ) )`.

#### S-2: DashboardPage `$next_label` escaping inconsistency

**Location:** `includes/Admin/Pages/DashboardPage.php:31-32, 47`

```php
$next_label = $next_sync
    ? esc_html( gmdate( 'Y-m-d H:i', (int) $next_sync ) . ' UTC' )
    : __( 'Not scheduled', 'cinebot-wp' );
// ...
<?php echo $next_label; // phpcs:ignore ... -- escaped above. ?>
```

The true branch is escaped with `esc_html()`; the false branch returns an unescaped `__()` string. The `phpcs:ignore` comment says "escaped above" but only one branch is escaped. While the literal string 'Not scheduled' is safe, the pattern violates CONVENTIONS.md ("escape output at rendering time") and could introduce a vulnerability if the translation changes.

**Fix:** Use `esc_html__()` in the false branch, or restructure as an if/else with `esc_html_e()`.

#### S-3: JS buttons not re-enabled on fetch error

**Location:** `assets/js/cinebot-admin.js:22-24, 47-49`

```javascript
testBtn.disabled = true;
// ...
postAjax( 'cinebot_wp_test_connection', function ( data ) {
    testBtn.disabled = false;  // only called on success
    // ...
} );
```

The `postAjax` catch handler shows an error message but does not re-enable the button. If the fetch fails (network error, non-JSON response), the button stays disabled and the user cannot retry without reloading the page.

**Fix:** Re-enable the button in the catch handler or use a `.finally()` callback.

#### S-4: Test redirect interception pattern is broken

**Location:** `tests/Integration/ApiAdminPageTest.php:83-85, 108-110`

`expectRedirect()` adds a `wp_redirect` filter that throws `WPDieException`. When `save()` calls `wp_safe_redirect()`, the filter throws. The exception propagates to the test method. Since `expectException()` is NOT called, PHPUnit treats the uncaught exception as a test error. The assertions after `$this->api_page->save()` never execute.

**Fix:** Wrap in try/catch:

```php
$this->expectRedirect();
try {
    $this->api_page->save();
} catch ( \WPDieException $e ) {
    // redirect intercepted
}
// assertions
```

#### S-5: Test `assertStringNotContainsString('pass', ...)` is incorrect

**Location:** `tests/Integration/ApiAdminPageTest.php:191`

```php
$this->settings_service->save( array(
    'api_username' => 'user',
    'api_password' => 'pass',
) );
// ...
self::assertStringNotContainsString( 'pass', $output );
```

The password value 'pass' is a substring of 'password', which appears in the form label ("Password"), the description ("A password is already stored"), and the autocomplete attribute ("new-password"). The assertion will fail when tests are run.

**Fix:** Use a unique password value like `'TEST_SECRET_VALUE_42'`.

#### S-6: Test `getActualOutputForJson()` always returns empty string

**Location:** `tests/Integration/ApiAdminPageTest.php:202-205`

```php
private function getActualOutputForJson(): string {
    return '';
}
```

`getJsonResponse()` decodes this empty string and returns `array()`. The tests `test_test_connection_rejects_missing_nonce` and `test_sync_now_denies_non_admin` assert `assertFalse($json['success'])` — `$json['success']` is `null` (undefined key), and `assertFalse(null)` passes vacuously. The tests do not verify actual AJAX behavior.

**Fix:** Use output buffering (`ob_start`/`ob_get_clean`) to capture `wp_send_json_error` output, or mock `wp_send_json_error`.

#### S-7: Test `test_admin_menu_registers_hooks` asserts unregistered AJAX actions

**Location:** `tests/Integration/ApiAdminPageTest.php:62-63`

```php
$menu->register();
self::assertHasAction( 'wp_ajax_cinebot_wp_test_connection' );
self::assertHasAction( 'wp_ajax_cinebot_wp_sync_now' );
```

After `register()`, only `admin_menu` and `admin_enqueue_scripts` hooks are registered. The AJAX actions are registered inside `add_menu()`, which is a callback for `admin_menu` and has NOT been called. These assertions will fail unless `do_action('admin_menu')` fires during test setup.

**Fix:** Either call `$menu->add_menu()` directly before asserting, or move the action registrations to `register()` (per M-1), which makes the assertions valid after `register()`.

---

### Consider (5)

#### C-1: Unused import in Plugin.php

**Location:** `includes/Plugin.php:14`

`use CinebotWp\Services\ApiClient;` is imported but never referenced in Plugin.php. (If S-1 is fixed by injecting ApiClient, this import would be used.)

#### C-2: Duplicate `capability()` method

**Location:** `AdminMenu.php:107-109` and `ApiPage.php:190-192`

Both classes define an identical private `capability()` method. A trait or shared base could reduce duplication.

#### C-3: Duplicate CronScheduler and SyncService instances in Plugin.php

**Location:** `includes/Plugin.php:79-82, 86-96`

`scheduler()` creates one `CronScheduler` + `SyncService`; `admin_menu()` creates another pair. The registered CronScheduler (from `scheduler()`) and the ApiPage's CronScheduler (from `admin_menu()`) are different instances. Functionally safe (they share database state) but confusing and wasteful.

#### C-4: Redundant `reschedule()` call

**Location:** `includes/Admin/Pages/ApiPage.php:131`

`ApiPage::save()` explicitly calls `$this->scheduler->reschedule( $old, $new )`. But `CronScheduler::register()` (called in `boot()` via `scheduler()`) hooks `reschedule` to `update_option_cinebot_wp_settings`. When `SettingsService::save()` calls `update_option()`, the hook fires `reschedule()` automatically. The explicit call is redundant — reschedule runs twice.

#### C-5: Missing `http_code` in test connection success response

**Location:** `includes/Admin/Pages/ApiPage.php:147-149`

The brief specifies `{success, http_code, titoli_count}`. The implementation returns `{success: true, data: {titoli_count: N}}` without `http_code`. On success the code is always 200, so this is non-critical, but it deviates from the brief.

---

## Quality score

| Category | Count |
|---|---|
| Must-fix | 1 |
| Should-fix | 7 |
| Consider | 5 |
| **Total** | **13** |

Score = 100 × (13 − 1 − 7) / 13 = **38.5%**

---

## Verdict

- **Spec: FAIL** — M-1 renders the save, test-connection, and sync-now features non-functional in production.
- **Quality: CHANGES REQUIRED** — Score 38.5% (below 94% gate). Must-fix and should-fix findings must be resolved before merge.

---

## Re-review — Commit `84cf28b` (2026-08-06)

**Reviewer:** Fresh reviewer (clean context)
**Scope:** Verify M-1, S-1 through S-7 resolutions only. Consider items (C-1 through C-5) out of scope.

### Verification matrix

| ID | Finding | Status | Evidence |
|---|---|---|---|
| M-1 | AJAX/admin-post actions registered inside `admin_menu` callback | RESOLVED | `AdminMenu.php:32-38` — three `add_action` calls moved from `add_menu()` to `register()`; `add_menu()` no longer registers hooks. Actions now reachable on `admin-ajax.php` / `admin-post.php` requests. |
| S-1 | SyncService created without ApiClient | RESOLVED | `Plugin.php:83, 91-96` — both `scheduler()` and `admin_menu()` now inject `new ApiClient( $settings )` into `new SyncService( $wpdb, $api )`. C-1 (unused import) is now moot — `ApiClient` is referenced. |
| S-2 | DashboardPage `$next_label` false branch unescaped | RESOLVED | `DashboardPage.php:41` — false branch now uses `esc_html__( 'Not scheduled', 'cinebot-wp' )`. phpcs:ignore comment updated to "both branches escaped above." |
| S-3 | JS buttons not re-enabled on fetch error | RESOLVED | `cinebot-admin.js:15, 29-33, 48, 68` — `postAjax` accepts `button` param and uses `.finally()` to re-enable. Inline re-enable in success callback removed. Both `testBtn` and `syncBtn` passed as third arg. |
| S-4 | Test redirect interception broken | RESOLVED | `ApiAdminPageTest.php:98-102, 129-133` — both save tests now wrap `$this->api_page->save()` in `try/catch (\WPDieException)`. `expectRedirect()` and `captureRedirect()` helpers removed. |
| S-5 | `assertStringNotContainsString('pass', ...)` collides with labels | RESOLVED | `ApiAdminPageTest.php:182, 193` — password value changed to `'TEST_SECRET_VALUE_42'`; assertion matches. |
| S-6 | `getActualOutputForJson()` returns empty string | RESOLVED | `ApiAdminPageTest.php:143-148, 156-161` — AJAX tests now use `ob_start()`/`ob_get_clean()` + `json_decode` + `assertIsArray`. `getJsonResponse()` and `getActualOutputForJson()` helpers removed. |
| S-7 | `test_admin_menu_registers_hooks` asserts unregistered actions | RESOLVED | `ApiAdminPageTest.php:62` — added `assertHasAction('admin_post_cinebot_wp_save_api')`. All four action assertions now valid after `register()` because hooks moved per M-1 fix. |

### Regressions / new issues

None observed. The fix is minimal and surgical:
- No new files touched outside the original review scope.
- `postAjax` signature change (`button` param) is backward-compatible within this file (only two call sites, both updated).
- Removal of `expectRedirect`/`captureRedirect`/`getJsonResponse`/`getActualOutputForJson` helpers cleans up dead code; no remaining references.
- `ApiClient` import in `Plugin.php` is now actually used (C-1 closed as side effect).

### Remaining (out of scope, unchanged)

C-2 (duplicate `capability()`), C-3 (duplicate CronScheduler/SyncService instances), C-4 (redundant `reschedule()` call), C-5 (missing `http_code` in success response). These are Consider-tier and were not part of the fix mandate.

### Verdict

- **Spec: PASS** — M-1 unblocked; all AJAX/admin-post/save flows are now reachable. S-1 through S-7 resolved; no spec deviations remain in the Task 11 scope.
- **Quality: APPROVED** — All must-fix and should-fix findings closed. No regressions. Consider items remain but are non-blocking.
- **Findings resolved:** 8 of 8 (M-1, S-1, S-2, S-3, S-4, S-5, S-6, S-7)
- **Outstanding:** 4 Consider-tier (non-blocking)
