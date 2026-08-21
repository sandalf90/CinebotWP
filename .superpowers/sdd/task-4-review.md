# Task 4 Review

## Scope

Reviewed only `TipologiaRepository`, `LocaleRepository`, and their integration tests against the Task 4 brief, approved Task 4 plan, table schema, DTO contracts, and project conventions. No Docker, PHPUnit, PHPCS, PHPStan, or build commands were rerun because the user accepted that local Docker and PHP are unavailable.

## Findings

### Medium

1. **`upsertApi()` accepts malformed remote IDs after lossy coercion.** The method casts the untrusted `localeId` to `int` before validating it, so values such as `'1junk'`, `1.9`, or `true` become the valid ID `1`. A malformed payload can therefore update the wrong API venue or match an unrelated manual venue, contrary to the requirement that `localeId` itself be a positive API identity and to the global-uniqueness boundary (`includes/Repositories/LocaleRepository.php:118-127`). The invalid-payload provider checks missing, zero, and negative IDs, but has no positive-coercible malformed values, so this behavior is not detected (`tests/Integration/LocaleRepositoryTest.php:163-187`). Validate the original value as a positive integer without truncation or trailing characters, then cast the validated value.

### Low

1. **The manual-ownership test does not prove that the matched row is exactly unchanged.** The API payload attempts to replace the name, city, and province, but the assertions check only name and source (`tests/Integration/LocaleRepositoryTest.php:138-161`). A regression that changed `codice`, address fields, remote ID, map, or timestamps would pass. Snapshot the complete DTO before `upsertApi()` and compare its full `toArray()` result afterward. The current implementation does return before mapping or writing and is compliant by inspection (`includes/Repositories/LocaleRepository.php:125-128`).

2. **Update timestamp behavior is only partially contracted.** Both lifecycle tests prove that `created_at` is preserved, but neither proves that `updated_at` is refreshed on update (`tests/Integration/TipologiaRepositoryTest.php:100-129`, `tests/Integration/LocaleRepositoryTest.php:63-89`). The implementation writes a fresh UTC WordPress timestamp and omits `created_at` from updates (`includes/Repositories/TipologiaRepository.php:72-100`, `includes/Repositories/LocaleRepository.php:69-106`), but a future regression could remove the refresh without failing these tests. Set an older stored `updated_at` fixture before saving and assert that it changes while `created_at` remains fixed.

## Spec Verdict

**FAIL - one correction required**

- API identity validation is not strict enough to establish that the supplied `localeId` is a positive integer before lookup or persistence (`includes/Repositories/LocaleRepository.php:118-127`).
- SQL values are otherwise safely passed through `prepare()`, `insert()`, `update()`, or `delete()` with explicit formats. Interpolated table identifiers are injected WordPress prefixes plus fixed suffixes; ordering and predicate fragments are internal literals (`includes/Repositories/TipologiaRepository.php:27-57`, `includes/Repositories/TipologiaRepository.php:83-162`, `includes/Repositories/LocaleRepository.php:28-60`, `includes/Repositories/LocaleRepository.php:74-102`, `includes/Repositories/LocaleRepository.php:150-223`).
- Event-type codes remain strings, code lookup is prepared, active filtering is prepared, and ordering is fixed to `codice ASC` (`includes/Repositories/TipologiaRepository.php:35-64`; `tests/Integration/TipologiaRepositoryTest.php:60-95`). Database uniqueness enforces event-type codes and failed saves produce a safe actionable exception without exposing SQL (`includes/Database/SchemaInstaller.php:194-203`, `includes/Repositories/TipologiaRepository.php:72-103`, `includes/Repositories/TipologiaRepository.php:167-174`).
- Event-type insert/update maps are explicit; insert sets both timestamps, update preserves `created_at`, activation updates only `attivo` and `updated_at`, and custom deletion includes the fixed `predefinito=0` ownership predicate (`includes/Repositories/TipologiaRepository.php:72-153`).
- Venue search clamps page and page size to at least one, computes an offset, prepares limits and filter values, escapes LIKE metacharacters, and fixes ordering to `nome ASC, id ASC` (`includes/Repositories/LocaleRepository.php:150-167`, `includes/Repositories/LocaleRepository.php:194-223`). `search()` and `count()` consume the same predicate builder, establishing filter/count parity (`includes/Repositories/LocaleRepository.php:150-185`).
- Venue persistence explicitly maps all DTO fields and valid source values. Inserts set both timestamps; updates preserve `created_at`; the API mapper covers all required keys and sets `source=api` (`includes/Repositories/LocaleRepository.php:69-141`). Existing manual rows are returned before any field assignment or write (`includes/Repositories/LocaleRepository.php:125-128`).
- Repository lookups and lists hydrate `TipologiaEvento` or `Locale`; array DTO returns are precisely documented as `array<int,TipologiaEvento>` and `array<int,Locale>` (`includes/Repositories/TipologiaRepository.php:35-64`, `includes/Repositories/LocaleRepository.php:36-60`, `includes/Repositories/LocaleRepository.php:144-168`).

## Quality Verdict

**CHANGES REQUESTED**

- Production structure is focused and readable: injected `wpdb`, no hidden repository globals, explicit field maps, shared filter construction, safe exception text, and no speculative abstraction.
- The four files use PHP 7.4-compatible syntax and match the repository's WordPress formatting shape by static inspection. Executable WPCS and PHPStan confirmation remains unavailable.
- Integration tests exercise DTO hydration, code uniqueness, safe failed event-type writes, source handling, API mapping, combined filters, injection-shaped values, count parity, pagination boundaries, and deterministic equal-name ordering (`tests/Integration/TipologiaRepositoryTest.php:60-196`, `tests/Integration/LocaleRepositoryTest.php:60-246`).
- Test contracts should be strengthened for exact manual-row immutability and `updated_at` refresh, as detailed above.

## Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 1 |
| Low | 2 |

## Residual Risk

The focused integration suite, full PHPUnit suite, PHPCS, PHPStan, and distribution build remain unexecuted in this environment (`.superpowers/sdd/task-4-report.md:30-61`, `.superpowers/sdd/task-4-report.md:93-95`). This accepted environment limitation is not counted as a code finding.

## Re-review Through 89db1bf

### Scope

Re-reviewed the complete Task 4 implementation through `89db1bf8d071489442ebe431fb141831be3aaacf`, concentrating on the three prior findings and regressions in the two repositories and their integration tests. The corrective commit changes only `LocaleRepository`, both Task 4 integration tests, and the implementation report; `TipologiaRepository` production behavior is unchanged. Dynamic gates were not rerun under the accepted local Docker/PHP limitation.

### Findings

No remaining findings.

### Prior Findings

- **Resolved: strict `localeId` validation.** `upsertApi()` passes the original mixed value to `api_remote_id()` before lookup or persistence. Native integers must be positive; all other scalar/object/array types are rejected unless the value is an all-digit string. Zero-only strings are rejected, leading zeroes are normalized, and length plus lexical comparison against `PHP_INT_MAX` rejects overflow before the final cast (`includes/Repositories/LocaleRepository.php:118-128`, `includes/Repositories/LocaleRepository.php:238-263`). Tests reject floats, booleans, signed, decimal, alphanumeric, whitespace, zero-string, and overflowing inputs, while documenting intentional acceptance of a bounded positive digit string (`tests/Integration/LocaleRepositoryTest.php:182-232`). This closes the prior Medium finding.
- **Resolved: exact manual ownership assertion.** The test populates every meaningful optional venue field, snapshots the complete persisted DTO with `toArray()`, invokes API upsert with conflicting data, and compares the complete post-call state exactly, including IDs, source, all mapped fields, and timestamps (`tests/Integration/LocaleRepositoryTest.php:151-180`). The implementation still returns the matching manual row before any API mapping or write (`includes/Repositories/LocaleRepository.php:125-128`). This closes the first prior Low finding.
- **Resolved: deterministic timestamp refresh contracts.** Each lifecycle test writes a fixed old `updated_at` value directly, reloads and verifies that fixture, performs the repository update, then proves `created_at` is unchanged and `updated_at` no longer has the old value (`tests/Integration/TipologiaRepositoryTest.php:100-142`, `tests/Integration/LocaleRepositoryTest.php:63-102`). The production saves continue to omit `created_at` from update maps and assign `current_time( 'mysql', true )` to `updated_at` (`includes/Repositories/TipologiaRepository.php:72-103`, `includes/Repositories/LocaleRepository.php:69-109`). This closes the second prior Low finding without depending on same-second wall-clock changes.

### No-regression Review

- SQL preparation, explicit write formats, trusted fixed table suffixes, fixed ordering, escaped LIKE values, and shared search/count predicates are unchanged by the corrective commit (`includes/Repositories/LocaleRepository.php:28-109`, `includes/Repositories/LocaleRepository.php:144-223`; `includes/Repositories/TipologiaRepository.php:27-162`).
- DTO hydration and precise list return PHPDoc remain unchanged (`includes/Repositories/LocaleRepository.php:36-60`, `includes/Repositories/LocaleRepository.php:144-168`; `includes/Repositories/TipologiaRepository.php:35-64`).
- Code uniqueness, safe write failures, positive activation IDs, predefined-row deletion protection, pagination clamping, deterministic ordering, source mapping, and API/manual ownership behavior retain their original contracts and test coverage.
- The new helper and tests use PHP 7.4-compatible syntax and follow the existing WPCS shape by static inspection. `git diff --check 330e9ce 89db1bf` reported no whitespace errors for the corrective range.

### Spec Verdict

**PASS**

All Task 4 requirements reviewed in the original report are satisfied through `89db1bf`, including strict positive API identity handling without lossy coercion or integer overflow.

### Quality Verdict

**PASS**

The three prior findings are closed with targeted implementation and behavioral test changes. No code-quality or regression finding remains in the reviewed scope. Review quality score: **100%** with zero must-fix or should-fix items.

### Remaining Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

### Residual Risk

PHPUnit, PHPCS, PHPStan, the full Composer check, and the distribution build remain unexecuted because the accepted Docker/PHP environment limitation is unchanged (`.superpowers/sdd/task-4-report.md:106-133`). This is residual verification risk, not a remaining Task 4 finding.
