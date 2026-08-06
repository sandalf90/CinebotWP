# Task 10 Report - WP-Cron Scheduler

## Status

NEEDS_RUNTIME: Task 10 implementation and focused integration tests are complete, but Docker Desktop is unavailable and neither PHP nor Composer is installed on PATH for local verification.

## Delivered

- Added `CronScheduler` with `cinebot_weekly` using `WEEK_IN_SECONDS`, registration hooks, idempotent scheduling, settings-change replacement, and full cleanup.
- Scheduled sync safely dispatches `SyncService::sync()` and suppresses thrown errors from WP-Cron output.
- Extended `Plugin` lifecycle composition: activation installs schema, registers cron behavior, then schedules enabled synchronization; deactivation clears only the cron event.
- Added integration coverage for all supported recurrences, unknown-frequency fallback, disabled scheduling, schedule replacement, update-option behavior, safe cron dispatch, and lifecycle scheduling/cleanup.

## Verification

- Focused Docker integration suite: BLOCKED. Docker Desktop engine pipe is unavailable.
- Docker WPCS lint: BLOCKED. Docker Desktop engine pipe is unavailable.
- Docker PHPStan analysis: BLOCKED. Docker Desktop engine pipe is unavailable.
- Local PHP syntax checks: BLOCKED. PHP is not installed on PATH.
- `git diff --check`: completed without whitespace errors; Git emitted only existing LF-to-CRLF conversion warnings.

## Follow-up

Run `docker compose run --rm php composer test:integration -- --filter CronSchedulerTest` and `docker compose run --rm php composer check` once Docker Desktop is available.

## Post-Review Fixes

### Resolved - Test isolation leak in CronSchedulerTest (review: Low)

`CronSchedulerTest::set_up()` stripped the bootstrapped plugin hooks (`cinebot_wp_sync_event`, `update_option_cinebot_wp_settings`, `cron_schedules`) without restoring them, leaving later integration tests order-dependent. Added `tests/Integration/CronSchedulerTest.php::tear_down()` that re-clears the cron event and settings, then restores the bootstrapped hooks by resetting the `Plugin` singleton's `$booted` guard via reflection and re-invoking `Plugin::instance()->boot()`. No production code changed; the reflection mirrors the existing `PluginBootstrapTest` pattern.
