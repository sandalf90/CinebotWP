# Task 8 Brief — Cinebot API client and poster URL

Implement only Task 8. Read `CONVENTIONS.md`, SettingsService public API, and API response design. Do not persist data or add sync/admin/cron behavior.

## Files/context

- Baseline through `4f03fdc`.
- Create `includes/Services/ApiClient.php`, `ApiException.php`, `LocandinaService.php`, `tests/Unit/ApiClientTest.php`, `tests/Unit/LocandinaServiceTest.php`.
- Local Docker/PHP unavailable by user decision; attempt TDD commands/static checks. Do not stage coordinator/review/state artifacts.

## Interfaces

- `ApiClient::__construct(SettingsService $settings, ?callable $httpGet = null)`; store callable in PHPDoc/untyped property.
- `fetchProgrammazione(): array`.
- `ApiException` extends `RuntimeException`; optional safe HTTP status accessor may be added for Task 11, but never store response bodies/credentials.
- `LocandinaService::build(string $host, string $path, int $titleId, int $flag): ?string`.

## API behavior

- URL: normalized SettingsService base + `/v1/programmazione`; append `/{$frontend}` only when non-null. No double slashes.
- Reject empty username/password before HTTP with safe ApiException.
- Default transport calls `wp_remote_get($url,$args)`. Args exactly: `Authorization: Basic {base64(username:password)}`, `Accept: application/json`, timeout 60, redirects bounded to 3, `reject_unsafe_urls=true`.
- Injected transport receives URL/args and makes tests independent.
- `WP_Error` → safe network ApiException without original message if it could expose URL/credentials.
- HTTP 401 → safe authentication error/status 401. Other non-200 → safe status error containing numeric code only.
- Limit response body to 10 MiB before decode; oversized → safe ApiException.
- Strict JSON object/associative array decode. Malformed JSON, scalar JSON, non-200 top-level `status` when present, non-null/non-empty top-level `error`, missing/non-array `programmazione` → safe ApiException. Valid empty `programmazione: []` is allowed.
- Return full associative payload unchanged after validation. Never log/echo headers, password, URL query, response body, or upstream error text.

## Poster URL

- flag `0` (or <=0) returns null without validating other values.
- Positive flag requires positive title ID, a host without scheme/userinfo/path/query/fragment/port, and a non-empty relative path.
- Normalize host lowercase; accept DNS host labels only (and optional localhost only if explicitly justified for tests—prefer reject).
- Trim surrounding path slashes; split segments; reject empty internal segments, `.`/`..`, backslashes, control chars, scheme markers, query/fragment. Raw-url-encode safe segments so result cannot escape path.
- Return `https://{host}/{path}/titolo/{id}/locandina`; sample must exactly equal `https://ticket.cinebot.it/martinovich/titolo/491/locandina`.
- Invalid positive-flag input throws safe `InvalidArgumentException`; no partial URL.

## Tests/TDD

Write tests first for URL with/without frontend, exact transport args/header/timeout/redirect/safe-URL flag, empty credentials, WP_Error, 401, 500, malformed/scalar/oversized JSON, payload status/error failures, missing/wrong/empty/valid programmazione, and no secret/body leakage in exception messages.

Poster tests: flag zero, exact sample, host/path normalization, nested safe path, positive title validation, schemes/userinfo/ports/slashes/control/domain errors, traversal/dot/backslash/query/fragment, encoded-danger inputs, and deterministic output.

Attempt red/green:
`docker compose run --rm php composer test:unit -- --filter "ApiClientTest|LocandinaServiceTest"`

Attempt `composer check`; static inspect exact headers/options, secret-free errors, body bound before decode, response shape validation, URL validation/encoding, PHP7.4/WPCS/whitespace.

## Commit/report

Write `.superpowers/sdd/task-8-report.md`; inspect status/diff/log; stage only Task 8 files/report; commit `feat: fetch cinebot programming api`. Return status/hash/verification/concerns.
