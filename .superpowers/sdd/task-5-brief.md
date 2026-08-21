# Task 5 Brief — Schedule hierarchy repositories

Implement only Task 5 from the approved plan. Read `CONVENTIONS.md`, Task 5 plan text, schema, DTOs, and `ProgrammazioneCard`. Do not implement SyncService, admin UI, cron, caching, or transactions at service level.

## Context

- Tasks 1–4 complete through `89db1bf`.
- Inject `\wpdb` into each repository; return DTOs or `ProgrammazioneCard`, never undocumented rows.
- Dynamic values are prepared; identifiers/order directions are fixed or allowlisted.
- Local Docker/PHP unavailable by user decision: attempt TDD commands and provide static evidence.
- Never stage coordinator/review artifacts or state YAML.

## Files

- Create `includes/Repositories/TitoloRepository.php`
- Create `includes/Repositories/EventoRepository.php`
- Create `includes/Repositories/SettoreRepository.php`
- Create `includes/Repositories/PrezzoRepository.php`
- Create `tests/Integration/ScheduleRepositoryTest.php`

## Required interfaces

Implement every signature listed in Task 5, including:

- Title find/findByRemoteId/save/search/count/statistics/countByTypeCode/findPublicSchedule/countPublicSchedule/deactivateUnseenApi/delete.
- Event findByRemoteId/save/findByTitoloId/belongsToTitolo/countByTitoloId/countByLocaleId/deleteByTitoloId/deactivateUnseenApi/deactivateByTitoloIds/delete.
- Sector findByRemoteId/save/findByEventoId/belongsToEvento/deleteByEventoId/deactivateUnseenApi/deactivateByEventoIds/delete.
- Price findByRemoteId/save/findBySettoreId/belongsToSettore/deleteBySettoreId/deactivateUnseenApi/deactivateBySettoreIds/delete.

Use precise PHPDoc such as `array<int,Titolo>` and returned affected-ID arrays.

## Persistence rules

- Explicit field maps and formats; insert sets created/updated UTC timestamps, update preserves created timestamp and refreshes updated timestamp. Missing/failed update throws safe `RuntimeException`.
- Nullable remote IDs permit multiple manual rows; scoped sector/price uniqueness follows schema.
- Title tag is represented as array in DTO and stored as JSON text. Save uses `wp_json_encode()` and throws on failure; hydration decodes valid JSON arrays and falls back to `[]` for null/invalid/non-array JSON.
- `source` must be exactly `api|manual`; manual rows always retain `sync_active=1` and null `last_seen_sync` when saved manually. Do not silently convert ownership.
- Delete methods return true only when one row is deleted. Delete-by-parent returns affected row count. They do not recursively call other repositories; caller controls order/transaction.
- Ownership methods are positive-ID exact parent checks.
- Empty arrays passed to cascade methods return empty/0 without SQL and never create `IN ()`.

## Search/read behavior

Title admin `search/count` share predicates: optional `tipoevento_codice`, exact `source`, and escaped case-insensitive text across title/author. Page/per-page clamp positive; order `titolo ASC,id ASC`.

`statistics()` returns exactly keys `titoli_totali`, `titoli_manuali`, `eventi_totali`, `locali_totali`, `tipologie_attive` using separate safe counts or one scalar query. `countByTypeCode()` is exact string-code count.

Public filters accepted: `tipo`, `locale`, `comune`, `from`, `to`, `orderby` (`inizio|titolo` only), `order` (`ASC|DESC` only), `limit` (1–100), `offset` (>=0). Defaults: future from current UTC date, `inizio ASC`, limit 50, offset 0. `countPublicSchedule()` uses the identical visibility/filter predicates without limit/order.

Public visibility requires:

- title and event `sync_active=1`
- event `stato=3`
- event date within supplied/default range
- exact active type/venue/city filters
- one `ProgrammazioneCard` per event
- price min/max consider only prices with `sync_active=1` and API/business `stato=1`, under active sectors; an event with no active price still appears with null min/max (use LEFT JOIN/subquery correctly)
- title/type/venue joins and projection keys exactly match `ProgrammazioneCard::fromRow()`.

## Reconciliation behavior

- Every unseen/cascade update affects only `source='api'` and currently active rows.
- `TitoloRepository::deactivateUnseenApi(frontendId, token)` scopes by frontend and returns affected title IDs.
- Child `deactivateUnseenApi(parentId, token)` scopes by parent and returns affected child IDs.
- Cascade methods accept parent ID arrays, deactivate direct API children, and return affected child IDs where specified.
- No method touches manual rows. Tokens and parent/frontend inputs are prepared.

## Tests/TDD

Write `ScheduleRepositoryTest` first. Cover:

- multiple manual null remote IDs; API uniqueness behavior; all CRUD DTO mappings; tags JSON round-trip/invalid fallback; timestamps; safe failures.
- every parent lookup, ownership check, count, delete, delete-by-parent, empty-array no-op.
- admin filters/count parity/pagination/order/injection-shaped inputs.
- statistics and type counts.
- public projection, default future behavior, all combined filters, allowlisted sorting, limit/offset/count parity, active state-3 visibility, inactive title/event/sector/price behavior, price min/max, event without price.
- reconciliation unseen and cascades at each level, affected IDs, frontend/parent isolation, manual preservation, empty arrays.

Attempt red/green:

`docker compose run --rm php composer test:integration -- --filter ScheduleRepositoryTest`

Attempt full `composer check`, then statically inspect signatures, key maps, prepared SQL, allowlists, visibility predicates, no empty IN, DTO/read model hydration, WPCS/PHP7.4 shape, and whitespace.

## Commit/report

Inspect status/diff/log; stage only Task 5 files and `.superpowers/sdd/task-5-report.md`. Commit `feat: persist cinebot schedule hierarchy`. Report commands/results/static evidence/hash/self-review/concerns. Return concise status/hash/verification/concerns.
