# Task 8 Review

## Scope

Reviewed the complete committed Task 8 change from `4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f` through `8015d924e0a6b728a2520dfbfdad041fecfd83ad`: `ApiClient`, `ApiException`, `LocandinaService`, both focused unit tests, and the implementation report. The review used the Task 8 brief, reviewer package, approved plan and design, project conventions, `SettingsService`, Composer/PHPCS configuration, and the current committed implementation and tests. It concentrated on exact URL/options/authentication, settings and transport failures, `WP_Error` and HTTP statuses, body bounds and JSON shape, payload envelope semantics, secret/body leakage, poster flag/host/path/title validation, traversal and encoded-danger handling, canonical output, test coverage, PHP 7.4, and WPCS. Docker, PHPUnit, PHPCS, PHPStan, Composer checks, and the build were not rerun as requested.

## Findings

### Medium

1. **A JSON object in `programmazione` is accepted even though only a JSON array is valid.** The client first proves only that the top-level value is an object, then decodes the same body associatively and checks `is_array( $payload['programmazione'] )` (`includes/Services/ApiClient.php:88-108`). Associative decoding converts both JSON arrays and JSON objects to PHP arrays, so `{"programmazione":{}}` is accepted as the same empty PHP array as the explicitly valid `{"programmazione":[]}`; a non-empty object is also accepted. This violates the brief's distinction between non-array programming data and a valid empty array (`.superpowers/sdd/task-8-brief.md:26-28`) and makes the report's strict-shape claim inaccurate (`.superpowers/sdd/task-8-report.md:25-26`). Validate the `programmazione` property on the object decode before converting/returning the associative payload, and add empty and non-empty JSON-object rejection tests alongside the valid empty-array test. The current wrong-type coverage uses only a JSON string (`tests/Unit/ApiClientTest.php:254-282`).

2. **Settings failures escape the API client's safe exception contract and the planned caller will not catch them.** `fetchProgrammazione()` directly calls `SettingsService::password()` before constructing the request (`includes/Services/ApiClient.php:41-52`). Password retrieval can throw `RuntimeException` for oversized, malformed, tampered, or undecryptable stored credentials and unavailable/failing crypto primitives (`includes/Services/SettingsService.php:298-349`, `includes/Services/SettingsService.php:412-429`). That exception is safe in text but is not an `ApiException`, contrary to the client's declared credential/transport failure boundary (`includes/Services/ApiClient.php:34-40`); the approved synchronization design catches only `ApiException`, so such a settings failure would escape normal sync error handling (`docs/superpowers/specs/2026-08-02-cinebot-wp-plugin-design.md:353-370`). Convert credential-read failures to a fixed-message `ApiException` without chaining the original exception or retaining credential data, and add a corrupted-settings test proving transport is not called and only `ApiException` escapes.

### Low

1. **Several explicit boundary and defensive failure properties remain uncontracted by tests.** The oversized-body test proves only a value above 10 MiB, not that exactly 10 MiB is accepted and 10 MiB plus one byte is rejected (`tests/Unit/ApiClientTest.php:233-247`; bound at `includes/Services/ApiClient.php:83-86`). No test covers a transport callable that throws, so an exception containing upstream text currently bypasses the fixed safe messages at `includes/Services/ApiClient.php:54-73`; although core `wp_remote_get()` normally returns `WP_Error`, the injected transport contract and the general no-upstream-error-text guarantee are stronger if this behavior is normalized and tested (`.superpowers/sdd/task-8-brief.md:23-28`). Poster tests thoroughly cover syntax attacks but do not exercise the implemented 63-byte label and 253-byte total-host limits or encoded control-byte rejection (`includes/Services/LocandinaService.php:54-73`, `includes/Services/LocandinaService.php:81-90`; providers at `tests/Unit/LocandinaServiceTest.php:115-180`). Add exact body boundaries, a throwing-transport secrecy case if the callable is intended as a true transport boundary, host length boundaries, and representative `%00`/`%1f`/`%7f` cases.

## Component Verdicts

### `ApiClient`

**FAIL - changes required**

- URL construction removes trailing base slashes, appends exactly `/v1/programmazione`, and appends the normalized integer frontend only when non-null (`includes/Services/ApiClient.php:48-52`). The focused tests assert both exact scoped and unscoped URLs (`tests/Unit/ApiClientTest.php:42-89`).
- Request arguments are exactly the required Basic Authorization and JSON Accept headers, 60-second timeout, three redirects, and unsafe-URL rejection (`includes/Services/ApiClient.php:54-66`); the complete array is asserted exactly (`tests/Unit/ApiClientTest.php:42-70`).
- Empty normalized username/password values fail before transport with a fixed message (`includes/Services/ApiClient.php:42-46`, `tests/Unit/ApiClientTest.php:91-133`). Corrupted credential retrieval is not normalized to `ApiException`, as described in Finding 2.
- `WP_Error` and malformed transport return values receive fixed safe messages; HTTP 401 receives a fixed authentication message/status, while other non-200 responses disclose only their numeric status (`includes/Services/ApiClient.php:68-81`). Response bodies and `WP_Error` text are not interpolated or retained by these branches.
- The body is rejected above 10 MiB before either JSON decode (`includes/Services/ApiClient.php:83-93`). Malformed JSON, scalar top-level JSON, strict non-integer/non-200 payload status, non-null/non-empty payload errors, and absent/non-PHP-array programming data are rejected with fixed messages (`includes/Services/ApiClient.php:88-109`). Associative decoding loses the required JSON array/object distinction for `programmazione`, preventing approval.
- Valid payloads, including an empty programming array and extra keys, are returned as associative arrays unchanged relative to `wp_json_encode()`/`json_decode(..., true)` semantics (`includes/Services/ApiClient.php:93-111`, `tests/Unit/ApiClientTest.php:284-317`).
- No logging, output, retry, persistence, SQL, admin, cron, or synchronization behavior was introduced.

### `ApiException`

**PASS by static inspection**

- The type extends `RuntimeException`, stores only an optional integer HTTP status, and exposes it through `status()` (`includes/Services/ApiException.php:10-32`). It has no body, URL, credential, header, upstream exception, or arbitrary context field.
- The implementation is compatible with PHP 7.4. The optional status accessor conforms to the Task 8 brief (`.superpowers/sdd/task-8-brief.md:13-16`).

### `LocandinaService`

**PASS by static inspection**

- Any non-positive flag returns null before validating title, host, or path; a positive flag requires a positive title ID (`includes/Services/LocandinaService.php:21-27`).
- Hosts are lowercased and restricted to multi-label DNS syntax with non-empty labels of at most 63 bytes, total length at most 253 bytes, no leading/trailing hyphen, and no IP literal or localhost (`includes/Services/LocandinaService.php:29-32`, `includes/Services/LocandinaService.php:51-76`). Schemes, userinfo, ports, paths, queries, fragments, controls, underscores, malformed labels, and trailing dots consequently fail the same non-reflective exception path.
- Surrounding path slashes are normalized. Internal empty segments, dot segments, backslashes, controls, schemes, raw query/fragment delimiters, and encoded path/query/fragment/backslash/control delimiters are rejected before encoding (`includes/Services/LocandinaService.php:34-46`, `includes/Services/LocandinaService.php:78-91`). Accepted segments are independently `rawurlencode()`-encoded, so accepted percent text, including `%2e%2e`, is emitted literally as `%252e%252e` and cannot become traversal in the generated URL.
- The canonical output is deterministic and exactly `https://ticket.cinebot.it/martinovich/titolo/491/locandina` for the required sample (`includes/Services/LocandinaService.php:48`, `tests/Unit/LocandinaServiceTest.php:28-84`). Invalid positive-flag inputs throw only `Unable to build poster URL.` and cannot reflect host/path/title data.

### Tests

**FAIL - coverage changes required**

- `ApiClientTest` covers exact scoped/unscoped URLs and complete options, both individual empty credential cases and pre-transport failure, `WP_Error`, invalid transport returns, 401/500 statuses, malformed and scalar top-level JSON, oversized bodies, strict status values, scalar/array error values, missing/string programming data, valid empty programming data, full payload preservation, and message-level secret/body exclusion (`tests/Unit/ApiClientTest.php:39-317`).
- It does not expose the accepted JSON-object programming shape or settings exception escape. Exact body boundaries and throwing transports are also untested (`tests/Unit/ApiClientTest.php:233-282`).
- `LocandinaServiceTest` covers flag short-circuiting, exact canonical output, normalization, nested segment encoding, encoded traversal neutralization, determinism, title validation, scheme/userinfo/port/path/query/fragment/IP/localhost/DNS-label host failures, and raw/encoded path dangers (`tests/Unit/LocandinaServiceTest.php:18-199`). Host-length and encoded-control boundaries remain static-only evidence.
- Test setup removes both settings options before and after each API test (`tests/Unit/ApiClientTest.php:20-37`), preventing persisted credential/salt state from leaking between focused cases.

## Spec Verdict

**FAIL - changes required**

Exact request construction, authentication options, credential-presence checks, WordPress HTTP result handling, numeric status reporting, pre-decode body bounding, most payload-envelope rules, secret-safe fixed errors, poster validation, traversal resistance, segment encoding, canonical output, PHP 7.4 syntax, and scope boundaries conform by static inspection. Task 8 is not acceptance-ready because JSON object-valued `programmazione` passes as valid programming data and settings failures bypass the API exception boundary expected by downstream synchronization.

## Quality Verdict

**CHANGES REQUESTED**

The change is focused, readable, resource-bounded at the decode boundary, and substantially security-conscious. No direct credential, Authorization header, response body, URL query, or `WP_Error` message disclosure was found in handled branches. The two correctness defects and missing regression coverage prevent approval. Production and test syntax is PHP 7.4-compatible by static inspection; no clear WPCS syntax or naming violation was found, but PHPCS remains unexecuted and therefore cannot be claimed passing.

## Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 2 |
| Low | 1 |

## Residual Risk

The filtered unit suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build remain unexecuted under the accepted environment limitation (`.superpowers/sdd/task-8-report.md:9-16`, `.superpowers/sdd/task-8-report.md:31-34`). No dynamic command was run during this review. Consequently, PHP 7.4 and WPCS conclusions are static only, and the newly identified failures are reasoned from language/runtime semantics rather than reproduced output.

## Audit Notes

Correctness, security, resource handling, exception boundaries, URL and payload parsing, output determinism, test isolation/coverage, scope, PHP 7.4 syntax, and WPCS shape were checked. Supply-chain and SQL-injection review are not applicable because no dependency or SQL changed. No audit item was silently skipped; executable tests, lint, analysis, and build were omitted because the user explicitly prohibited a dynamic rerun.

## Re-review Through `d583f53`

### Scope

Re-reviewed the complete Task 8 range from `4f03fdcc0356049187ab7aebca9c8b9fd7c6dc7f` through implementation commit `8015d924e0a6b728a2520dfbfdad041fecfd83ad` and corrective commit `d583f53ca3e96359f518fc53c505c6762bc41bc6`. The updated brief, report, reviewer package, prior review, final services, and final focused tests were inspected. This re-review specifically traced each prior finding and checked native JSON shape validation, settings and transport throwable normalization, preserved `ApiException` status semantics, exact body bounds, DNS/encoded-control coverage, secret-safe errors, request URL/options, poster output, PHP 7.4 syntax, and WPCS shape. Docker, PHPUnit, PHPCS, PHPStan, Composer checks, and the build were not rerun as requested.

### Findings

No remaining findings.

### Prior Findings

1. **Resolved: JSON objects cannot pass as `programmazione`.** After proving the top-level JSON value is an object, the client now requires that the first decoded `programmazione` property exists and is a native PHP array before performing associative conversion (`includes/Services/ApiClient.php:100-108`). This distinguishes JSON `[]` from `{}` and rejects both empty and populated object values. The focused provider proves rejection of `{"programmazione":{}}` and `{"programmazione":{"frontend":50}}` without body/credential disclosure (`tests/Unit/ApiClientTest.php:335-361`), while valid empty and populated arrays remain accepted and returned unchanged (`tests/Unit/ApiClientTest.php:398-430`).
2. **Resolved: settings and generic transport throwables normalize to secret-safe `ApiException` values.** Credential, base URL, and frontend reads are enclosed in one `Throwable` boundary that emits only `Unable to prepare the Cinebot API request.` (`includes/Services/ApiClient.php:42-50`). The tampered-ciphertext test proves the exception type/message, excludes original/encrypted credential values, and proves the transport was never called (`tests/Unit/ApiClientTest.php:136-166`). The transport is independently wrapped: arbitrary throwables become only `Unable to connect to the Cinebot API.`, while an existing `ApiException` is rethrown as the exact same object (`includes/Services/ApiClient.php:60-78`). Tests prove upstream URL/secret text is excluded and that a legitimate status 429 survives intact (`tests/Unit/ApiClientTest.php:203-243`). Neither path chains or stores the caught exception, so it cannot retain the upstream message/body/URL through `getPrevious()`.
3. **Resolved: exact body-limit, host-boundary, and encoded-control contracts are tested.** The production comparison still rejects only lengths greater than 10 MiB before either `json_decode()` call (`includes/Services/ApiClient.php:95-108`). Tests construct valid JSON at exactly 10 MiB and at one byte above it, requiring successful parsing in the former case and a safe exception in the latter (`tests/Unit/ApiClientTest.php:308-333`, `tests/Unit/ApiClientTest.php:479-487`). Poster tests now accept exactly a 63-byte label and a 253-byte DNS host (`tests/Unit/LocandinaServiceTest.php:86-112`), reject a 64-byte label and a 254-byte host (`tests/Unit/LocandinaServiceTest.php:143-171`), and reject `%00`, `%1f`, and `%7f` path values (`tests/Unit/LocandinaServiceTest.php:196-218`). These correspond directly to the fixed host and segment validation (`includes/Services/LocandinaService.php:54-90`).

### No-regression Review

- Scoped and unscoped requests retain the exact normalized URLs and complete required options: Basic authorization, JSON Accept header, timeout 60, redirection 3, and `reject_unsafe_urls=true` (`includes/Services/ApiClient.php:55-73`, `tests/Unit/ApiClientTest.php:40-90`).
- Empty credentials still fail before transport; `WP_Error`, malformed transport values, HTTP 401, other non-200 statuses, malformed/scalar JSON, invalid status/error envelopes, missing programming data, and invalid programming types remain fixed-message safe failures (`includes/Services/ApiClient.php:51-105`, `includes/Services/ApiClient.php:112-124`, `tests/Unit/ApiClientTest.php:92-304`, `tests/Unit/ApiClientTest.php:363-396`). Handled messages do not interpolate body, URL, credentials, Authorization, or upstream error text.
- `ApiException` remains a PHP 7.4-compatible `RuntimeException` subtype that stores only the optional numeric status (`includes/Services/ApiException.php:10-32`). The corrected transport boundary deliberately preserves a pre-existing instance/status without modifying it (`includes/Services/ApiClient.php:74-78`, `tests/Unit/ApiClientTest.php:224-243`).
- Poster behavior remains flag-first, title-positive, DNS-only/normalized-host, path-segment validated and independently raw-URL-encoded. The canonical sample, traversal neutralization, deterministic output, non-reflective errors, and rejection of schemes/userinfo/ports/IPs/delimiters/dot segments/backslashes remain covered (`includes/Services/LocandinaService.php:21-91`, `tests/Unit/LocandinaServiceTest.php:18-237`).
- No dependency, persistence, synchronization, admin, cron, logging, SQL, retry, Multisite, React, or unrelated behavior was added. Production and test syntax remains PHP 7.4-compatible by static inspection. No clear WPCS naming, whitespace, or line-shape violation was found, although PHPCS was not executed.

### Spec Verdict

**PASS by static inspection**

Through `d583f53`, Task 8 satisfies the exact request URL/options/authentication, empty-credential handling, safe settings/transport/WordPress HTTP failures, 401 and numeric status behavior, pre-decode 10 MiB boundary, native-array `programmazione` validation, status/error envelope rules, valid empty programming behavior, secret/body non-disclosure, poster host/path/title/flag validation, traversal and encoded-danger resistance, canonical URL output, and focused regression coverage requirements.

### Quality Verdict

**PASS by static inspection**

The corrective change is narrow, preserves the legitimate `ApiException` status contract, fails closed at settings and transport boundaries without retaining caught throwables, and converts the previously ambiguous JSON shape into an explicit contract. Focused tests now cover every prior defect and the requested security boundaries. Dynamic quality gates remain residual verification risk only.

### Remaining Severity Counts

| Severity | Count |
|---|---:|
| Critical | 0 |
| High | 0 |
| Medium | 0 |
| Low | 0 |

### Residual Risk

The filtered unit suite, full PHPUnit suite, PHPCS, PHPStan, Composer check, and distribution build remain unexecuted because this review was expressly static-only and the updated Task 8 report records unavailable Docker/PHP tooling (`.superpowers/sdd/task-8-report.md:10-18`, `.superpowers/sdd/task-8-report.md:33-36`). The requested Docker/PHP commands were not run. A separate non-Docker `rtk git status --short; rtk git diff --check` attempt could not start because `rtk` is unavailable on the host. PHP 7.4 and WPCS conclusions are therefore static inspection only.

### Audit Notes

All three prior findings were checked against the final cumulative Task 8 source and tests, with exact request behavior and secret-safe failure paths rechecked for regressions. Supply-chain and SQL-injection review remain not applicable because no dependency or SQL changed. No audit item was silently skipped; only the user-prohibited dynamic gates were omitted from positive claims.
