# Task 6 Brief — Synchronization log persistence

Implement only Task 6 from the approved plan. Read `CONVENTIONS.md`, sync-log schema, and `SyncLog` DTO. Do not implement SyncService, cron, Dashboard, or admin pages.

## Context/files

- Baseline through `7bb4fa0`.
- Create `includes/Repositories/SyncLogRepository.php` and `tests/Integration/SyncLogRepositoryTest.php`.
- Inject `\wpdb` and optional clock callable. Default clock returns `current_time('mysql', true)`. Keep PHP 7.4 compatibility.
- Local Docker unavailable by user choice; attempt commands and use static evidence. Do not stage coordinator/review artifacts/state.

## Interfaces

- `__construct(\wpdb $db, ?callable $clock = null)` (store callable in untyped/PHPDoc property because callable typed properties are unsupported)
- `start(string $payloadHash = ''): int`
- `finish(int $id, string $status, array $stats, ?string $error = null): void`
- `latest(): ?SyncLog`
- `recent(int $limit = 5): array<SyncLog>`
- `search(array $filters, int $page, int $perPage): array<SyncLog>`
- `count(array $filters = array()): int` (add because Task 15 pagination requires it; update plan interface list consistently)
- `deleteOlderThan(\DateTimeImmutable $cutoff): int`

## Behavior

- `start()` inserts UTC `started_at`, null finish, status `running`, zero counters, nullable/empty payload hash normalized to null. Return insert ID; safe RuntimeException on failure.
- `finish()` requires positive existing ID and status in `success|error|partial`. Map only four approved nonnegative integer counters; missing counters default zero, negatives clamp zero. Set UTC finish. Error is `sanitize_textarea_field` or null. Do not overwrite start/payload hash. Missing/failing update throws safe RuntimeException.
- `latest()` and `recent()` return DTOs ordered `started_at DESC,id DESC`; recent clamps 1–100.
- `search/count` share predicates. Supported filters: exact allowlisted status (`running|success|error|partial`), optional UTC `from` and `to` MySQL timestamps on `started_at`; invalid/empty ignored. Pagination positive; deterministic newest ordering.
- `deleteOlderThan()` uses cutoff formatted `Y-m-d H:i:s`, deletes only `started_at < cutoff`, returns affected count, throws on DB false.
- All values prepared/formatted; fixed trusted identifiers only. No log error exposes API credentials (repository sanitizes but caller still owns safe message).

## Tests/TDD

Write tests first for deterministic injected times, start defaults/hash, successful/partial/error finish, counter defaults/clamping, sanitized error, missing/failing writes, latest/recent limit/order, search/count parity and combined filters, injection-shaped status, pagination, strict retention boundary and delete failure. Assert DTOs and complete field values.

Attempt red/green:
`docker compose run --rm php composer test:integration -- --filter SyncLogRepositoryTest`

Attempt `composer check`; statically inspect SQL formats/placeholders, filter parity, allowlists, clock calls, DTO mappings, errors, WPCS/PHP7.4, whitespace.

## Commit/report

Write `.superpowers/sdd/task-6-report.md`. Inspect status/diff/log, stage only Task 6 files/report and necessary plan interface update, commit `feat: persist synchronization history`. Return status/hash/verification/concerns.
