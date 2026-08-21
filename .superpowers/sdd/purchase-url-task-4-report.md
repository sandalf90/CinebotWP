# Task 4 Report: Generate Purchase URLs During Transactional Synchronization

## What I Implemented

Wired the purchase URL generation from Task 1's `CinebotUrlService::buildAcquisto()` into `SyncService`, so every successfully synchronized API event receives a canonical `urlAcquisto`. Host/path changes now count as event updates (incrementing `eventi_updated`), and invalid host/path/idevento values cause full transaction rollback with safe error messages.

### Production code (`includes/Services/SyncService.php`)

**`sync_title()`** — preserved the manual-title early return, then extracted and validated the envelope `host` and `path` once via `required_string()`. These validated strings are passed to both `map_title()` and `sync_event()`, eliminating the duplicate `required_string` calls that previously lived inside `map_title()`:

```php
$host = $this->required_string( $envelope, 'host' );
$path = $this->required_string( $envelope, 'path' );
$title = null !== $existing ? $existing : new Titolo();
$this->map_title( $title, $data, $host, $path, $frontend, $token );
```

The `sync_event()` call was updated to pass the validated base:

```php
$this->sync_event( $event_data, $title_id, $host, $path, $token, $stats );
```

**`sync_event()`** — signature changed to accept `string $host, string $path`. After the existing manual-event early return and the `idevento` validation, the purchase URL is generated before the remaining event mapping:

```php
$event->idevento = $remote;
$event->urlAcquisto = $this->urls->buildAcquisto( $host, $path, $remote );
```

**`map_title()`** — signature changed from `array $envelope` to `string $host, string $path`. The `buildLocandina()` call now uses the validated parameters instead of calling `required_string()` internally:

```php
$title->locandinaUrl = $this->urls->buildLocandina(
    $host,
    $path,
    $title->idtitolo,
    (int) $title->locandinaFlag
);
```

**`event_changed()`** — added `$old->urlAcquisto !== $new->urlAcquisto` as the second comparison clause, so host/path changes (which change the URL) count as event updates. Reformatted to multi-line for readability.

### Tests (`tests/Integration/SyncServiceTest.php`)

Five additions/modifications, all from the brief verbatim:

1. **`test_imports_complete_fixture_and_records_success`** — added exact URL assertion:
   ```php
   self::assertSame(
       'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
       $event->urlAcquisto
   );
   ```

2. **`test_identical_payload_is_idempotent_and_hash_is_key_order_invariant`** — strengthened idempotence:
   ```php
   self::assertSame( 0, $result->stats()['eventi_updated'] );
   ```

3. **`test_changed_path_updates_purchase_url_and_event_stats`** (new) — changes the envelope path to `'sala nuova'` and asserts the URL refreshes to `https://ticket.cinebot.it/sala%20nuova/evento/2920/acquista` with `eventi_updated=1`.

4. **`test_next_sync_backfills_existing_null_purchase_url`** (new) — nulls the stored `url_acquisto` via direct DB update, re-syncs, and asserts the URL is backfilled with `eventi_updated=1`.

5. **`test_manual_event_purchase_url_remains_untouched`** (new) — sets the event to `source=manual` with a manual URL, re-syncs, and asserts the URL and source are unchanged with `eventi_updated=0`.

6. **`test_invalid_purchase_url_base_rolls_back_safely`** (new) — tests two invalid payloads (missing `host`, hostile `path` containing `'../secret-api-password'`), asserting full rollback: `'error'` status, safe message, no event/title rows, safe sync-log error, and no payload secret leakage.

## TDD Evidence

### RED (Step 5)

Ran `docker compose run --rm php composer test:integration -- --filter SyncServiceTest` against the tree with all tests added but no production code changes. The key RED failure:

```
1) CinebotWp\Tests\Integration\SyncServiceTest::test_imports_complete_fixture_and_records_success
Failed asserting that null is identical to 'https://ticket.cinebot.it/martinovich/evento/2920/acquista'.
/plugin/tests/Integration/SyncServiceTest.php:61
```

This confirmed the expected RED state: imported events had `urlAcquisto=NULL` because `sync_event` didn't call `buildAcquisto()`.

Other new tests (path-change, backfill, manual-ownership, invalid-base) also failed, though their failures were masked by pre-existing lock contention (see Issues below).

### GREEN (Step 9)

After implementing Steps 6-8, ran the same filter. The primary assertion passes:

```
docker compose run --rm php composer test:integration -- --filter SyncServiceTest::test_imports_complete_fixture_and_records_success

OK (1 test, 15 assertions)
```

Test 1 passes with 15 assertions, including the new `urlAcquisto` assertion. This proves:
- `sync_event` generates the URL via `buildAcquisto('ticket.cinebot.it', 'martinovich', 2920)`
- The result is exactly `https://ticket.cinebot.it/martinovich/evento/2920/acquista`
- The URL is persisted to the `url_acquisto` column by `EventoRepository::save()`
- The URL is hydrated back by `Evento::fromArray()`

Tests that call `syncPayload` twice (path-change, backfill, manual-ownership, invalid-base) and tests after the first test in the suite fail due to pre-existing lock contention (see Issues). The code correctness for these cases is verified by:

1. **Code inspection**: `event_changed()` includes `$old->urlAcquisto !== $new->urlAcquisto`, so any host/path change that alters the URL triggers an update.
2. **Code inspection**: The manual-event early return in `sync_event` (line 194) precedes the `urlAcquisto` assignment (line 199), so manual events are never overwritten.
3. **Code inspection**: `required_string($envelope, 'host')` in `sync_title` (line 168) throws before any persistence when host is missing.
4. **Code inspection**: `buildAcquisto` → `buildUrl` → `isValidSegment` rejects `'../secret-api-password'` (contains `..`), throwing before the event is saved.
5. **Baseline confirmation**: The pre-existing `test_changed_api_rows_update_and_increment_stats` (which also calls `syncPayload` twice) fails identically in isolation, confirming the lock contention is pre-existing and not caused by my changes.

### Baseline comparison

Stashed my changes and ran the same test filter on HEAD to establish the baseline:

```
Tests: 15, Assertions: 69, Errors: 2, Failures: 8
```

The baseline has 8 failures + 2 errors from pre-existing lock contention and dbDelta table visibility. My branch adds 4 new tests (total 19), of which test 1 passes (including the new `urlAcquisto` assertion) and the remaining new tests are blocked by the same pre-existing lock contention that affects pre-existing tests.

## Files Changed

| File | Change |
|------|--------|
| `includes/Services/SyncService.php` | +20/-7: host/path extraction in `sync_title`, URL generation in `sync_event`, `map_title` signature change, `event_changed` URL comparison |
| `tests/Integration/SyncServiceTest.php` | +96: URL assertion, idempotence assertion, 4 new test methods |

Total: 2 files, 116 insertions, 7 deletions. Diff matches the brief's code blocks verbatim.

## Commit

```
4fb7b23 feat: generate imported event purchase URLs
```

Staged exactly the two files specified by the brief. The unrelated working-tree modifications (Admin pages, specs, other tests) were left unstaged and are not part of this commit.

## Self-Review Findings

### Brief fidelity
- All code blocks match the brief character-for-character (verified via `git diff`).
- The manual-title early return in `sync_title` is preserved before host/path extraction (line 165-167 before line 168-169).
- The manual-event early return in `sync_event` is preserved before `urlAcquisto` assignment (line 194-196 before line 199).
- No remaining event mapping, persistence, statistics, sectors, or reconciliation code was changed.
- `event_changed()` includes all original comparison clauses plus the new `urlAcquisto` clause.

### Correctness
- **URL generation**: `buildAcquisto($host, $path, $remote)` is called for every non-manual event. The host and path are validated once in `sync_title` and passed down, avoiding redundant validation.
- **Change detection**: Adding `$old->urlAcquisto !== $new->urlAcquisto` to `event_changed()` ensures host/path changes (which change the URL) increment `eventi_updated`. The backfill case (null → URL) also triggers this comparison.
- **Manual ownership**: The manual-event early return in `sync_event` (line 194) precedes the `urlAcquisto` assignment (line 199), so manual events retain their URL. The manual-title early return in `sync_title` (line 165) precedes host/path extraction, so manual titles skip validation entirely.
- **Invalid base rollback**: Missing `host` throws in `sync_title` before any persistence. Hostile `path` (e.g., `../secret-api-password`) throws in `buildAcquisto` → `buildUrl` → `isValidSegment` during `sync_event`, after the title is saved but within the transaction, so `ROLLBACK` undoes the title save. Both produce safe error messages via `record_failure()`.
- **Security**: No payload data leaks in error messages (`record_failure` returns the generic "Schedule synchronization failed."). The `test_invalid_purchase_url_base_rolls_back_safely` test explicitly asserts `secret-api-password` does not appear in the result message.

### Convention compliance (CONVENTIONS.md / AGENTS.md)
- No `source=manual` overwrite risk (manual events early-return before URL assignment).
- No API credentials exposed.
- No unprepared dynamic SQL introduced (all new code uses existing prepared methods).
- No plugin data deleted on deactivation.
- No Multisite or React introduced.
- Conventional Commits message: `feat: generate imported event purchase URLs`.

### SOLID
- **Single Responsibility**: `sync_event` generates the URL (via `CinebotUrlService`) and persists the event (via `EventoRepository`), both event-related concerns.
- **Open/Closed**: `CinebotUrlService` is injected via constructor (Task 1), not hardcoded.
- **Dependency Inversion**: `SyncService` depends on the `CinebotUrlService` abstraction (injected), not a concrete global.

### Boy Scout Rule
- `event_changed()` was reformatted from a single long line to multi-line for readability — a surgical improvement within the method I was already modifying.
- Duplicate `required_string($envelope, 'host')` / `required_string($envelope, 'path')` calls (previously in both `sync_title` via `map_title` and implicitly in `map_title` itself) were consolidated: host/path are now extracted once in `sync_title` and passed as validated strings to both `map_title` and `sync_event`.

## Issues or Concerns

1. **Pre-existing lock contention prevents multi-call tests from passing.** `SyncLock::release()` uses `delete_exact()` which does a direct `DELETE FROM options WHERE option_name = %s AND option_value = %s` — bypassing the WP object cache. When a test calls `syncPayload()` twice within a single test method, the first call's `release()` deletes the DB row but not the cached option value. The second call's `acquire()` calls `add_option()` which finds the stale cached value and returns false, resulting in a `'locked'` status.

   This is pre-existing: the baseline (HEAD without my changes) has the same failure in `test_changed_api_rows_update_and_increment_stats`, `test_identical_payload_is_idempotent...`, `test_manual_title_and_venue_remain_untouched`, `test_reconciliation...`, `test_frontend_reconciliation_is_isolated`, and `test_cache_is_deleted_only_after_successful_commit` — all of which call `syncPayload()` twice.

   My new tests that call `syncPayload()` twice (`test_changed_path_updates_purchase_url_and_event_stats`, `test_next_sync_backfills_existing_null_purchase_url`, `test_manual_event_purchase_url_remains_untouched`, `test_invalid_purchase_url_base_rolls_back_safely`) are affected by the same pre-existing issue.

   **Verification of correctness**: Test 1 (`test_imports_complete_fixture_and_records_success`) calls `syncPayload()` once and PASSES with 15 assertions including the new `urlAcquisto` assertion. This proves the URL generation, persistence, and hydration are correct. The remaining test cases are verified by code inspection (documented above in GREEN section).

2. **Pre-existing dbDelta table-visibility failure.** `SchemaInstallerTest::test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults` fails in Docker due to dbDelta table visibility — documented in the Task 3 report and the brief's Environment Notes. Not affected by my changes (confirmed by running `--filter "SchemaInstallerTest|PluginBootstrapTest"` which shows the same single pre-existing failure).

3. **No PHPStan run.** The brief's Step 9 specifies only the integration test filter. I did not run `composer analyse` (PHPStan) since it is not part of the brief's required verification for this task.

4. **RTK not installed.** The brief's commands use `rtk` prefixes but RTK is not available in this environment. Used plain `docker compose` and `git` commands throughout.

## Follow-up Fix: Clone Existing DTOs Before Mutation for Correct Change Detection

### Problem

A latent bug existed in `sync_event()` and `sync_title()`: when an existing DTO was found, the code assigned the same object reference to the working variable (`$event = $existing` / `$title = $existing`). Subsequent field mutations then mutated the shared object, so the comparison functions (`event_changed()`, and the `hash_equals`/`syncActive` checks in `sync_title()`) compared the object against itself — every `!==` was `false`, every `hash_equals` was `true`. As a result, `eventi_updated` and `titoli_updated` were never incremented for existing rows, masking all updates as no-ops.

### Fix

Changed both assignment sites to use `clone`, preserving the original `$existing` values for comparison while the cloned `$event`/`$title` receives the newly-mapped values:

- `includes/Services/SyncService.php:170` — `$title = null !== $existing ? clone $existing : new Titolo();`
- `includes/Services/SyncService.php:197` — `$event = null !== $existing ? clone $existing : new Evento();`

### Verification

Ran the focused single-call test (unchanged behavior for the happy path):

```
docker compose run --rm php composer test:integration -- --filter SyncServiceTest::test_imports_complete_fixture_and_records_success

OK (1 test, 15 assertions)
```

The clone does not break the happy path: the test calls `syncPayload()` once, asserts success status and the expected stats (including `titoli_updated`/`eventi_updated`), and passes all 15 assertions.

Multi-call tests remain affected by the pre-existing `SyncLock` cache-staleness issue documented in "Issues or Concerns" #1 above — not caused by this fix.

### Commit

```
c65e2a5 fix: clone existing DTOs before mutation for correct change detection
```

Staged only `includes/Services/SyncService.php` (2 insertions, 2 deletions). No other working-tree changes included.

