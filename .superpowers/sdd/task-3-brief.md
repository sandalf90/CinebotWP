# Task 3 Brief — Typed DTOs and ProgrammazioneCard

Implement only Task 3 from the approved plan (currently lines 567–631). Read `CONVENTIONS.md`, the approved database schema, and the public projection in Task 5. Do not implement persistence, validation, WordPress hooks, or services.

## Context

- Tasks 1–2 are complete through `3007fcb`.
- Local Docker/PHP are unavailable by user decision. Attempt focused commands in TDD order and record exact failure; use rigorous static checks.
- Do not stage coordinator/review files or `specs/state.yaml`/`execution-status.yaml`.
- Existing plan interfaces intentionally use camelCase (`fromArray`, `toArray`, `frontendId`, `tipoeventoCodice`, `ProgrammazioneCard::fromRow`). Keep them for type consistency. Update `phpcs.xml.dist` with a narrowly documented exclusion for WPCS method/property snake-case messages only under `includes/`; do not disable local-variable, function, hook, or option naming rules.

## Files

- Create `includes/Models/Titolo.php`, `Evento.php`, `Settore.php`, `Prezzo.php`, `Locale.php`, `TipologiaEvento.php`, `SyncLog.php`
- Create `includes/ReadModels/ProgrammazioneCard.php`
- Create `tests/Unit/ModelsTest.php`
- Modify `phpcs.xml.dist` and matching plan configuration snippet only for the narrow OO naming exception

## Interfaces and fields

Every domain model provides `public static function fromArray(array $data): self` and `public function toArray(): array`, mapping database snake_case keys to typed public camelCase properties and back. Missing nullable values remain `null`, never `0`/empty string. Flags become `int`. `source` defaults to `manual`; `syncActive` defaults to `1`; `lastSeenSync` defaults to `null`; tags default to `[]`. Money properties are nullable decimal strings, never floats.

- `Titolo`: `id`, `idtitolo`, `frontendId`, `titolo`, `autore`, `esecutore`, `durata`, `scadenza`, `descrizione`, `tipoeventoCodice`, `locandinaFlag`, `locandinaUrl`, `cinetel`, `tmdb`, `trailer`, `cast`, `tag`, `source`, `syncHash`, `syncActive`, `lastSeenSync`, `createdAt`, `updatedAt`.
- `Evento`: `id`, `idevento`, `titoloId`, `inizio`, `organizzatoreId`, `organizzatoreCf`, `localeId`, `stato`, `otp`, `controlloaccessi`, `mappa`, `source`, `syncActive`, `lastSeenSync`, `createdAt`, `updatedAt`.
- `Settore`: `id`, `idsettore`, `eventoId`, `nome`, `source`, `syncActive`, `lastSeenSync`, `createdAt`, `updatedAt`.
- `Prezzo`: `id`, `idprezzo`, `settoreId`, `nome`, `tipo`, `importo`, `prevendita`, `stato`, `source`, `syncActive`, `lastSeenSync`, `createdAt`, `updatedAt`.
- `Locale`: `id`, `localeIdRemoto`, `nome`, `codice`, `indirizzo`, `cap`, `comune`, `provincia`, `mappa`, `source`, `createdAt`, `updatedAt`.
- `TipologiaEvento`: `id`, `codice`, `descrizione`, `predefinito`, `attivo`, `createdAt`, `updatedAt`.
- `SyncLog`: `id`, `startedAt`, `finishedAt`, `status`, `titoliAdded`, `titoliUpdated`, `eventiAdded`, `eventiUpdated`, `errorMessage`, `payloadHash`.
- `ProgrammazioneCard`: immutable/read-only-by-convention public typed properties for `eventoId`, `inizio`, `titoloId`, `titolo`, `descrizione`, `locandinaUrl`, `tipoCodice`, `tipoDescrizione`, `localeId`, `localeNome`, `comune`, `prezzoMin`, `prezzoMax`; provides `fromRow(array $row): self` only. It is the single raw joined-row boundary.

Use PHP 7.4 property types. IDs/flags are nullable or non-nullable exactly according to schema/defaults. Date/time values remain nullable strings except `Evento::inizio` and `ProgrammazioneCard::inizio`, which are strings. Required names/titles are strings with empty default only when hydration input omits them; no business validation belongs here.

## Tests

- Write `ModelsTest` first with a data provider covering all seven DTO round trips, nullable preservation, default ownership/reconciliation values, leading-zero type code, tag arrays, and money string precision.
- Add a `ProgrammazioneCard` projection test covering every projected key and null min/max prices.
- Assert `toArray()` emits exact database keys and no camelCase keys.
- Assert input arrays are not mutated.
- Attempt red: `docker compose run --rm php composer test:unit -- --filter ModelsTest` before implementations.
- Attempt green and full gate after implementation; statically inspect typed properties/signatures/key sets/PHP 7.4 syntax and whitespace.

## Commit/report

Inspect status/diff/log, stage only Task 3 files and `.superpowers/sdd/task-3-report.md`, then commit `feat: add cinebot domain models`. Report status, red/green results, static evidence, hash, self-review, and concerns. Return only concise status/hash/verification/concerns.
