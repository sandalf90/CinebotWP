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

## Review Fixes

### Changes

- Added a documented 4096-byte maximum for plaintext API passwords and reject larger values before encryption or option storage.
- Added an 8192-byte encoded payload maximum checked before Base64 decoding.
- Added a decoded payload cap derived from the runtime IV length, maximum padded password ciphertext, and 32-byte HMAC.
- Routed encryption, decryption, and both KDF contexts through one raw HMAC helper that requires an exact 32-byte string result.
- Preserved generic credential failures for all new rejection and primitive-failure paths.
- Reflowed every WPCS-risk line cited by the Task 7 review; neither Task 7 PHP file contains a line longer than 120 characters.

### Tests

- Added accepted-boundary and rejected-over-limit plaintext tests.
- Added encoded-boundary and pre-decoding oversized payload tests.
- Added direct MAC tampering and observable HMAC-before-decrypt tests.
- Added exact salt `autoload = 'no'` persistence coverage.
- Added salt creation race coverage proving the losing writer rereads and uses the winning salt.
- Added behavioral checks for distinct 32-byte KDF outputs.
- Added controlled required-primitive unavailability and HMAC failure coverage for encryption, decryption, and both KDF calls.
- Namespaced test wrappers delegate to native PHP and WordPress functions by default, leaving the production `SettingsService` API unchanged.

### Verification

- Red and green filtered-test attempts: accepted-unavailable because the Docker Desktop Linux engine pipe is absent.
- `composer check`: accepted-unavailable for the same Docker engine failure.
- `git diff --check -- includes/Services/SettingsService.php tests/Unit/SettingsServiceTest.php`: no whitespace findings.
- Static bounds check: plaintext is bounded before crypto/storage; encoded input is bounded before strict decoding; decoded data is bounded before authentication.
- Static crypto-order check: strict decoding and structural limits precede HMAC; checked HMAC and `hash_equals()` precede `openssl_decrypt()`.
- Static failure check: all raw `hash_hmac()` use is centralized behind exact type/length validation.
- Static WPCS line-length check: no lines exceed 120 characters in either Task 7 PHP file.

### Remaining Concern

- Runtime PHPUnit, WPCS, PHPStan, and distribution-build results remain unconfirmed until the accepted Docker/PHP environment limitation is removed.
