# Task 2 Brief — Seven-table schema and lifecycle

Read this first. Implement only Task 2 from `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md` (Task 2, currently lines 488–566). Read `CONVENTIONS.md`, the approved design section “2. Schema del database”, and Appendix A for the exact 62 event-type rows. Do not implement models, repositories, services, admin pages, or synchronization logic.

## Context and existing interfaces

- Task 1 is complete through commit `1aaf9f5`; `Plugin::instance()`/`boot()` and the committed autoloader exist.
- The user explicitly chose no local Docker. Attempt the focused command in TDD order, but record Docker unavailability and perform all possible static checks.
- Coordinator files `.superpowers/sdd/progress.md`, `specs/state.yaml`, `specs/execution-status.yaml`, and review artifacts are uncommitted orchestration state. Do not stage or modify them.
- Add `uninstall.php` to PHPStan paths in this task because the file now exists, as required by the corrected plan.

## Files

- Create `includes/Database/SchemaInstaller.php`
- Create `includes/Database/EventTypeDefaults.php`
- Create `uninstall.php`
- Create `tests/Integration/SchemaInstallerTest.php`
- Modify `cinebot-wp.php`, `includes/Plugin.php`, and `phpstan.neon.dist`
- Update Task 2 literal plan snippets only if implementation requires a correction; do not alter scope.

## Interfaces

- `SchemaInstaller::__construct(\wpdb $db)`
- `SchemaInstaller::install(): void`
- `SchemaInstaller::supportsTransactions(): bool`
- `EventTypeDefaults::all(): array` with exactly 62 `{codice, descrizione}` rows, preserving leading zero codes
- Static `Plugin::activate(): void` and `Plugin::deactivate(): void`

## Required behavior

- Create exactly seven `$wpdb->prefix . 'cinebot_'` tables: `titoli`, `eventi`, `settori`, `prezzi`, `locali`, `tipologie_eventi`, `sync_log`.
- Use `dbDelta()`, WordPress charset/collation, and explicit `ENGINE=InnoDB`.
- Before any table creation, `supportsTransactions()` checks `SHOW ENGINES` for InnoDB support `YES` or `DEFAULT`; unsupported engines throw a translated actionable `RuntimeException` and create no plugin table.
- Implement every approved field/index. In particular: nullable remote IDs; title `frontend_id`; hierarchy `sync_active` and `last_seen_sync`; unique remote IDs; scoped composite sector/price uniqueness; event date index; reconciliation indexes.
- Store DB version `1.0.0` in `cinebot_wp_db_version` with autoload disabled.
- Seed all 62 defaults only when the event-type table is empty; later activation must preserve rows and `attivo` choices.
- `cinebot-wp.php` registers only `[Plugin::class, 'activate']` and `[Plugin::class, 'deactivate']`.
- `Plugin::activate()` constructs the installer from global `$wpdb` and installs. `Plugin::deactivate()` only clears `cinebot_wp_sync_event`; it never deletes data.
- `uninstall.php` exits unless `WP_UNINSTALL_PLUGIN` is defined, drops only the seven plugin tables, deletes `cinebot_wp_settings`, `cinebot_wp_db_version`, `cinebot_wp_encryption_salt`, `cinebot_wp_sync_lock`, clears the cron hook, and deletes matching normal transient value/timeout options. Single-site only; no Multisite loop.
- Every dynamic SQL value is prepared. Static identifiers are built from trusted `$wpdb->prefix` plus fixed suffixes.
- WPCS style and PHP 7.4 compatibility are mandatory. Use docblocks for public methods and narrow PSR-4 filename exclusions already configured.

## TDD and verification

1. Write `SchemaInstallerTest` first for seven tables, version, 62 defaults, nullable remote IDs, unique/composite indexes, event date index, reconciliation fields/indexes, InnoDB engine, idempotence, preserved disabled default, lifecycle registration, deactivation data retention, and unsupported-InnoDB failure before creation.
2. Attempt red: `docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest`.
3. Implement minimum schema/lifecycle/uninstall behavior.
4. Attempt focused test and `docker compose run --rm php composer check`; record environment result.
5. Perform static validation of PHP syntax shape, 62 unique codes, SQL table/index definitions, prepared dynamic values, XML/NEON paths, and whitespace.
6. Inspect `git status`, `git diff`, `git log --oneline -10`; stage only Task 2 implementation/test/plan files plus the Task 2 report. Do not stage coordinator/reviewer artifacts.
7. Commit `feat: install cinebot database schema`.

## Report

Write `.superpowers/sdd/task-2-report.md` with status, files, red/green commands, exact results, static checks, commit hash, self-review, and concerns. Return only status, commit, one-line verification, and concerns.
