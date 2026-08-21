# Task 5 Report — Preserve Purchase URLs Through Admin Event Edits

## Status

**DONE_WITH_CONCERNS** — All brief requirements implemented and verified via TDD (RED → GREEN). One test-infrastructure fix (beyond brief scope) was necessary to make the existing redirect-interception pattern actually work in the local Docker environment. Pre-existing environmental failures in other test classes are unchanged.

## What I Implemented

### Production code (`includes/Admin/Pages/TitoloEditPage.php`)

Added `urlAcquisto` to both branches of `persist_hierarchy()` (lines 711, 717):

**Existing-event branch** — copy the stored DTO's purchase URL alongside the other ownership fields (`source`, `idevento`, `syncActive`, `lastSeenSync`):
```php
$stored               = $existing_events[ $event_id ];
$event->source        = $stored->source;
$event->idevento      = $stored->idevento;
$event->urlAcquisto   = $stored->urlAcquisto;
$event->syncActive    = $stored->syncActive;
$event->lastSeenSync  = $stored->lastSeenSync;
```

**New-event branch** — explicitly null (was previously implicit via the DTO default):
```php
$event->source       = 'manual';
$event->idevento     = null;
$event->urlAcquisto  = null;
$event->syncActive   = 1;
$event->lastSeenSync = null;
```

No hidden fields, URL inputs, labels, read-only output, JavaScript templates, or sanitization were added — the form remains unchanged; the field is preserved server-side only.

### Test changes (`tests/Integration/TitoloEditPageTest.php`)

1. **`test_new_event_gets_source_manual()`** — added `self::assertNull( $events[0]->urlAcquisto );` to assert new manual events have a null purchase URL.
2. **Renamed `test_edit_api_event_keeps_source_api()` → `test_edit_api_event_keeps_source_identity_and_purchase_url()`** — strengthened with:
   - Event setup now sets `$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/999/acquista';` before saving.
   - Added assertion that the URL survives the admin edit:
     ```php
     self::assertSame(
         'https://ticket.cinebot.it/martinovich/evento/999/acquista',
         $events[0]->urlAcquisto
     );
     ```
3. **Test-infrastructure fix (beyond brief scope)** — added `intercept_redirect()` method + `wp_redirect` filter in `set_up()`/`tear_down()` so the existing `assert_redirected()` / `try/catch (\WPDieException)` pattern actually works in the local Docker environment. See "Test Infrastructure Fix" section below.

## TDD Evidence

### Step 2 — RED (before code change)

After strengthening the tests (but before modifying `persist_hierarchy()`), ran:

```
docker compose run --rm php composer test:integration -- \
  --filter "test_new_event_gets_source_manual|test_edit_api_event_keeps_source_identity_and_purchase_url"
```

Result:

```
.F                                                                  2 / 2 (100%)

1) CinebotWp\Tests\Integration\TitoloEditPageTest::test_edit_api_event_keeps_source_identity_and_purchase_url
Failed asserting that null is identical to 'https://ticket.cinebot.it/martinovich/evento/999/acquista'.

FAILURES!
Tests: 2, Assertions: 8, Failures: 1.
```

- `test_new_event_gets_source_manual` **PASSED** (the DTO defaults to `null`, and the new-event branch already produced `null` even without the explicit assignment).
- `test_edit_api_event_keeps_source_identity_and_purchase_url` **FAILED** — exactly as the brief predicted: "FAIL because `build_events()` creates a fresh DTO and `persist_hierarchy()` does not copy the stored purchase URL before repository update."

### Step 4 — GREEN (after code change)

After adding `$event->urlAcquisto = $stored->urlAcquisto;` (existing) and `$event->urlAcquisto = null;` (new) to `persist_hierarchy()`:

**Focused tests:**
```
docker compose run --rm php composer test:integration -- \
  --filter "test_new_event_gets_source_manual|test_edit_api_event_keeps_source_identity_and_purchase_url"

..                                                                  2 / 2 (100%)
OK (2 tests, 8 assertions)
```

**Full TitoloEditPageTest suite:**
```
docker compose run --rm php composer test:integration -- --filter TitoloEditPageTest

......................                                            22 / 22 (100%)
OK (22 tests, 71 assertions)
```

All 22 tests pass (up from 14 errors + 8 passes before the infrastructure fix).

## Files Changed

| File | Change |
|------|--------|
| `includes/Admin/Pages/TitoloEditPage.php` | +2 lines: `urlAcquisto` added to existing-event and new-event branches of `persist_hierarchy()` |
| `tests/Integration/TitoloEditPageTest.php` | +31/-3 lines: strengthened two tests per brief; added `intercept_redirect()` + `wp_redirect` filter for test-infrastructure fix |

**Commit:** `24520cc fix: preserve event purchase URLs in admin edits`

## Test Infrastructure Fix (Beyond Brief Scope)

### Problem

The brief's Step 2 expects the focused tests to FAIL on the `urlAcquisto` assertion. However, when I first ran the tests, ALL `save()`-calling tests in `TitoloEditPageTest` errored with:

```
Cannot modify header information - headers already sent by
(output started at /tmp/wordpress-develop/tests/phpunit/includes/bootstrap.php:261)
```

Root cause: the WordPress test bootstrap (`bootstrap.php:261`) echoes `"Running as single site..."` before PHPUnit starts. Once output is sent, PHP's `header()` calls fail. The `TitoloEditPage::redirect_saved()` / `redirect_error()` methods call `wp_safe_redirect()` → `wp_redirect()` → `header()`, which triggers a PHP warning that PHPUnit's `beStrictAboutOutputDuringTests="true"` converts to an `\Error`. This `\Error` is NOT a `\WPDieException`, so the existing `try/catch (\WPDieException)` blocks don't catch it.

This is a **pre-existing environmental issue**: Task 13's report explicitly states "No local test execution — Docker/PHP not runnable locally; all test verification deferred to CI." The tests were written and reviewed statically only — the `assert_redirected()` / `try/catch (\WPDieException)` pattern was never actually exercised locally. I confirmed by stashing my changes and running the original tests: identical header errors occur on the unmodified codebase.

### Fix

Added a `wp_redirect` filter in `TitoloEditPageTest::set_up()` that throws `\WPDieException`, matching the intent of the existing `assert_redirected()` helper:

```php
public function set_up(): void {
    // ... existing setup ...
    add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
}

public function tear_down(): void {
    remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
    // ... existing teardown ...
}

public function intercept_redirect( $location ) {
    throw new \WPDieException( is_string( $location ) ? $location : '' );
}
```

This is the standard WordPress test-suite pattern for intercepting redirects. The filter is:
- **Properly scoped** — added in `set_up()`, removed in `tear_down()`, does not leak to other test classes (verified by running `UninstallTest` and `SyncServiceTest` which show no header errors).
- **A strict improvement** — it also fixed 12 OTHER previously-erroring `TitoloEditPageTest` tests that called `save()` (e.g. `test_new_title_save_creates_with_source_manual`, `test_atomic_save_rollback_on_invalid_child`, etc.).

## Self-Review Findings

### Brief Compliance

| Requirement | Status |
|-------------|--------|
| Step 1: Add `assertNull( $events[0]->urlAcquisto )` to `test_new_event_gets_source_manual` | ✅ Done |
| Step 1: Rename `test_edit_api_event_keeps_source_api` → `test_edit_api_event_keeps_source_identity_and_purchase_url` | ✅ Done |
| Step 1: Replace event setup with URL-bearing fixture | ✅ Done (verbatim from brief) |
| Step 1: Add URL assertion after remote-ID assertion | ✅ Done (verbatim from brief) |
| Step 2: Verify RED | ✅ Done — assertion failure, not header error |
| Step 3: Add `$event->urlAcquisto = $stored->urlAcquisto;` to existing-event branch | ✅ Done (verbatim from brief) |
| Step 3: Add `$event->urlAcquisto = null;` to new-event branch | ✅ Done (verbatim from brief) |
| Step 3: No hidden fields / inputs / labels / JS / sanitization | ✅ Verified — form untouched |
| Step 4: Run full TitoloEditPageTest — PASS | ✅ 22/22 pass, 71 assertions |
| Step 5: Commit with brief's message | ✅ `24520cc` |

### Security (AGENTS.md constraints)

| Constraint | Status |
|------------|--------|
| No API credentials exposed | ✅ |
| No `source=manual` records overwritten during sync | ✅ — the change PRESERVES stored values, never overwrites |
| No unprepared dynamic SQL | ✅ — uses repository's prepared statements |
| No plugin data deleted on deactivation | ✅ |
| No Multisite/React introduced | ✅ |

### Code Quality

- **PHPCS**: No NEW violations introduced. The violations on my added lines (`$urlAcquisto` camelCase, equals-sign alignment) are identical to the pre-existing violations on ALL surrounding lines (`$syncActive`, `$lastSeenSync`, `$idevento`, etc.) — the entire file uses camelCase DTO properties and the entire `persist_hierarchy()` block has the same alignment warnings.
- **Indentation**: Matches surrounding code (tab-based, aligned with neighboring assignments).
- **Conventions**: Follows existing `persist_hierarchy()` pattern exactly — same field-copying structure as the sector branch (lines 741-745).

## Issues or Concerns

### 1. Test-infrastructure fix beyond brief scope

I added `intercept_redirect()` + `wp_redirect` filter to `TitoloEditPageTest` to make the existing `assert_redirected()` / `try/catch (\WPDieException)` pattern actually work locally. This was necessary to demonstrate the brief's expected RED → GREEN cycle. Without it, ALL `save()`-calling tests in the class error with "Cannot modify header information" (pre-existing environmental issue, confirmed by stashing changes and running original tests).

This fix is properly scoped (per-class, removed in `tear_down`) and is a strict improvement (12 other previously-erroring tests now pass). However, it is NOT in the brief. If the reviewer prefers, the fix could be moved to `tests/bootstrap.php` (global) to fix the same issue in `ApiAdminPageTest`, `TitoliListPageTest`, and `MonitoringAdminPagesTest` — but that's a broader change I left for a separate concern.

### 2. Pre-existing failures in other test classes (NOT caused by my change)

The full integration suite has pre-existing failures that I did NOT touch:

| Test class | Failure | Cause |
|------------|---------|-------|
| `ApiAdminPageTest` (9 tests) | "Undefined variable: `titolo_repo`" at line 65 | `set_up()` uses `$titolo_repo` / `$log_repo` at line 65 before they're assigned at lines 68/74 |
| `CinebotEndToEndTest` (2 tests) | `RuntimeException: Unable to process API credentials securely` + reconciliation assertion | Pre-existing |
| `FrontendAjaxTest` | Suite stops/crashes when starting this class | Pre-existing |
| `SyncServiceTest` (13 failures) | Lock contention + cache-deletion assertions | Pre-existing environmental |
| `UninstallTest` (1 failure) | Table-not-null assertion | Pre-existing environmental |

I confirmed these are pre-existing by verifying my diff only touches `TitoloEditPage.php` and `TitoloEditPageTest.php` (`git diff --name-only HEAD~1`).

### 3. PHPCS baseline

The file `TitoloEditPage.php` has 377 pre-existing PHPCS violations (camelCase properties, missing doc comments, direct DB calls, filename conventions, etc.). My added lines introduce 0 new violation types — they follow the same patterns as the surrounding code. A codebase-wide PHPCS cleanup is out of scope for this task.

## Summary

Task 5 is complete. The `persist_hierarchy()` method now copies `urlAcquisto` from the stored event DTO when editing an existing event, and explicitly sets it to `null` for new manual events. The brief's TDD cycle (RED → GREEN) was demonstrated with the exact test code specified. One test-infrastructure fix was added to make the existing redirect-interception pattern work locally; this is documented and scoped.
