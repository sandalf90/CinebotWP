# Task 8 Implementation Report

## Status

- Implemented `ApiClient`, `ApiException`, and `LocandinaService` only.
- Added focused unit coverage for authenticated API requests, response validation, safe failures, and poster URL construction.
- Left existing coordinator, review, progress, execution-status, and state artifacts unchanged and unstaged.

## TDD And Verification

- RED attempted: `docker compose run --rm php composer test:unit -- --filter "ApiClientTest|LocandinaServiceTest"`.
- GREEN attempted after implementation and again after final edits with the same command.
- Full gate attempted: `docker compose run --rm php composer check`.
- All Docker commands were blocked before test execution because the Docker Desktop Linux engine pipe was unavailable.
- Host fallback checks confirmed that neither `php` nor `composer` is installed.
- `git diff --check` completed without Task 8 whitespace errors.

## Static Audit

- Request URL is normalized and appends the frontend only when non-null.
- Request arguments include exact Basic authorization and JSON headers, a 60-second timeout, at most three redirects, and unsafe-URL rejection.
- Credentials are rejected before transport when empty.
- Exceptions use fixed safe text and retain neither credentials, Authorization headers, URLs, upstream errors, nor response bodies.
- HTTP status handling distinguishes 401 and stores only an optional numeric status.
- Response bodies are bounded to 10 MiB before JSON decoding.
- Top-level JSON must be an object with valid optional `status`/`error` fields and an array `programmazione` field.
- Poster hosts are restricted to normalized DNS names without schemes, userinfo, ports, paths, queries, fragments, localhost, or IP literals.
- Poster paths reject empty/dot/traversal/control/scheme/delimiter inputs and encode each accepted segment independently.
- No dependencies, persistence, synchronization, admin, cron, logging, or retry behavior was added.

## Concerns

- PHPUnit, WPCS, PHPStan, and build results remain unverified until Docker Desktop or a PHP/Composer host environment is available.
- `rtk` was unavailable, so direct `git`, Docker, PHP, and Composer commands were used.
