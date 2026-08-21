# Task 5 Review

## Scope

Reviewed the complete committed Task 5 implementation from `89db1bf8d071489442ebe431fb141831be3aaacf` through `1c3e3b1d90e58198a338911b35de4e0b140014d6`: all four hierarchy repositories and `ScheduleRepositoryTest`. The review used the Task 5 brief, approved plan, schema, DTOs, `ProgrammazioneCard`, project conventions, implementation report, and reviewer package. All implementation and test files were read directly in full. Docker, PHPUnit, PHPCS, PHPStan, Composer checks, and the build were not rerun because the user accepted the unavailable local Docker/PHP environment.

## Findings

### High

1. **Every reconciliation method reports selected IDs as affected even when the database update fails.** Each implementation first selects candidate IDs, calls `$wpdb->query()` without inspecting its `false` result or affected-row count, and then returns all preselected IDs (or their count). A failed title update can therefore return title IDs that remain active; orchestration can consume those IDs and deactivate descendants despite the parent update failing. Event, sector, and price methods have the same false-success behavior (`includes/Repositories/TitoloRepository.php:213-227`, `includes/Repositories/EventoRepository.php:196-207`, `includes/Repositories/SettoreRepository.php:168-177`, `includes/Repositories/PrezzoRepository.php:140-146`, `includes/Repositories/PrezzoRepository.php:171-180`). This violates the affected-ID/count contracts and the explicit requirement to handle database update failures. Check the query result, throw a safe `RuntimeException` on `false`, and ensure the returned IDs correspond to rows actually deactivated. Keeping the API/currently-active predicates in the `UPDATE`, not only in the preceding `SELECT`, would also prevent a stale candidate set from being reported or modified after concurrent state changes.

### Medium

1. **The integration suite does not test reconciliation write failures, so the High defect passes the advertised safe-failure coverage.** Reconciliation assertions exercise successful unseen/cascade paths and empty arrays only (`tests/Integration/ScheduleRepositoryTest.php:285-321`). No test forces `$wpdb->query()` to fail or verifies that a failed update cannot return affected IDs/counts. Add failure-path coverage for each distinct repository return contract: affected ID arrays for title/event/sector operations and an affected count for the price cascade.

2. **The claimed all-field CRUD/DTO mapping coverage does not inspect the field maps.** The main CRUD test assigns only a small subset of fields, checks complete values only for title tags/manual sync state, and otherwise asserts merely that child lookups contain DTO instances (`tests/Integration/ScheduleRepositoryTest.php:79-119`, fixture builders at `tests/Integration/ScheduleRepositoryTest.php:331-381`). It would pass if fields such as title author/cast/poster metadata, event organizer/access/map values, sector name, or price type/pre-sale/state were omitted, assigned the wrong format, or hydrated incorrectly. This is material because Task 5 explicitly requires every field and format map to be covered separately. Populate every meaningful property at all four levels and compare complete persisted DTO values, including decimal strings, nullable fields, API sync fields, and both timestamps.

### Low

1. **Parent ownership and direct-deletion tests do not establish the required negative behavior.** The suite proves positive ownership but tests only one non-positive event case; it never checks a valid child against a different valid parent at any hierarchy level (`tests/Integration/ScheduleRepositoryTest.php:104-110`). The deletion sequence removes each descendant before deleting its parent, so it also does not prove that `delete()` and delete-by-parent methods refrain from recursively deleting lower levels (`tests/Integration/ScheduleRepositoryTest.php:259-279`). Add wrong-positive-parent assertions for all three ownership methods and retain descendants while deleting a parent so direct-only behavior is observable.

2. **The public read-model test leaves most projection and pagination/count output uncontracted.** It verifies class type, event IDs, prices, and row counts, but not the complete 13-field `ProgrammazioneCard` projection; incorrect title/type/venue aliases or null handling could pass (`tests/Integration/ScheduleRepositoryTest.php:210-253`; required output in `includes/ReadModels/ProgrammazioneCard.php:33-49`). It also does not prove that count ignores a nonzero offset/limited page while retaining identical visibility/filter predicates. Assert one complete card and compare a paginated result against the unpaginated filtered count.

3. **Several new test statements are not in the repository's WPCS formatting shape and are likely lint failures.** Long inline closures and very long assertion/filter lines are left on single lines (`tests/Integration/ScheduleRepositoryTest.php:200`, `tests/Integration/ScheduleRepositoryTest.php:202`, `tests/Integration/ScheduleRepositoryTest.php:232`, `tests/Integration/ScheduleRepositoryTest.php:235-236`, `tests/Integration/ScheduleRepositoryTest.php:244`, `tests/Integration/ScheduleRepositoryTest.php:253`). The executable PHPCS result is unavailable, so this is a static quality finding rather than a claimed command failure. Expand the closures, arrays, and assertions into standard multiline WordPress formatting before the gate is rerun.

## Repository Verdicts

### `TitoloRepository`

**FAIL**

- Field map and 21-value format map align with the title schema; JSON tags use `wp_json_encode()` with a safe failure and hydrate only arrays, falling back to `[]` (`includes/Repositories/TitoloRepository.php:58-110`, `includes/Repositories/TitoloRepository.php:305-313`).
- Source validation and update ownership protection are present; manual saves force `sync_active=1` and `last_seen_sync=null`; inserts set both UTC timestamps while updates omit `created_at` (`includes/Repositories/TitoloRepository.php:58-108`).
- Admin search and count share one predicate builder, prepare dynamic values, escape LIKE input, preserve string type codes, clamp positive pagination, and fix ordering to `titolo ASC, id ASC` (`includes/Repositories/TitoloRepository.php:119-142`, `includes/Repositories/TitoloRepository.php:239-264`). Statistics expose exactly the required keys and `countByTypeCode()` is an exact prepared string comparison (`includes/Repositories/TitoloRepository.php:145-168`).
- Public projection aliases match `ProgrammazioneCard`; joins are valid in their generated order; active type/title/event visibility, event state 3, UTC-date default, inclusive `to` date, exact filters, allowlisted sorting, bounded pagination, active-sector/state-1 active-price aggregation, event-preserving price `LEFT JOIN`, and count predicate parity are present (`includes/Repositories/TitoloRepository.php:176-205`, `includes/Repositories/TitoloRepository.php:266-303`).
- The repository fails the reconciliation affected-ID/failure contract described in the High finding (`includes/Repositories/TitoloRepository.php:213-227`).

### `EventoRepository`

**FAIL**

- Explicit fields/formats match the event schema, API identity lookup is globally scoped, update ownership is preserved, manual synchronization state is forced, and DTO list/ownership/count/delete methods have the required direct semantics (`includes/Repositories/EventoRepository.php:31-134`, `includes/Repositories/EventoRepository.php:164-188`).
- Unseen and title-cascade selection predicates correctly scope positive parents, `source='api'`, and current active state; empty normalized arrays return before SQL and cannot generate `IN ()` (`includes/Repositories/EventoRepository.php:141-162`, `includes/Repositories/EventoRepository.php:216-227`).
- The repository fails the reconciliation affected-ID/failure contract described in the High finding (`includes/Repositories/EventoRepository.php:196-207`).

### `SettoreRepository`

**FAIL**

- Explicit fields/formats match the sector schema; remote lookup uses the required event scope; source/update ownership, manual state, DTO output, exact parent ownership, and direct delete semantics are implemented (`includes/Repositories/SettoreRepository.php:31-117`, `includes/Repositories/SettoreRepository.php:146-160`).
- Unseen and event-cascade predicates are API-only/currently-active, parent values are prepared, and empty normalized arrays return before SQL (`includes/Repositories/SettoreRepository.php:124-143`, `includes/Repositories/SettoreRepository.php:186-197`).
- The repository fails the reconciliation affected-ID/failure contract described in the High finding (`includes/Repositories/SettoreRepository.php:168-177`).

### `PrezzoRepository`

**FAIL**

- Explicit fields/formats match the price schema, including decimal strings written with `%s`; remote lookup uses the required sector scope; source/update ownership, manual state, DTO output, exact parent ownership, and direct delete semantics are implemented (`includes/Repositories/PrezzoRepository.php:31-121`, `includes/Repositories/PrezzoRepository.php:149-163`).
- Unseen and sector-cascade predicates are API-only/currently-active, parent values are prepared, and empty normalized arrays return before SQL (`includes/Repositories/PrezzoRepository.php:128-146`, `includes/Repositories/PrezzoRepository.php:189-200`).
- The repository fails the reconciliation affected-count/failure contract described in the High finding (`includes/Repositories/PrezzoRepository.php:140-146`, `includes/Repositories/PrezzoRepository.php:171-180`).

### `ScheduleRepositoryTest`

**FAIL**

- The suite covers successful CRUD paths, nullable manual identities, scoped/global remote lookups, title JSON fallback/failure, title update ownership, admin filters/order/count, statistics, public visibility/pricing/filtering/sorting, direct delete return values, reconciliation success/manual exclusion, and empty cascade inputs.
- It does not satisfy the brief's separate all-field mapping, all-repository safe-write failure, negative parent ownership, demonstrably nonrecursive deletion, complete read-model output, reconciliation DB failure, or paginated count-parity coverage, as detailed above.

## Spec Verdict

**FAIL - changes required**

The persistence maps, JSON handling, ownership guards, direct delete implementations, admin query parity, public SQL construction, DTO/read-model boundaries, API-only reconciliation predicates, prepared dynamic values, and empty-`IN` guards are compliant by static inspection. Task 5 is not spec-complete because all four reconciliation implementations can falsely report affected records after a database update failure, and the required separate behavioral coverage is materially incomplete.

## Quality Verdict

**CHANGES REQUESTED**

The repository design is focused and uses injected `wpdb`, fixed identifiers/order fragments, prepared dynamic values, explicit DTO boundaries, and safe exception text. No secrets, new dependencies, authentication behavior, external API calls, or unprepared user-controlled SQL were found. Production syntax is PHP 7.4-compatible by static inspection. The ignored reconciliation results, incomplete behavioral contracts, oversized all-in-one test methods, and likely WPCS formatting issues prevent approval.

## Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 1 |
| Medium | 2 |
| Low | 3 |

The cross-cutting reconciliation defect is counted once even though it occurs in all four repositories.

## Residual Risk

The focused integration suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build remain unexecuted under the accepted environment limitation (`.superpowers/sdd/task-5-report.md:16-27`, `.superpowers/sdd/task-5-report.md:44-51`). An attempted `rtk git diff --check` during review could not run because `rtk` is not installed on the host; the implementation report records a successful direct `git diff --check` for the task files (`.superpowers/sdd/task-5-report.md:27`). These environment/tooling limitations are residual verification risk and are not included in the severity counts.

## Audit Notes

Supply-chain review is not applicable because Task 5 changes no dependencies. Security review found prepared dynamic values and fixed or allowlisted SQL structure, with no credential or authorization surface in scope. Performance-sensitive public reads use one event query and one grouped price subquery rather than per-row queries. No checklist item was silently skipped: runtime lint, static analysis, tests, and build were omitted only because of the explicitly accepted environment constraint.

## Re-review Through 7bb4fa0

### Scope

Re-reviewed the complete Task 5 implementation through `7bb4fa03eae778b97eff9692a002d7cfac40958e`, including the updated brief, implementation report, reviewer package, prior review, all four repository files, and the full integration test. The review concentrated on all six prior findings and regressions in SQL construction, public visibility/count parity, prepared values, and manual ownership. Docker, PHPUnit, PHPCS, PHPStan, Composer checks, and the build were not rerun under the accepted unavailable Docker/PHP environment.

### Findings

No remaining findings.

### Prior Findings

1. **Resolved: reconciliation failures, exact affected IDs, and stale-predicate protection.** Title reconciliation now selects candidates with the complete frontend/API/current-active/unseen predicate, repeats that predicate in each conditional update, throws a safe exception when `$wpdb->query()` returns `false`, and appends an ID only when exactly one row was changed (`includes/Repositories/TitoloRepository.php:213-238`, `includes/Repositories/TitoloRepository.php:332-335`). Event, sector, and price helpers apply the same rule while retaining their original parent, unseen/cascade, API-source, and active predicates (`includes/Repositories/EventoRepository.php:141-162`, `includes/Repositories/EventoRepository.php:196-217`; `includes/Repositories/SettoreRepository.php:124-143`, `includes/Repositories/SettoreRepository.php:168-188`; `includes/Repositories/PrezzoRepository.php:128-146`, `includes/Repositories/PrezzoRepository.php:171-191`). A zero-row result is omitted as stale; the price cascade counts only collected IDs. All values, including IDs, parents, frontend IDs, tokens, source, state, and timestamp, remain prepared.

2. **Resolved: reconciliation failure tests.** A dedicated test uses a database double that allows candidate selection but returns `false` for reconciliation updates. It exercises the title affected-ID array, event affected-ID array, sector affected-ID array, and price affected-count contracts, requires safe `RuntimeException` messages, and confirms all four rows remain active (`tests/Integration/ScheduleRepositoryTest.php:459-509`, `tests/Integration/ScheduleRepositoryTest.php:561-573`). A separate zero-row double proves that a stale candidate is not returned and remains active (`tests/Integration/ScheduleRepositoryTest.php:511-535`).

3. **Resolved: complete field mappings and timestamp evidence.** The CRUD test assigns every meaningful title, event, sector, and price field, including nullable metadata, flags, decimal strings, source, sync state, hashes/tokens, and parent IDs, then compares complete DTO `toArray()` output (`tests/Integration/ScheduleRepositoryTest.php:79-132`, `tests/Integration/ScheduleRepositoryTest.php:544-559`). The shared assertion verifies generated `created_at` and `updated_at` for every repository insert. The update contract still deterministically proves title `created_at` preservation and `updated_at` refresh (`tests/Integration/ScheduleRepositoryTest.php:175-183`); the child implementations use the same required shape by assigning a fresh UTC `updated_at`, adding `created_at` only on insert, and omitting it from updates (`includes/Repositories/EventoRepository.php:47-85`, `includes/Repositories/SettoreRepository.php:47-78`, `includes/Repositories/PrezzoRepository.php:47-82`). Manual rows at all four levels are separately proven to force `sync_active=1`, clear `last_seen_sync`, and permit multiple null remote IDs (`tests/Integration/ScheduleRepositoryTest.php:134-173`).

4. **Resolved: negative parent ownership and direct deletion.** The test creates two valid positive parent chains and rejects cross-parent event, sector, and price ownership (`tests/Integration/ScheduleRepositoryTest.php:382-397`). It then deletes each parent while its direct descendant still exists and verifies the descendant remains. Delete-by-parent operations likewise leave lower descendants present until explicitly deleted (`tests/Integration/ScheduleRepositoryTest.php:399-415`). This establishes exact positive-parent checks and nonrecursive repository deletion.

5. **Resolved: complete public card and offset/count parity.** The public test compares every one of the 13 documented `ProgrammazioneCard` fields, including nullable poster and price fields and joined type/venue values (`tests/Integration/ScheduleRepositoryTest.php:285-320`, `tests/Integration/ScheduleRepositoryTest.php:575-596`). Matching combined filters with `limit=1` and `offset=1` produce an empty page while `countPublicSchedule()` remains one, proving count ignores pagination but preserves filter/visibility predicates (`tests/Integration/ScheduleRepositoryTest.php:323-338`).

6. **Resolved: WPCS formatting shape.** The reviewer-cited inline closure, statistics assertion, filter array, sorting assertions, and card-ID assertions are now expanded into conventional multiline WordPress formatting (`tests/Integration/ScheduleRepositoryTest.php:264-277`, `tests/Integration/ScheduleRepositoryTest.php:323-350`, `tests/Integration/ScheduleRepositoryTest.php:357-375`). Static inspection found no PHP syntax newer than 7.4. Executable PHPCS confirmation remains unavailable and is recorded only as residual risk.

### No-regression Review

- The corrective changes are confined to reconciliation result handling, safe reconciliation exceptions, tests, and the implementation report. Persistence field/format maps, source validation, manual synchronization state, JSON tags, timestamp save behavior, ownership checks, and direct delete implementations remain intact.
- Admin search/count still share one predicate builder, preserve leading-zero type codes, escape LIKE input, prepare dynamic values, clamp positive pagination, and use fixed `titolo ASC, id ASC` ordering (`includes/Repositories/TitoloRepository.php:119-167`, `includes/Repositories/TitoloRepository.php:250-275`).
- Public query construction is unchanged: projection starts from events with an event-preserving active-price `LEFT JOIN`; fixed inner joins require an active type and matching title/venue; visibility requires active title/event, event state 3, and the UTC/default or supplied date range; exact type/venue/city filters are prepared (`includes/Repositories/TitoloRepository.php:176-205`, `includes/Repositories/TitoloRepository.php:277-314`). Price aggregation still includes only active sectors and active state-1 prices, so events without qualifying prices retain null min/max. Sorting and direction remain allowlisted, limit remains 1-100, offset remains nonnegative, and count reuses the same joins and predicates without ordering or pagination.
- Reconciliation still excludes manual rows in both candidate selection and conditional updates. Manual saves continue to force active state and clear sync tokens, and updates still reject source conversion in every repository (`includes/Repositories/TitoloRepository.php:58-108`, `includes/Repositories/EventoRepository.php:47-83`, `includes/Repositories/SettoreRepository.php:47-76`, `includes/Repositories/PrezzoRepository.php:47-80`). Empty cascade arrays still return before SQL, preventing `IN ()` (`includes/Repositories/EventoRepository.php:155-162`, `includes/Repositories/SettoreRepository.php:137-144`, `includes/Repositories/PrezzoRepository.php:140-147`).
- No new dependency, secret, external API behavior, authentication surface, dynamic identifier, or user-controlled unprepared SQL was introduced. Per-candidate conditional updates trade additional queries for exact stale-candidate and affected-ID semantics; hierarchy batches are bounded by the synchronization input and preserve correctness without adding service-level transactions.

### Spec Verdict

**PASS**

All six prior findings are closed through `7bb4fa0`. The four repositories satisfy the Task 5 persistence, ownership, deletion, admin read, public schedule, DTO/read-model, and reconciliation contracts by static inspection, with targeted regression tests for the corrected behavior.

### Quality Verdict

**PASS**

The corrective implementation is explicit, safe, PHP 7.4-compatible, and narrowly scoped. Tests now contract the previously missing failure, stale-candidate, complete-mapping, ownership, nonrecursive-deletion, full-projection, pagination/count, and formatting behavior. No must-fix or should-fix item remains.

### Remaining Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

### Residual Risk

Runtime verification remains unavailable: the focused integration suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build could not execute because Docker Desktop's Linux engine and local PHP/Composer are unavailable (`.superpowers/sdd/task-5-report.md:87-94`). An attempted metadata command during this re-review also could not run because the required `rtk` wrapper is not installed; commit metadata and corrective scope were instead verified from the updated review package and direct full-file inspection (`.superpowers/sdd/task-5-review-package.md:3-7`, `.superpowers/sdd/task-5-review-package.md:20-64`). These accepted tooling limitations are residual verification risk, not code findings.

### Audit Notes

Security, correctness, performance, and clarity were rechecked. Supply-chain checks remain not applicable because dependencies did not change. No audit item was silently skipped or rationalized away; only executable gates and the unavailable `rtk` command were omitted from positive claims, and both limitations are stated above.
