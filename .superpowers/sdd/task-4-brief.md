# Task 4 Brief — Event-type and venue repositories

Implement only Task 4 from the approved plan. Read `CONVENTIONS.md`, Task 4 plan text, the schema, and the `Locale`/`TipologiaEvento` DTOs. Do not implement admin pages, title/event repositories, or synchronization orchestration.

## Context

- Tasks 1–3 are complete through `e3253d8`.
- Inject `\wpdb`; never hide a global inside repository methods.
- Return DTOs, never raw database rows.
- Local Docker/PHP gates are unavailable by user decision; preserve TDD order, attempt commands, and provide static evidence.
- Do not stage coordinator/review artifacts or state YAML.

## Files

- Create `includes/Repositories/TipologiaRepository.php`
- Create `includes/Repositories/LocaleRepository.php`
- Create `tests/Integration/TipologiaRepositoryTest.php`
- Create `tests/Integration/LocaleRepositoryTest.php`

## Interfaces

`TipologiaRepository`:

- `__construct(\wpdb $db)`
- `findByCode(string $code): ?TipologiaEvento`
- `findAll(bool $activeOnly = false): array`
- `save(TipologiaEvento $type): int`
- `setActive(int $id, bool $active): void`
- `deleteCustom(int $id): bool`

`LocaleRepository`:

- `__construct(\wpdb $db)`
- `find(int $id): ?Locale`
- `findByRemoteId(int $remoteId): ?Locale`
- `save(Locale $locale): int`
- `upsertApi(array $data): int`
- `search(array $filters, int $page, int $perPage): array`
- `count(array $filters = array()): int`

Document array DTO return types precisely in PHPDoc.

## Required behavior

- Table names are trusted `$wpdb->prefix` plus fixed suffixes.
- All values use `$wpdb->prepare()`, `$wpdb->insert()`, `$wpdb->update()`, or `$wpdb->delete()` with explicit formats. Fixed ORDER BY fragments come from internal allowlists only.
- `findByCode('01')` preserves `'01'` and returns CINEMA after activation seeding.
- `findAll(true)` returns active rows only; ordering is code ascending with string codes preserved.
- `save()` explicitly maps all allowed fields. Insert sets both timestamps; update preserves `created_at`, refreshes `updated_at`, and returns the local ID. Duplicate codes and failed writes throw an actionable `RuntimeException` without leaking SQL.
- `setActive()` validates a positive ID and updates only `attivo`/`updated_at`; missing ID throws `RuntimeException`.
- `deleteCustom()` returns false for missing/predefined rows, deletes only `predefinito=0`, and returns true only when one row was deleted.
- Venue search supports exact sanitized `provincia`, exact/partial documented `comune` (choose exact for deterministic admin filters), and case-insensitive text search across name/code/comune. It clamps page/perPage to positive values, uses offset, and orders by `nome ASC, id ASC`.
- Venue `count()` uses exactly the same filter predicates as `search()`.
- `save(Locale)` explicitly maps all fields/source. New manual DTOs remain `source=manual`; updates preserve the supplied valid source (`api|manual`) and created timestamp.
- `upsertApi()` requires positive `localeId` and non-empty `locale`, otherwise throws `InvalidArgumentException`. Map API keys: `localeId`, `locale`, `localeCodice`, `indirizzo`, `cap`, `comune`, `provincia`, `mappa`. New row gets `source=api`. Existing `source=api` is updated. Existing `source=manual` with the same remote ID is returned unchanged.

## Tests/TDD

Write tests first for:

- leading-zero code lookup; active filtering/order; custom insert/update/disable/delete; predefined delete rejection; duplicate/failing writes.
- venue insert/find/update; API create/update; manual ownership preservation; invalid API payloads.
- combined venue filters, count parity, page boundaries, deterministic equal-name ordering, and SQL-injection-shaped filter values producing no broadened result.
- DTO return types and unchanged created timestamps on update.

Attempt red/green:

`docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"`

Attempt full `composer check`; record environment failure. Statically inspect SQL placeholders, fixed identifiers/order, formats, method signatures, DTO hydration, PHP 7.4/WPCS shape, and whitespace.

## Commit/report

Inspect status/diff/log; stage only Task 4 implementation/tests and `.superpowers/sdd/task-4-report.md`. Commit `feat: persist event types and venues`. Report exact commands/results, static checks, hash, self-review, concerns. Return concise status/hash/verification/concerns.
