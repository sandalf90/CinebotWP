# Task 7 Implementation Report

## Scope

- Added `CinebotWp\Services\SettingsService`.
- Added isolated unit coverage in `tests/Unit/SettingsServiceTest.php`.
- Did not add HTTP, admin form, cron, AJAX, coordinator state, or review artifacts.

## TDD

- Wrote the settings and credential tests before production code.
- Red attempt: `docker compose run --rm php composer test:unit -- --filter SettingsServiceTest`.
- Red result: accepted-unavailable because the Docker Desktop Linux engine pipe was absent. The test referenced the then-missing `SettingsService` implementation.
- Green attempt: repeated the filtered unit command after implementation.
- Green result: accepted-unavailable for the same Docker engine failure.

## Implementation

- Public views expose exactly username, frontend, frequency, enabled state, base URL, and `has_password`.
- Input normalization uses strict frequency and enabled allowlists, non-coercive positive integer parsing with overflow rejection, WordPress username sanitation, and HTTPS URL component validation.
- Empty password submissions preserve an existing ciphertext and remain absent on first save.
- Passwords use AES-256-CBC with random IVs and `OPENSSL_RAW_DATA`.
- A 32-byte random project salt is strict-Base64 encoded and created with autoload disabled.
- Independent encryption and HMAC keys are derived from `AUTH_SALT`, the binary project salt, and distinct context labels.
- Storage is strict Base64 of `IV || ciphertext || HMAC-SHA256(IV || ciphertext)`.
- Decryption validates encoding, total structure, CBC block alignment, and HMAC before calling OpenSSL.
- All credential processing failures use one generic exception message and do not log secrets or ciphertext.

## Verification

- Filtered unit test: accepted-unavailable, Docker engine pipe absent.
- `composer check`: accepted-unavailable, Docker engine pipe absent.
- Local PHP fallback: unavailable because `php` is not installed on `PATH`.
- Whitespace: `git diff --check -- includes/Services/SettingsService.php tests/Unit/SettingsServiceTest.php` produced no findings.
- Static API check: all eight required public methods are present; no additional public methods exist.
- Static secrecy check: the six-key public projection excludes `api_password`; production code contains no output or logging calls.
- Static crypto check: required primitives are fail-closed; IV length comes from OpenSSL; salt and payload decoding are strict; payload size and block alignment are checked; `hash_equals` precedes `openssl_decrypt`; encryption and authentication contexts differ.
- Static compatibility check: implementation uses PHP 7.4-compatible syntax and WordPress option/sanitization APIs.

## Concerns

- Runtime PHPUnit, WPCS, PHPStan, and build results remain unconfirmed until Docker Desktop or a local PHP toolchain is available.
