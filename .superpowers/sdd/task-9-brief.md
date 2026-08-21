# Task 9 Brief — Atomic schedule synchronization

Implement only Task 9. Read `CONVENTIONS.md`, SyncService plan task, database/repository interfaces, ApiClient, LocandinaService, and SyncLogRepository. This task is high-risk; do not implement cron/admin UI.

## Files

- Create `includes/Services/SyncService.php`, `SyncResult.php`, `SyncLock.php`
- Create `tests/fixtures/cinebot-sample.json` from approved supplied JSON
- Create `tests/Integration/SyncServiceTest.php`, `tests/Integration/SyncLockTest.php`

## Interfaces

- `SyncService::sync(): SyncResult`, `syncPayload(array $payload): SyncResult`
- `SyncResult::isSuccess(): bool`, `status(): string`, `stats(): array`, `message(): string`
- `SyncLock::acquire(int $ttl = 300): ?string`, `release(string $token): bool`

## Lock

- Option `cinebot_wp_sync_lock`, non-autoload, JSON `token`/`expires_at` UTC integer.
- Acquire with `add_option`; no check-then-set race. If existing lock expired, delete only via option-name+exact-option-value compare and retry once. Generate token `bin2hex(random_bytes(32))`; validate ttl 1–3600.
- Release deletes only when stored token equals supplied token with `hash_equals`; malformed option fails closed. Never delete another owner lock.

## Synchronization

- `sync()` obtains lock, calls ApiClient, delegates to payload; contention returns non-success `locked` without calling API.
- `syncPayload()` validates top object / `programmazione` array and envelope `frontend` positive integer; no partial writes for malformed payload.
- Obtain lock in syncPayload too only when not already held internally; avoid double lock through private method.
- Canonical payload hash uses recursive key-sort then JSON + SHA-256, no secrets.
- Start SyncLog before transaction. Use `$wpdb->query('START TRANSACTION')`, commit, rollback checked for false. All tables InnoDB by Task 2.
- Map title fields (all DTO fields), poster from envelope host/path/id/flag, tags/cast safely; map embedded venues, events, sectors, prices exactly. API rows `source=api`; existing manual matching remote IDs are skipped entirely, including descendants.
- Every seen API hierarchy row gets active/token. Reconcile after child loops exactly per plan: unseen prices, sectors + price cascade, events + cascade, titles + cascade. Scope title unseen by envelope frontend. Returning rows reactivate via save.
- On any throwable: rollback, finish log error safely if possible, return `error` result with safe message; do not leak payload/API/credential. Always release own lock in finally.
- On commit: delete matching normal transient and timeout option rows using prepared patterns; finish success log. Stats count only actual inserted/updated titles/events (not skipped/manual/deactivated).
- No service may overwrite `source=manual`, including venues.

## Tests

Use fixture and explicit small payload builders. Cover first import mapping/poster/stats/log, idempotence, changed API updates, manual title/venue untouched, missing optional arrays, all reconciliation disappearance/return at price/sector/event/title, frontend isolation, rollback on malformed child and forced repository/DB failure, lock contention/expiry/nonowner, cache deletion only after commit, payload canonical hash invariance, no secret in result/log.

Attempt focused integration suite and `composer check`; static inspect transaction command order, lock compare/delete, manual guards, all repository calls, reconciliation order, error paths/commit cache, PHP7.4/WPCS/whitespace.

## Commit/report

Write task-9-report; stage only task files/report; commit `feat: synchronize cinebot schedules`. Return concise status/hash/verification/concerns.
