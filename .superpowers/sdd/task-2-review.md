# Task 2 Review

Spec compliance: PASS

Code quality: CHANGES REQUIRED

## Finding Counts

- Critical: 0
- Important: 1
- Minor: 2

## Critical

No critical findings.

## Important

1. A failed default insert can permanently leave the installation with fewer than the required 62 event types. `seed_event_types()` first treats any non-empty table as fully seeded, then inserts rows individually and throws immediately when one insert fails. If row N fails after earlier rows succeeded, those earlier rows remain; the next activation returns at the count check and never inserts rows N through 62. Make the seed operation atomic or remove this activation attempt's inserted rows before throwing, and cover retry after a forced mid-seed failure. (`includes/Database/SchemaInstaller.php:232`, `includes/Database/SchemaInstaller.php:237`, `includes/Database/SchemaInstaller.php:253`)

## Minor

1. The defaults test proves only the count, code uniqueness, and the first leading-zero code. It does not compare all 62 code/description pairs with the approved Appendix A catalog, so a changed code or description can pass while violating the exact-default requirement. (`tests/Integration/SchemaInstallerTest.php:102`, `tests/Integration/SchemaInstallerTest.php:104`)

2. Uninstall's destructive cleanup has no automated contract test. Static inspection confirms the current script drops the seven fixed tables, deletes the four approved options, clears only the approved cron hook, and uses prepared transient patterns, but regressions in this exact-removal boundary would not be detected by `SchemaInstallerTest`. (`uninstall.php:17`, `uninstall.php:27`, `uninstall.php:31`, `uninstall.php:35`, `uninstall.php:37`, `tests/Integration/SchemaInstallerTest.php:23`)

## Spec Compliance Notes

- The implementation defines exactly seven custom tables with the approved fields, types, uniqueness rules, event-date index, and reconciliation columns/indexes. Every statement uses WordPress charset/collation and explicit InnoDB.
- The current catalog matches all 62 Appendix A code/description pairs on static comparison, including leading-zero codes and codes `41` and `42` without markers.
- Seeding occurs only when the event-type table is empty and preserves existing `attivo` choices on normal repeated activation.
- The entry point registers only static `Plugin::activate` and `Plugin::deactivate` lifecycle callbacks. Deactivation only clears `cinebot_wp_sync_event` and does not delete persisted data.
- Single-site uninstall is guarded by `WP_UNINSTALL_PLUGIN` and removes only the seven fixed plugin tables, four approved options, approved cron hook, and matching normal transient value/timeout rows. Dynamic SQL values are prepared; interpolated identifiers are derived from trusted WordPress identifiers and fixed suffixes.
- The changes use PHP 7.4-compatible syntax, PSR-4 class placement, and targeted WPCS suppressions. No Task 3 or later behavior was introduced.

## Verification Boundary

Per the accepted environment constraint, Docker-dependent PHPUnit, WPCS, PHPStan, build, and PHP syntax gates were not rerun. Their unavailable local runtime is an environment gap, not a defect. Static review found no schema/default catalog drift, unprepared dynamic values, lifecycle scope leakage, Multisite behavior, credentials, or deactivation deletion.

The attempted RTK-prefixed repository checks could not execute because `rtk` is unavailable in this environment; no direct fallback or dynamic gate was run during this review.

## Audit Rationalization Check

Supply-chain checks are not applicable because Task 2 adds no dependency. Provenance metadata and unrelated repository-wide heuristics were not used to expand this review beyond the user-specified Task 2 range. Dynamic verification was skipped only because the user explicitly accepted the missing Docker/PHP environment.

---

## Re-review Through `3007fcb`

Spec compliance: PASS

Code quality: APPROVED

### Remaining Finding Counts

- Critical: 0
- Important: 0
- Minor: 0

### Prior Findings

1. **Resolved, Important: atomic seed and retry.** Empty-table seeding now starts a transaction, inserts the complete catalog, commits only after all inserts succeed, and attempts rollback before returning the safe translated failure. The regression test uses a separate real `wpdb` connection to fail the fifth insert, proves the first attempt leaves zero rows, retries through the public installer, and proves all 62 rows are then committed. (`includes/Database/SchemaInstaller.php:228`, `includes/Database/SchemaInstaller.php:238`, `includes/Database/SchemaInstaller.php:243`, `includes/Database/SchemaInstaller.php:247`, `includes/Database/SchemaInstaller.php:259`, `includes/Database/SchemaInstaller.php:286`, `tests/Integration/SchemaInstallerTest.php:166`, `tests/Integration/SchemaInstallerTest.php:212`, `tests/Integration/SchemaInstallerTest.php:215`, `tests/Integration/SchemaInstallerTest.php:217`)

2. **Resolved, Minor: exact catalog contract.** The test now asserts 62 rows, 62 unique codes, and a canonical SHA-256 over every ordered `codice<TAB>descrizione` pair. The production catalog remains unchanged from the previously verified Appendix A match, including leading-zero codes and codes `41` and `42`. (`tests/Integration/SchemaInstallerTest.php:134`, `tests/Integration/SchemaInstallerTest.php:137`, `tests/Integration/SchemaInstallerTest.php:139`, `tests/Integration/SchemaInstallerTest.php:313`)

3. **Resolved, Minor: uninstall boundary contract.** The new integration test installs all seven tables, creates unrelated table/option/cron/transient controls, executes the guarded uninstall script, and verifies that every approved artifact is removed while every unrelated control remains. Fixture cleanup and schema restoration run in `finally`. (`tests/Integration/UninstallTest.php:48`, `tests/Integration/UninstallTest.php:53`, `tests/Integration/UninstallTest.php:56`, `tests/Integration/UninstallTest.php:61`, `tests/Integration/UninstallTest.php:68`, `tests/Integration/UninstallTest.php:73`, `tests/Integration/UninstallTest.php:78`, `tests/Integration/UninstallTest.php:92`)

### Regression Review

- The production schema still contains exactly the seven approved `cinebot_` tables. Their fields, nullable remote IDs, unique/scoped keys, event-date index, reconciliation fields/indexes, charset/collation, and explicit InnoDB options are unchanged. (`includes/Database/SchemaInstaller.php:79`, `includes/Database/SchemaInstaller.php:84`, `includes/Database/SchemaInstaller.php:220`)
- The entry point still registers only static `Plugin::activate` and `Plugin::deactivate` callbacks. Activation installs through global `$wpdb`; deactivation only clears `cinebot_wp_sync_event` and deletes nothing. (`cinebot-wp.php:23`, `cinebot-wp.php:24`, `includes/Plugin.php:41`, `includes/Plugin.php:50`)
- Uninstall production behavior remains single-site and constrained to the seven fixed tables, four approved options, one cron hook, and prepared normal transient value/timeout patterns. (`uninstall.php:8`, `uninstall.php:17`, `uninstall.php:27`, `uninstall.php:31`, `uninstall.php:35`, `uninstall.php:37`, `uninstall.php:39`)
- The follow-up adds only recoverable schema seeding, its tests, uninstall contract coverage, and corresponding Task 2 plan/report documentation. No models, repositories, services, synchronization, admin UI, shortcode, Multisite loop, new dependency, credential handling, or other later-task behavior was introduced.

### Verification Boundary

Docker/PHP-dependent PHPUnit, WPCS, PHPStan, build, and syntax gates were not rerun, as explicitly requested. Their unavailability remains an environment gap rather than a finding. This re-review is based on direct source, test, handoff, and static scope inspection through commit `3007fcba0de52ec356304da7abbdcf15c657f77a`.

### Audit Rationalization Check

No applicable correctness, security, performance, clarity, scope, or test-coverage item was skipped. Supply-chain review is not applicable because the fix adds no dependency; unavailable dynamic gates were not treated as passing.
