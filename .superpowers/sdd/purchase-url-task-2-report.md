# Task 2 Report: Persist The Nullable Event Purchase URL

## What I Implemented

Added the nullable `url_acquisto` field to the event model, repository, and database schema. Versioned the schema at `1.1.0` and ensured the DB version is stored only after successful seeding.

### Source Changes

**`includes/Models/Evento.php`**
- Added `public ?string $urlAcquisto = null;` property after `idevento`
- Added hydration in `fromArray()`: `$model->urlAcquisto = isset( $data['url_acquisto'] ) ? (string) $data['url_acquisto'] : null;`
- Added serialization in `toArray()`: `'url_acquisto' => $this->urlAcquisto,`

**`includes/Repositories/EventoRepository.php`**
- Added `'url_acquisto' => $event->urlAcquisto,` to save data array (after `idevento`)
- Updated formats array: added `'%s'` as second element (for the nullable string)
- Existing `created_at` append and insert/update branches unchanged

**`includes/Database/SchemaInstaller.php`**
- Added `public const DB_VERSION = '1.1.0';`
- Added `url_acquisto varchar(500) NULL,` to `cinebot_eventi` schema (after `idevento`)
- Moved version persistence from before `seed_event_types()` to after it
- Added `store_version()` private method that uses `add_option`/`update_option` with autoload=false and throws `RuntimeException` if the version cannot be recorded

### Test Changes

**`tests/Unit/ModelsTest.php`**
- Added `'url_acquisto'` key to evento row data (after `idevento`)
- Added `self::assertNull( $evento->urlAcquisto )` to defaults test

**`tests/Integration/ScheduleRepositoryTest.php`**
- Set `$event->urlAcquisto` on API event before save
- Added `self::assertNull( $stored_manual_event->urlAcquisto )` assertion

**`tests/Integration/SchemaInstallerTest.php`**
- Replaced literal `'1.0.0'` version assertions with `SchemaInstaller::DB_VERSION`
- Added nullable and type assertions for `url_acquisto` column
- Added `assert_column_type()` helper method
- Added `assertFalse( get_option( 'cinebot_wp_db_version' ) )` after seed failure
- Added `assertSame( SchemaInstaller::DB_VERSION, ... )` after successful retry

## TDD Evidence

### RED (failing tests before implementation)

**Unit test command:**
```bash
docker compose run --rm php composer test:unit -- --filter ModelsTest
```

**RED output (key failures):**
```
1) test_model_round_trip_uses_exact_database_keys_without_mutating_input ('evento')
   Failed asserting that two arrays are identical.
   --- Expected: key 2 => 'url_acquisto'
   +++ Actual:   key 2 => 'titolo_id'

2) test_defaults_preserve_nulls_and_own_manual_reconciliation_state
   Undefined property: CinebotWp\Models\Evento::$urlAcquisto

Tests: 9, Assertions: 266, Errors: 1, Failures: 1.
```

**Integration test command:**
```bash
docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest|SchemaInstallerTest"
```

**RED output (key failures):**
```
1) ScheduleRepositoryTest::test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state
   Undefined property: CinebotWp\Models\Evento::$urlAcquisto

2) SchemaInstallerTest::test_deactivation_retains_schema_and_data
   Error: Undefined class constant 'DB_VERSION'

3) SchemaInstallerTest::test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults
   Failed asserting that '1.0.0' is false.  (version stored before seed in old code)
```

### GREEN (passing tests after implementation)

**Unit test command:**
```bash
docker compose run --rm php composer test:unit -- --filter ModelsTest
```

**GREEN output:**
```
.........                                                           9 / 9 (100%)
OK (9 tests, 336 assertions)
```

**Integration test command (key test):**
```bash
docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest::test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state"
```

**GREEN output:**
```
.                                                                   1 / 1 (100%)
OK (1 test, 39 assertions)
```

This confirms: API `urlAcquisto` round-trips exactly, manual `urlAcquisto` remains null, all 39 assertions pass.

## Files Changed

| File | Lines Changed |
|------|--------------|
| `includes/Models/Evento.php` | +3 |
| `includes/Repositories/EventoRepository.php` | +31/-14 (reformatted save data) |
| `includes/Database/SchemaInstaller.php` | +21/-8 |
| `tests/Unit/ModelsTest.php` | +2 |
| `tests/Integration/ScheduleRepositoryTest.php` | +2 |
| `tests/Integration/SchemaInstallerTest.php` | +33/-8 |

## Self-Review Findings

- All code blocks from the brief used verbatim (property, hydration, serialization, save data, formats, DB_VERSION constant, column definition, store_version method, test assertions)
- `url_acquisto` positioned directly after `idevento` in all layers (model property, hydration, serialization, repository data, schema column)
- Formats array correctly updated: `'%d'` (idevento) + `'%s'` (url_acquisto) + remaining fields = 15 formats for 15 data fields
- `store_version()` uses `add_option` with autoload=false (matching old behavior) and throws on verification failure
- Version is now stored AFTER `seed_event_types()` succeeds, so a seed failure leaves the version unset
- No API credentials exposed, no `source=manual` overwrites, no dynamic SQL, no plugin data deletion

## Issues or Concerns

### Pre-existing SchemaInstallerTest Failures (NOT caused by my changes)

Three SchemaInstallerTest tests fail due to a pre-existing environmental issue where `dbDelta()` does not create tables visible to the test environment. This was confirmed by running the tests with ALL my changes reverted (original code at HEAD `747e305`):

1. **`test_install_creates_approved_schema_and_defaults`** — Shows 0 tables created. Pre-existing: same failure with original code.
2. **`test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults`** — Second `install()` fails because mock wpdb can't see tables. Pre-existing: same error with original code (at line 215 vs my line 219 — my `assertFalse` assertion passes, allowing the test to progress further, but the second install failure is pre-existing).
3. **`test_deactivation_retains_schema_and_data`** — `Plugin::activate()` calls `install()` which doesn't create tables. Pre-existing: same failure with original code (was error due to undefined constant in my test changes; now fails at table existence check).

The brief noted: "Pre-existing integration test failures ... are environmental and NOT caused by your changes." The environmental issue extends beyond SyncServiceTest to SchemaInstallerTest. The root cause is that `dbDelta()` (which uses the global `$wpdb`) creates tables that are not visible to the test assertions or mock wpdb connections in this Docker environment.

### Pre-existing ScheduleRepositoryTest Failures

Two tests not modified by this task also fail pre-existingly:
- `test_admin_search_count_statistics_and_type_count` — count mismatch (3 vs 2)
- `test_reconciliation_query_failures_throw_for_every_return_contract` — exception message assertion

These were failing identically in both the RED and baseline runs.

## Commit

```
79f1668 feat: persist event purchase URLs
```

---

## Follow-Up: Indentation Fix (seed-failure test)

### Problem

In `test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults`, the outer `try` block's body (lines 215–226) was de-indented from 3 tabs to 2 tabs when new assertions were added. The outer `try {` sits at 2 tabs, so its body must be at 3 tabs — but the post-catch assertions and retry block were at 2 tabs, misaligned with the surrounding try/finally structure.

### What Changed

**`tests/Integration/SchemaInstallerTest.php`** — 10 lines re-indented (2→3 tabs), no logic changes:

- `self::assertSame( 0, ... )` — table count after rollback
- `self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), ... )` — transaction query log
- `self::assertFalse( get_option( 'cinebot_wp_db_version' ) )` — version not persisted on failure
- `$installer->install()` — retry
- `self::assertSame( 62, ... )` — full seed count after retry
- `self::assertSame( array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ), ... )` — full transaction log
- `self::assertSame( SchemaInstaller::DB_VERSION, ... )` — version persisted after retry

The inner `catch` closing `}` (line 213) and the `} finally {` (line 227) were already correct and left unchanged.

### Commit

```
99a45de fix: correct indentation in seed-failure test
```
