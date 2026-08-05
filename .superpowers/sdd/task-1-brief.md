# Task 1 Brief — Bootstrap plugin and test harness

Read this first. It is the complete coordination brief for Task 1. The approved detailed task text is in `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md`, Task 1 only (currently lines 127–487); do not read or implement later tasks.

## Context

Cinebot WP is a greenfield WordPress 6 plugin. This task creates the executable foundation only. The user explicitly chose to work in-place. No Git repository exists; initialize directly on branch `feat/cinebot-wp`, not `main`/`master`. The `rtk` wrapper and Bash are unavailable on the Windows host; native Git/PowerShell commands are allowed. Bash exists inside the planned PHP Docker image.

Read `CLAUDE.md`, `AGENTS.md`, and `CONVENTIONS.md` before editing. Do not modify product scope or implement Task 2 schema behavior.

## Files

Create exactly the Task 1 files:

- `composer.json`, `cinebot-wp.php`, `includes/autoload.php`, `includes/Plugin.php`
- `compose.yaml`, `docker/php/Dockerfile`, `docker/prepare-tests.sh`, `docker/run-tests.sh`
- `phpunit.xml.dist`, `phpcs.xml.dist`, `phpstan.neon.dist`
- `tests/bootstrap.php`, `tests/wp-tests-config.php`, `tests/Integration/PluginBootstrapTest.php`
- `tools/build.php`, `.gitignore`

Preserve and include the existing approved docs/conventions/specs in the initial repository commit. Do not create application classes beyond the minimal `Plugin` singleton.

## Required behavior

- PHP 7.4+, WordPress 6.0+, text domain `cinebot-wp`, namespace `CinebotWp\`.
- Runtime loads committed `includes/autoload.php`; it must not require `vendor/autoload.php`.
- Composer is development-only and provides PHPUnit 9, WPCS, PHPStan WordPress, and scripts `prepare-tests`, `test`, `test:unit`, `test:integration`, `lint`, `lint:fix`, `analyse`, `build`, `check` exactly as the plan specifies.
- Docker Compose provisions MySQL 8 and parameterized PHP (`PHP_VERSION`, default 7.4), with WordPress tag `WP_VERSION`, default `6.0.12`.
- The test preparer clones the exact WordPress develop tag into the named volume and validates its PHPUnit bootstrap.
- `tests/bootstrap.php`, `tests/wp-tests-config.php`, PHPUnit, PHPCS, and PHPStan configs use the exact values from Task 1.
- `Plugin::instance(): Plugin` is a singleton and `Plugin::boot(): void` is idempotent.
- Define `CINEBOT_WP_VERSION=1.0.0`, `CINEBOT_WP_FILE`, `CINEBOT_WP_PATH`, and `CINEBOT_WP_URL`.
- `tools/build.php` creates `dist/cinebot-wp.zip` with a `cinebot-wp/` top-level folder, includes runtime files, excludes development files and `vendor/`.
- Do not add activation/deactivation callbacks yet; Task 2 owns them.

## TDD and verification

1. Initialize `git init -b feat/cinebot-wp`.
2. Add configuration and the failing `PluginBootstrapTest` before the minimal Plugin implementation.
3. Attempt the focused red test and record the actual failure. If Docker is unavailable, report that as an environment concern but still inspect/validate all static files possible.
4. Implement the minimum bootstrap/autoloader/build behavior.
5. Run the focused test, WPCS, PHPStan, and build when environment permits.
6. Inspect `git status`, `git diff`, and `git log --oneline -10`; stage only project files and no secrets.
7. Commit with `chore: bootstrap cinebot wordpress plugin`.

## Report

Write a detailed report to `.superpowers/sdd/task-1-report.md` containing:

- Status: `DONE`, `DONE_WITH_CONCERNS`, `NEEDS_CONTEXT`, or `BLOCKED`
- Files created/modified
- Red test command and observed failure
- Green/quality/build commands and exact results
- Commit hash
- Self-review and concerns

Return only the status, commit hash, one-line test summary, and concerns.
