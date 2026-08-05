# Task 2 Implementation Report

## Status

Implemented the approved seven-table schema, 62 event-type defaults, activation/deactivation lifecycle, single-site uninstall cleanup, integration contract, and PHPStan path update. Runtime verification is blocked because Docker Desktop and a host PHP runtime are unavailable.

## Files

- `includes/Database/SchemaInstaller.php`
- `includes/Database/EventTypeDefaults.php`
- `uninstall.php`
- `tests/Integration/SchemaInstallerTest.php`
- `cinebot-wp.php`
- `includes/Plugin.php`
- `phpstan.neon.dist`
- `.superpowers/sdd/task-2-report.md`

No coordinator state or review artifact was modified or selected for commit.

## TDD Commands

### Red

Command:

```text
docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
```

Exact result before implementation:

```text
unable to get image 'mysql:8.0': failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: Impossibile trovare il file specificato.
```

The command could not reach PHPUnit, so an executable red result was unavailable.

### Green

Focused command and full gate:

```text
docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
docker compose run --rm php composer check
```

Exact result from each command:

```text
unable to get image 'mysql:8.0': failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: Impossibile trovare il file specificato.
```

Host syntax command:

```text
php -v
```

Exact result:

```text
php : Termine 'php' non riconosciuto come nome di cmdlet, funzione, programma eseguibile o file script.
```

The required `rtk` wrapper was also unavailable with `CommandNotFoundException`, so subsequent repository commands used their underlying executables directly.

## Static Checks

- `docker compose config --quiet`: passed with exit code 0.
- `git diff --check`: passed with no whitespace errors; Git emitted only existing LF-to-CRLF working-copy warnings.
- Defaults scan: exactly 62 code/description rows, manually compared with Appendix A; all 62 codes are unique and leading-zero codes are strings.
- Schema scan: exactly seven `CREATE TABLE` statements, each sharing explicit `ENGINE=InnoDB` and WordPress charset/collation options.
- Field scan: all approved fields are present; all five remote identifier columns and `titoli.frontend_id` are nullable.
- Index scan: title/event/venue remote IDs are unique; sector and price remote IDs use scoped composite unique indexes; `eventi.inizio`, source/type, sync, parent, municipality, code, and sync-log indexes are present.
- Reconciliation scan: titles, events, sectors, and prices include `sync_active`, nullable `last_seen_sync`, a direct active index, and a parent/scope composite reconciliation index.
- Lifecycle scan: the entry point registers only `Plugin::activate` and `Plugin::deactivate`; deactivation only clears `cinebot_wp_sync_event`.
- Uninstall scan: direct-access guard, seven fixed table drops, four option deletions, cron cleanup, and prepared normal transient value/timeout deletion are present; no Multisite loop exists.
- SQL safety scan: dynamic values use `wpdb::prepare()` or formatted `wpdb::insert()`; interpolated identifiers are composed only from the trusted WordPress prefix and fixed suffixes.
- Configuration scan: `uninstall.php` is included by both `phpcs.xml.dist` and `phpstan.neon.dist`; PHPStan remains configured for PHP 7.4.
- Security scan: no credentials, authorization headers, or secret-like tokens were introduced.
- PHP shape review: namespaces, braces, signatures, array syntax, anonymous test double, and lifecycle callbacks use PHP 7.4-compatible constructs. Automated `php -l` was unavailable.

## Self-review

- Scope: pass; no Task 3 models, repositories, services, admin UI, or synchronization behavior was added.
- Correctness: pass by static review; schema/default/lifecycle behavior is covered by the new integration test contract.
- Security: pass; no untrusted identifier interpolation or unprepared dynamic SQL values.
- Performance: pass; schema work is activation-only, defaults seed only into an empty table, and reconciliation indexes match approved access scopes.
- Clarity and conventions: pass; PSR-4 classes, public docblocks, WPCS-targeted justifications, PHP 7.4 syntax, and fixed single-site lifecycle boundaries are preserved.
- TDD: test was written before production code and the required red command was attempted; Docker failed before test execution.
- Rationalization check: no audit item was silently skipped. Runtime checks are explicitly marked blocked rather than inferred passing.

## Commit

Message: `feat: install cinebot database schema`

Hash: the commit containing this report is referenced as `HEAD`; its immutable hash is returned in the coordinator handoff because a commit cannot contain its own hash.

## Concerns

- PHPUnit, WPCS, PHPStan, build, and PHP syntax execution remain unverified until Docker Desktop or a host PHP toolchain is available.

## Fix Review

### Findings Addressed

- Default seeding now uses explicit `START TRANSACTION`, `COMMIT`, and `ROLLBACK` statements. Any insert/commit failure or throwable rolls back before a translated safe `RuntimeException`, leaving an empty table that can be retried.
- A secondary real `wpdb` connection fails the fifth insert after four successful inserts; the focused test asserts rollback leaves zero rows, then retries through the same installer and asserts all 62 rows plus the exact transaction sequence.
- The complete ordered catalog is protected by count, unique-code count, and SHA-256 `26e7c32546f10b24f2260373b2d65c06dbf94d44d84c66963c69ce7d0d4ef380`. The canonical input is UTF-8 `codice<TAB>descrizione` rows in array order joined by LF without a trailing LF.
- `UninstallTest` creates the seven plugin tables and unrelated table, approved/unrelated options, approved/unrelated cron hooks, and approved/unrelated normal transient value/timeout options. It executes guarded uninstall, asserts the exact removal boundary, cleans fixtures, and restores schema in `finally`.
- Task 2 plan files/tests, behavior, focused commands, and staging snippet now document the recoverable seed and uninstall contracts.

### TDD And Dynamic Gates

Red commands were attempted after adding the recovery and uninstall contracts and before changing production seeding:

```text
docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
docker compose run --rm php composer test:integration -- --filter UninstallTest
```

Green/final commands were attempted after implementation:

```text
docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
docker compose run --rm php composer test:integration -- --filter UninstallTest
docker compose run --rm php composer check
```

Every Docker command stopped before PHPUnit/Composer with the same exact result:

```text
unable to get image 'mysql:8.0': failed to connect to the docker API at npipe:////./pipe/dockerDesktopLinuxEngine; check if the path is correct and if the daemon is running: open //./pipe/dockerDesktopLinuxEngine: Impossibile trovare il file specificato.
```

Host syntax probe `php -v` also could not run:

```text
php : Termine 'php' non riconosciuto come nome di cmdlet, funzione, programma eseguibile o file script.
```

Dynamic PHPUnit, WPCS, PHPStan, build, and PHP syntax gates were therefore not run; they are not reported as passing.

### Static Verification

- Canonical catalog regeneration: `sha256=26e7c32546f10b24f2260373b2d65c06dbf94d44d84c66963c69ce7d0d4ef380`, `rows=62`, `unique_codes=62`.
- Transaction scan: production contains explicit start, commit, rollback, and safe translated failure; the test asserts rollback/retry row counts and command order.
- Uninstall scan: the contract covers all seven approved tables, an unrelated table, four approved and one unrelated option, approved/unrelated cron hooks, and both transient value/timeout rows.
- Isolation scan: uninstall fixtures are cleaned and schema is restored from `finally`.
- Plan scan: canonical fingerprint generation, atomic transaction behavior, uninstall contract, both focused commands, and the added test path are documented.
- `docker compose config --quiet`: exit code 0.
- `git diff --check`: no whitespace errors; only Git LF-to-CRLF working-copy warnings.
- Secret scan: no credential, authorization-header, or token patterns found.

### Remaining Concern

- Runtime behavior and tool conformance require CI or another environment with Docker/PHP; local dynamic execution remains intentionally unavailable.
