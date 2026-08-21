# Task 7 Review

## Scope

Reviewed the complete committed Task 7 change from `35f5ae162311ea98ffa2a23ea9e43626f7ff9f29` through `00942da3c7a7df150a10444bb63f59a3e0bbc7ca`: `SettingsService`, its unit test, and the implementation report. The review used the Task 7 brief, reviewer package, approved plan and design security section, project conventions, Composer/PHPUnit/PHPCS configuration, and the implementation and test files at the committed revision. The review concentrated on public projection, option behavior, strict parsing, password preservation/replacement, cryptographic construction and failure handling, salt creation races/autoload, secret-safe errors, test isolation/coverage, PHP 7.4, and WPCS. Docker, PHPUnit, PHPCS, PHPStan, Composer checks, and the build were not rerun as requested.

## Findings

### Medium

1. **Credential input and stored payloads have no upper bound, allowing expensive encryption and pre-authentication memory exhaustion.** `save()` accepts an arbitrarily long non-empty password and sends it directly to OpenSSL (`includes/Services/SettingsService.php:59-61`, `includes/Services/SettingsService.php:224-243`). `decrypt()` strict-decodes the entire attacker-controlled option before applying any structural checks, and its only length rule is a minimum plus CBC block alignment (`includes/Services/SettingsService.php:252-265`). A large submitted password can bloat the potentially autoloaded settings option and every subsequent request; a large corrupted option can allocate substantially more memory before HMAC authentication. Define a defensible maximum plaintext credential size, reject larger saves with the generic exception, and reject encoded input by length before `base64_decode()`, followed by a decoded maximum check. Add boundary and over-limit tests for both save and read paths. This also makes the review package's payload-size claim materially complete (`.superpowers/sdd/task-7-review-package.md:7`).

### Low

1. **A `hash_hmac()` primitive failure is not checked explicitly.** Both encryption and decryption assume raw HMAC generation returned a 32-byte string (`includes/Services/SettingsService.php:240-243`, `includes/Services/SettingsService.php:267-272`). On PHP 7.4 the function contract permits `false`; during encryption that value can be coerced while constructing the payload, allowing `save()` to persist an undecryptable credential instead of immediately throwing the required safe `RuntimeException`. SHA-256 is normally guaranteed, so the practical likelihood is low, but the Task 7 fail-closed contract explicitly covers primitive failure (`.superpowers/sdd/task-7-brief.md:30`). Require a string of exactly `HMAC_LENGTH` in both paths before concatenation/comparison and test the failure behavior through an injectable primitive boundary or an isolated function-double process.

2. **The tests do not prove several explicit persistence and cryptographic failure properties.** Salt tests establish stability and decoded length only (`tests/Unit/SettingsServiceTest.php:270-284`); they do not inspect the option's `autoload` value or exercise the `add_option()` loser path that rereads a concurrently created salt (`includes/Services/SettingsService.php:309-327`). The malformed/tamper tests cover strict Base64, short/misaligned payloads, one altered ciphertext byte, and malformed salt (`tests/Unit/SettingsServiceTest.php:286-338`), but do not cover an oversized payload, a changed MAC byte, required-primitive unavailability/failure, separate KDF outputs, or observable HMAC-before-decrypt ordering. Add focused tests or a narrow injectable crypto/option boundary so the security guarantees are behavioral contracts rather than report-only static claims (`.superpowers/sdd/task-7-report.md:35-38`).

3. **The Task 7 PHP files contain multiple overlong lines that are likely to fail or warn under the unavailable WPCS gate.** Production examples include the array-shape annotations and compound conditions at `includes/Services/SettingsService.php:28`, `includes/Services/SettingsService.php:39`, `includes/Services/SettingsService.php:47`, `includes/Services/SettingsService.php:62`, `includes/Services/SettingsService.php:83`, `includes/Services/SettingsService.php:158`, and `includes/Services/SettingsService.php:334`. Test examples occur at `tests/Unit/SettingsServiceTest.php:180-181`. Reformat these before claiming `composer lint`; this is a static risk because PHPCS was not executed.

## Component Verdicts

### `SettingsService`

**FAIL - changes required**

- `get()` returns exactly the six approved keys and never returns plaintext or ciphertext; `password()` is the sole decrypted-password accessor (`includes/Services/SettingsService.php:25-41`, `includes/Services/SettingsService.php:78-88`). No output or logging call exists in the service.
- Defaults and option projection are explicit. Saving replaces all five non-secret settings with normalized submitted values/defaults while an omitted or empty password preserves a non-empty stored ciphertext; a first empty password remains absent (`includes/Services/SettingsService.php:49-69`). This matches the complete-form save semantics in the brief.
- Frequency is an exact four-value string allowlist; enabled accepts only `true`, `1`, `'1'`, and `'on'`; frontend accepts only positive native integers or all-digit positive strings no larger than `PHP_INT_MAX`, without float, boolean, sign, exponent, or whitespace coercion (`includes/Services/SettingsService.php:138-183`).
- Username sanitation and trimming are applied at save and read boundaries (`includes/Services/SettingsService.php:33-39`, `includes/Services/SettingsService.php:129-136`). Base URLs pass through `esc_url_raw()`, require HTTPS and a non-empty host, reject userinfo/query/fragment, lowercase the host, retain optional ports and paths, and remove trailing slashes (`includes/Services/SettingsService.php:185-219`).
- Encryption uses AES-256-CBC, OpenSSL's IV length, `random_bytes()`, `OPENSSL_RAW_DATA`, and Base64 of `IV || ciphertext || HMAC`; same-password saves receive fresh IVs (`includes/Services/SettingsService.php:221-247`).
- Encryption and authentication keys are deterministic 32-byte SHA-256 HMAC outputs with distinct context labels and include both `AUTH_SALT` and the binary 32-byte project salt (`includes/Services/SettingsService.php:288-304`). The labels provide key separation.
- Decryption strict-decodes Base64, checks minimum structure and CBC alignment, computes and compares the HMAC with `hash_equals()`, and only then extracts the IV/ciphertext and calls `openssl_decrypt()` (`includes/Services/SettingsService.php:249-286`). Tampering therefore fails before decryption for structurally valid bounded inputs.
- Salt creation uses 32 random bytes, strict Base64 decoding, exact decoded length, `add_option(..., 'no')`, and rereads the winner after an add race (`includes/Services/SettingsService.php:306-328`). The race does not produce divergent keys, although its loser path and autoload value lack tests.
- Primitive presence, OpenSSL/random failures, malformed salt/payload, MAC mismatch, and decrypt failure produce the same generic exception with no prior exception or secret included (`includes/Services/SettingsService.php:224-286`, `includes/Services/SettingsService.php:293-304`, `includes/Services/SettingsService.php:309-340`). The unchecked HMAC return and missing payload bounds prevent approval.
- Syntax is compatible with PHP 7.4 by static inspection. No dependency, SQL, HTTP, admin, cron, AJAX, Multisite, or React scope was introduced.

### `SettingsServiceTest`

**FAIL - coverage changes required**

- `setUp()` and `tearDown()` delete both Task 7 options, providing focused option isolation (`tests/Unit/SettingsServiceTest.php:21-37`). The suite does not mutate `AUTH_SALT` or other global state.
- Tests cover exact defaults/public keys, accessor values/types, every accepted frequency and fallback cases, strict frontend coercion/overflow cases, enabled allowlisting, HTTPS URL normalization/rejections, and username sanitation (`tests/Unit/SettingsServiceTest.php:39-217`).
- Password tests cover first encryption and round trip, absence of plaintext in storage, public secrecy, first-empty behavior, empty preservation, replacement, stable salt, and distinct ciphertext for repeated plaintext (`tests/Unit/SettingsServiceTest.php:219-284`).
- Malformed/tampered payload and salt tests require one exact generic exception and assert that neither a known secret nor the supplied ciphertext appears in its text (`tests/Unit/SettingsServiceTest.php:286-355`).
- The missing bounds, autoload/race, direct MAC mutation, primitive failure, KDF separation, and crypto-order coverage described above leave important security guarantees dependent on static inspection.

## Spec Verdict

**FAIL - changes required**

The public API, defaults, strict normalization, password preservation/replacement, ciphertext-only storage, random-IV AES-CBC construction, independent keys, HMAC-before-decrypt order, salt race algorithm, non-autoload salt creation call, generic exceptions, and scope boundaries conform by static inspection. Task 7 is not acceptance-ready because credential and encoded payload handling is unbounded and one permitted primitive-failure return is unchecked.

## Quality Verdict

**CHANGES REQUESTED**

The implementation is focused, readable, PHP 7.4-compatible, and substantially security-conscious. No credential exposure, plaintext/ciphertext logging, weak IV, shared key, unauthenticated decryption, unsafe exception text, or unrelated scope was found. The resource bounds, HMAC failure check, missing security regression coverage, and likely WPCS line findings prevent approval.

## Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 1 |
| Low | 3 |

## Residual Risk

The filtered unit suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build remain unexecuted under the accepted environment limitation (`.superpowers/sdd/task-7-report.md:29-42`). Direct `git diff --check 35f5ae1 00942da -- includes/Services/SettingsService.php tests/Unit/SettingsServiceTest.php` completed without whitespace errors during this review. The required `rtk` wrapper is unavailable on the host, so metadata and whitespace checks used direct Git commands; no Docker command was run.

## Audit Notes

Correctness, security, performance/resource handling, clarity, scope, option semantics, test isolation, PHP 7.4 syntax, WPCS shape, and the complete Task 7 diff were checked. Supply-chain and SQL-injection review are not applicable because no dependency or SQL changed. No audit item was silently skipped: executable tests, lint, analysis, and build were omitted only because Docker reruns were explicitly prohibited and the implementation report records that host PHP is unavailable.

## Re-review Through `4f03fdc`

### Scope

Re-reviewed the complete Task 7 range from `35f5ae162311ea98ffa2a23ea9e43626f7ff9f29` through corrective commit `4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f`. The updated brief, implementation report, reviewer package, prior review, final service, final test, corrective diff, cumulative diff, commit metadata, Composer/PHPUnit/PHPCS configuration, and WordPress test version were inspected. The re-review traced every prior finding and checked for regressions in credential exposure, public API shape, normalization, option semantics, encryption, authentication order, salt persistence, safe failures, test isolation, PHP 7.4 syntax, and WPCS formatting. No Docker, PHPUnit, PHPCS, PHPStan, Composer, or build command was run.

### Findings

No remaining findings.

### Prior Findings

1. **Resolved: plaintext, encoded, and decoded payload bounds and their boundaries.** A password is rejected above 4096 bytes before primitive checks, encryption, salt creation, or settings storage (`includes/Services/SettingsService.php:24-29`, `includes/Services/SettingsService.php:260-265`). Stored input is rejected above 8192 encoded bytes before Base64 decoding (`includes/Services/SettingsService.php:298-304`). After strict decoding, the service computes a runtime-IV-aware cap of `IV + 4096 + one 16-byte PKCS#7 block + 32-byte HMAC` and rejects larger decoded payloads before key derivation or authentication (`includes/Services/SettingsService.php:305-323`). Tests prove the accepted 4096-byte plaintext boundary and resulting maximum valid decoded payload by round trip, reject 4097 bytes without writing the settings option, prove 8192 encoded bytes reach the decoder and then fail decoded validation, and prove 8193 encoded bytes never reach the decoder (`tests/Unit/SettingsServiceTest.php:446-495`). The looser encoded cap remains finite and safely precedes allocation; valid payloads are constrained by the tighter decoded cap.
2. **Resolved: checked HMAC failures and fail-closed primitive behavior.** All four raw HMAC uses, comprising two KDF calls, payload authentication during encryption, and expected-MAC generation during decryption, now pass through one helper that requires a string of exactly 32 bytes (`includes/Services/SettingsService.php:286-289`, `includes/Services/SettingsService.php:325-330`, `includes/Services/SettingsService.php:352-386`). Primitive presence remains checked before credential processing, while thrown/false OpenSSL, random, HMAC, and decryption failures are converted to the same generic exception (`includes/Services/SettingsService.php:265-293`, `includes/Services/SettingsService.php:303-350`, `includes/Services/SettingsService.php:412-429`). Controlled wrappers prove an unavailable required primitive, an OpenSSL encryption failure, all three encryption-side HMAC failures, and the decryption-side HMAC failure; failed saves do not persist settings and exception text remains secret-safe (`tests/Unit/SettingsServiceTest.php:8-115`, `tests/Unit/SettingsServiceTest.php:128-163`, `tests/Unit/SettingsServiceTest.php:547-606`, `tests/Unit/SettingsServiceTest.php:669-682`).
3. **Resolved: salt autoload/race, key separation, direct MAC tampering, and HMAC-before-decrypt coverage.** Salt creation still uses 32 random bytes, `add_option(..., 'no')`, strict Base64 decoding, and exact decoded length; a losing add rereads the winning option rather than using its discarded salt (`includes/Services/SettingsService.php:388-410`). The test reads the WordPress 6.0 option row and requires `autoload = 'no'`, while the race double inserts a different winning salt, returns false to the attempted writer, and proves that the winning value decrypts the saved password (`tests/Unit/SettingsServiceTest.php:497-529`; pinned WordPress 6.0.12 test environment at `docker/prepare-tests.sh:4-17`). Distinct context labels still derive separate 32-byte encryption and authentication keys, now behaviorally asserted (`includes/Services/SettingsService.php:352-374`, `tests/Unit/SettingsServiceTest.php:531-545`). A direct mutation of the final MAC byte produces the generic failure with zero OpenSSL decrypt calls; a failed expected-MAC computation also leaves decrypt calls at zero (`tests/Unit/SettingsServiceTest.php:650-682`). Production order remains strict decode, encoded/decoded/structural checks, key derivation and checked HMAC, `hash_equals()`, then `openssl_decrypt()` (`includes/Services/SettingsService.php:298-350`).
4. **Resolved: WPCS line-shape risk.** Every production line cited in the prior review was reflowed, including array-shape annotations, public projection conditions, password preservation, frontend overflow parsing, primitive lists, and OpenSSL calls (`includes/Services/SettingsService.php:31-95`, `includes/Services/SettingsService.php:107-118`, `includes/Services/SettingsService.php:173-199`, `includes/Services/SettingsService.php:267-292`, `includes/Services/SettingsService.php:305-350`, `includes/Services/SettingsService.php:415-429`). The cited URL provider rows were also expanded (`tests/Unit/SettingsServiceTest.php:329-351`). Static scans found no line over 120 characters in either Task 7 PHP file. Namespaced test-double functions are enclosed by a targeted WPCS prefix-suppression pair (`tests/Unit/SettingsServiceTest.php:8-116`). Executable PHPCS confirmation remains residual risk rather than an open finding.

### No-regression Review

- The public surface remains exactly the required eight methods: `get()`, `save()`, `username()`, `password()`, `frontend()`, `frequency()`, `enabled()`, and `baseUrl()` (`includes/Services/SettingsService.php:43-146`). No public method, constructor requirement, or return shape changed. `get()` still returns exactly the six approved non-secret keys and `password()` remains the only plaintext accessor (`includes/Services/SettingsService.php:31-56`, `includes/Services/SettingsService.php:104-118`).
- Defaults, strict frontend/frequency/enabled parsing, username sanitation, HTTPS URL normalization, complete-form option semantics, empty-password preservation, and non-empty replacement are unchanged except for rejection of oversized non-empty credentials (`includes/Services/SettingsService.php:71-95`, `includes/Services/SettingsService.php:148-255`).
- AES-256-CBC, runtime IV length, fresh random IVs, `OPENSSL_RAW_DATA`, `IV || ciphertext || HMAC`, strict Base64 decoding, distinct KDF labels, `AUTH_SALT` plus project salt, and constant-time MAC comparison remain intact (`includes/Services/SettingsService.php:257-410`). The corrective helper strengthens failure handling without changing successful ciphertext construction or decryption.
- Generic safe exceptions remain the only credential-processing errors. No plaintext, ciphertext, key, Authorization header, prior exception, output, or logging call was introduced. Failed oversized/primitive saves occur before `update_option()` and cannot overwrite a previously stored settings option (`includes/Services/SettingsService.php:81-94`, `includes/Services/SettingsService.php:257-350`).
- Test controls and both WordPress options are reset in `setUp()` and `tearDown()`, so primitive/race counters and persisted state do not leak across cases (`tests/Unit/SettingsServiceTest.php:128-190`). The wrappers delegate to native functions unless a test explicitly selects one failure and do not alter the production class API (`tests/Unit/SettingsServiceTest.php:8-115`).
- The corrective commit changes only the Task 7 report, service, and test. No dependency, HTTP, admin, cron, AJAX, SQL implementation, Multisite, React, or unrelated application behavior changed. Production and test syntax remain PHP 7.4-compatible by static inspection.

### Spec Verdict

**PASS**

All four prior findings are closed through `4f03fdc`. Task 7 satisfies the public projection, strict parsing, option/password semantics, bounded credential processing, authenticated encryption, checked primitive failure, independent-key, random-IV, HMAC-before-decrypt, salt autoload/race, safe-exception, isolation, and coverage requirements by static inspection.

### Quality Verdict

**PASS**

The corrective implementation is narrow, fail-closed, resource-bounded, secret-safe, and PHP 7.4-compatible. Tests now contract every previously missing security boundary and failure/order property without expanding the production public API. No must-fix, should-fix, or advisory finding remains.

### Remaining Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

### Residual Risk

The filtered unit suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build remain unexecuted under the accepted Docker/PHP limitation (`.superpowers/sdd/task-7-report.md:66-78`). Static scans found no Task 7 PHP line over 120 characters, and cumulative `git diff --check 35f5ae1 4f03fdc` completed without findings for the service, test, and report. These unavailable dynamic gates are residual verification risk, not code findings.

### Audit Notes

Security, correctness, resource handling, public compatibility, parsing, option persistence, cryptographic construction/order, failure behavior, test isolation, scope, PHP 7.4 syntax, and WPCS shape were rechecked. Supply-chain and SQL-injection review remain not applicable because no dependency or SQL implementation changed. No audit item was silently skipped; only the explicitly prohibited unavailable dynamic gates were omitted from positive claims.
