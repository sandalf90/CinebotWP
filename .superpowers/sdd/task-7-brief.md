# Task 7 Brief — Secure API settings

Implement only Task 7 from the approved plan. Read `CONVENTIONS.md` and the design API settings/security section. Do not implement HTTP, admin forms, cron, or AJAX.

## Context/files

- Baseline through `35f5ae1`.
- Create `includes/Services/SettingsService.php` and `tests/Unit/SettingsServiceTest.php`.
- Use WordPress options `cinebot_wp_settings` and `cinebot_wp_encryption_salt` only.
- Local Docker/PHP unavailable by user choice; attempt commands/static checks. Do not stage coordinator/review artifacts/state.

## Public interface

- `get(): array` returning only `api_username`, `api_frontend`, `sync_frequency`, `sync_enabled`, `api_base_url`, `has_password`; never ciphertext/plaintext.
- `save(array $input): array` returning the same public view.
- `username(): string`, `password(): string`, `frontend(): ?int`, `frequency(): string`, `enabled(): bool`, `baseUrl(): string`.

## Defaults/validation

- username empty; no password; frontend null; frequency `daily`; enabled false; base URL `https://ws.cinebot.it`.
- Accepted frequencies exactly `hourly|twicedaily|daily|weekly`; invalid → `daily`.
- Frontend: empty/null → null; accept positive native integer or all-digit positive string within PHP int range; malformed/zero/negative/float/bool → null.
- Enabled normalized explicitly from true/1/'1'/'on'; all other values false.
- Username `sanitize_text_field` and trim.
- Base URL: `esc_url_raw`, HTTPS scheme required, non-empty host required, reject userinfo/query/fragment, normalize host casing and trailing slash; invalid falls back to default. Permit an HTTPS path for test servers but strip trailing slash.
- Empty submitted password preserves existing encrypted password. Non-empty password replaces it. First save with empty remains absent.

## Encryption

- Require `openssl_encrypt`, `openssl_decrypt`, `random_bytes`, `hash_hmac`, `hash_equals`; throw generic safe `RuntimeException` if unavailable/failing.
- AES-256-CBC with random IV length from `openssl_cipher_iv_length` and `OPENSSL_RAW_DATA`.
- Generate 32 random salt bytes once, Base64 store in `cinebot_wp_encryption_salt` with autoload false. Strict-decode on read; malformed salt throws safe RuntimeException.
- Derive separate 32-byte encryption/HMAC keys from `AUTH_SALT` plus binary project salt and distinct context labels using SHA-256/HMAC or equivalent deterministic KDF.
- Stored password is strict Base64 of `iv || ciphertext || hmac_sha256(iv || ciphertext)`. Verify length and HMAC with `hash_equals` before decrypting. Tamper/malformed data throws one generic safe exception with no credential/ciphertext.
- Settings option may autoload; it stores ciphertext only. Never return or log it from public methods.

## Tests/TDD

Write tests first for defaults; every accepted frequency and fallback; strict frontend cases including coercion/overflow; enabled cases; HTTPS URL normalization/rejections; username sanitation; first password save, round trip, stored option lacks plaintext, public `get()` exposes only `has_password`; empty password preserve; replacement; stable salt; distinct ciphertext across same-password saves due random IV; tampered Base64/HMAC/truncated payload/malformed salt; safe exception text not containing password/ciphertext; accessor types.

Isolate options in setUp/tearDown. Do not assert exact random ciphertext.

Attempt red/green:
`docker compose run --rm php composer test:unit -- --filter SettingsServiceTest`

Attempt `composer check`; static checks for no plaintext, exact public keys, cryptographic order/length, separate key contexts, strict parsing, no secret error/log, PHP7.4/WPCS, whitespace.

## Commit/report

Write `.superpowers/sdd/task-7-report.md`; inspect status/diff/log, stage only Task 7 files/report, commit `feat: secure cinebot api settings`. Return status/hash/verification/concerns.
