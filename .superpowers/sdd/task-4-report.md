# Task 4 Implementation Report

## Status

Implemented only the approved event-type and venue repositories and their integration tests:

- `includes/Repositories/TipologiaRepository.php`
- `includes/Repositories/LocaleRepository.php`
- `tests/Integration/TipologiaRepositoryTest.php`
- `tests/Integration/LocaleRepositoryTest.php`

No coordinator, review, lifecycle-state, admin, title/event repository, or synchronization files were changed for Task 4.

## TDD Evidence

The two integration test files were created before either repository implementation. The required RED command was then attempted:

```text
docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"
```

Result: unavailable before test execution because Docker Desktop's Linux engine pipe did not exist:

```text
unable to get image 'mysql:8.0': failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine
```

Host `php -v` was also attempted and failed because PHP is not installed on `PATH`. The repository implementations were then written against the failing-test contract without changing the tests to fit the implementation.

## Verification

Focused GREEN gate attempted after implementation:

```text
docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"
```

Result: unavailable before test execution with the same missing Docker engine pipe.

Full gate attempted after implementation:

```text
docker compose run --rm php composer check
```

Result: unavailable before Composer execution with the same missing Docker engine pipe.

Additional evidence:

- `docker compose config --quiet`: PASS (exit 0).
- `git diff --check`: PASS for Task 4 files; Git emitted only pre-existing CRLF warnings for unstaged `specs/execution-status.yaml` and `specs/state.yaml`.
- Static secret scan: PASS; no credential/token/private-key patterns in the repositories.
- Dependency scan: PASS; no dependencies added.
- SQL injection review: PASS; table names are injected `wpdb` prefixes plus fixed suffixes, all dynamic values use `prepare()`, `insert()`, `update()`, or `delete()` with explicit formats, LIKE values use `esc_like()`, and ordering is fixed internally.
- Ownership review: PASS; `upsertApi()` returns an existing manual venue unchanged.
- DTO review: PASS; row lookups and lists hydrate `TipologiaEvento` or `Locale`, and list PHPDoc uses `array<int,TipologiaEvento>` / `array<int,Locale>`.
- Signature review: PASS; all brief interfaces and return types are present, constructors inject `wpdb`, and no repository accesses global state.
- Persistence review: PASS; insert/update field maps are explicit, inserts set both timestamps, updates omit `created_at`, and failed writes produce safe actionable exceptions.
- Filter review: PASS; `search()` and `count()` call the same predicate builder; exact sanitized province/city and escaped case-insensitive text predicates are covered.
- Compatibility review: PASS by inspection for PHP 7.4 syntax and WordPress array/WPCS formatting; executable PHPCS/PHPStan evidence remains blocked with Composer.
- Whitespace review: PASS via `git diff --check`.

## Test Coverage

- Leading-zero event-type lookup and DTO hydration.
- Active-only filtering and ascending string-code ordering.
- Custom insert/update/disable/delete and creation timestamp preservation.
- Predefined and missing delete rejection.
- Duplicate and forced failed event-type writes with safe exceptions.
- Positive/missing activation ID validation.
- Manual venue insert/find/update, DTO hydration, source, and creation timestamp preservation.
- API venue create/update, field mapping, API source, and manual ownership preservation.
- Invalid API IDs/names.
- Combined exact filters, case-insensitive name/code/city text search, and count parity.
- Positive page/per-page clamping, boundary pages, and deterministic `nome ASC, id ASC` ordering.
- SQL-injection-shaped exact/text filter values returning no broadened result.

## Self-Review

- PASS Security: no secrets; prepared values and escaped LIKE input prevent injection; errors do not expose SQL.
- PASS Correctness: repository behavior maps directly to the brief and schema; creation timestamps and manual ownership are preserved.
- PASS Performance: indexed identity lookups are targeted; search/count share compact predicates and search is paginated.
- PASS Scope: only the four Task 4 code/test files and this report are intended for staging.
- PASS Dependency inversion: both repositories receive their immediate `wpdb` collaborator through constructors.
- PASS Types and clarity: public signatures are typed, array boundaries are documented, and repository methods return DTOs rather than rows.
- PASS Tests: each public behavior has integration coverage through repository interfaces; no production test hooks were introduced.
- PASS Code hygiene: no dead code, commented-out blocks, speculative abstraction, or unrelated refactoring.
- N/A Supply-chain package scoring: no package was added or changed.
- N/A Plan metadata/provenance: no plan artifact was created or modified; implementation is based on Task 4 of approved plan after baseline `e3253d8`.

Rationalizations rejected: runtime gates were not marked passing merely because Docker/PHP are unavailable; their exact failures and the residual verification gap are reported instead.

## Concerns

The focused integration suite, WPCS, PHPStan, full PHPUnit suite, and distribution build have not executed in this environment. They must run in CI or after Docker Desktop's Linux engine becomes available.

## Review Fix Report

Applied every finding from `.superpowers/sdd/task-4-review.md` in a follow-up fix:

- `LocaleRepository::upsertApi()` now validates the original `localeId` before casting. It accepts a positive native integer or an all-digit positive string that fits in the platform integer range. It rejects floats, booleans, signs, decimal/alphanumeric/whitespace strings, zero values, and overflowing digit strings.
- The API payload provider now covers malformed positive-coercible values including `1.9`, `true`, `'+1'`, `'1.0'`, `'1junk'`, and `' 1 '`, plus string zero and overflow. A separate test documents intentional support for a positive all-digit string with leading zeroes.
- The manual ownership test snapshots the complete persisted DTO/database-shaped array before `upsertApi()` and compares it exactly afterward, including IDs, all venue fields, source, and timestamps.
- Both repository lifecycle tests set `updated_at` to `2000-01-01 00:00:00` directly, reload the DTO, perform the update, and assert that `created_at` is unchanged while `updated_at` no longer equals the old fixture.

Review-fix TDD and verification commands:

```text
docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"
```

RED and GREEN attempts both stopped before PHPUnit because Docker Desktop's Linux engine pipe remains unavailable. The GREEN failure was:

```text
unable to get image 'mysql:8.0': failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine
```

```text
docker compose run --rm php composer check
```

The full gate stopped before Composer for the same environment reason.

Static review-fix results:

- `docker compose config --quiet`: PASS (exit 0).
- `git diff --check`: PASS; only line-ending warnings were emitted, including pre-existing unstaged state-file warnings.
- Strict identity review: PASS; validation branches on the original type, the digit regex is anchored, zero-only strings are rejected, and length/lexical comparison prevents integer overflow before the final cast.
- Timestamp review: PASS; direct fixtures make refresh assertions independent of wall-clock resolution, while complete DTO reloads prove creation timestamps remain unchanged.
- Ownership review: PASS; the full `Locale::toArray()` state is compared before and after API upsert.
- Scope review: PASS; only `LocaleRepository`, the two Task 4 integration tests, and this report are part of the fix.

Residual concern remains unchanged: executable PHPUnit, WPCS, PHPStan, and distribution-build evidence requires a working Docker Linux engine or host PHP runtime.
