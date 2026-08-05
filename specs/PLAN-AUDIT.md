# Plan Audit — Cinebot WP
**Date:** 2026-08-03 · **Verdict:** READY

## Principles Alignment

| Check | Status | Note |
|---|---|---|
| Vertical slices | ✅ | Five delivery gates terminate in independently usable import, management, reference/monitoring, public, and release capabilities. Intermediate menu/page wiring is added only with concrete classes. |
| Scope bounded | ✅ | `in_scope`/`out_of_scope` are explicit in the plan and `specs/product/SCOPE_LATEST.yaml`; Multisite and write-back are excluded. |
| Success criteria | ✅ | Focused red/green tests, vertical gate outcomes, end-to-end regression, build verification, and a manual acceptance checklist are present. |
| Hard gates | ✅ | Docker test provisioning, InnoDB, lifecycle ownership, reconciliation, atomic lock, state-3 visibility, quality gates, and distribution artifact are explicit. |
| Domain language | ✅ | Titolo, Evento, Settore, Prezzo, Locale, Tipologia, Programmazione, ManualOwnership, and Reconciliation are defined in the glossary and used consistently. |

## Conventions Completeness

| Check | Status | Note |
|---|---|---|
| Project `CLAUDE.md` | ✅ | Stack, commands, workflow, TDD, and audit hard gate documented and confirmed by the user. |
| `AGENTS.md` | ✅ | OpenCode instructions and project hard stops documented. |
| `CONVENTIONS.md` | ✅ | Structure, SQL, ownership, security, quality, documentation, and never-do rules are explicit. |
| `specs/` layout | ✅ | Product, architecture, verification, epic archive, bug registry, state, release, and audit artifacts exist. |
| Commit conventions | ✅ | Conventional Commits confirmed by the user and used throughout the plan. |
| Git workflow mode | ✅ | `solo-git` is canonical in `specs/state.yaml`. |

## Pre-flight Answers

| Item | Value |
|---|---|
| Setup image | `docker compose build` |
| Setup dependencies | `docker compose run --rm php composer install` |
| Test | `docker compose run --rm php composer test` |
| Build | `docker compose run --rm php composer build` |
| Lint | `docker compose run --rm php composer lint` |
| Typecheck | `docker compose run --rm php composer analyse` |
| Full gate | `docker compose run --rm php composer check` |
| CI platform | GitHub Actions using the same Docker commands |
| Git mode | `solo-git` |
| Primary stack | PHP 7.4+, WordPress 6.0+, MySQL/MariaDB InnoDB |
| Project state | Greenfield |
| Multisite | Out of scope for 1.0 |
| Remote IDs | Globally unique |
| Missing API rows | Deactivate without deletion; reactivate on return |
| Public event state | `evento.stato = 3` and active reconciliation hierarchy only |

## Review Resolution

| # | Original finding | Category | Resolution |
|---|---|---|---|
| 1 | WordPress test environment not executable | must-fix | Docker PHP/MySQL, exact WordPress tag provisioning, complete PHPUnit bootstrap/config, and executable commands added to Task 1. |
| 2 | Production autoload/distribution failure | must-fix | Runtime uses committed `includes/autoload.php`; deterministic ZIP build excludes `vendor/` and verifies its absence. |
| 3 | Repository contracts incomplete | must-fix | Parent lookups, ownership checks, counts, delete-by-parent, reconciliation, and cascade methods are defined before consumers. |
| 4 | Lifecycle contracts conflict | must-fix | Only static `Plugin::activate()`/`deactivate()` are registered; activation delegates to `SchemaInstaller`. |
| 5 | Upsert-only synchronization | must-fix | `frontend_id`, `sync_active`, `last_seen_sync`, scoped reconciliation, cascade deactivation, and reactivation tests added. |
| 6 | Transactions not guaranteed | must-fix | Every table is InnoDB; support is checked before activation and asserted in schema tests. |
| 7 | Non-atomic transient lock | must-fix | Dedicated option-backed `SyncLock` uses atomic `add_option`, owner token, expiry recovery, and contention tests. |
| 8 | Public visibility incomplete | should-fix | Public repository and shortcode tests require active hierarchy plus `evento.stato=3`. |
| 9 | Admin sequencing not runnable | should-fix | Dashboard/API shell is concrete; each submenu is registered only in the task that creates its page; edit links wait for the editor. |
| 10 | DTO/projection contradiction | should-fix | Joined public query returns named `ProgrammazioneCard`; raw rows exist only at `fromRow()`. |
| 11 | CI matrix not provisioned | should-fix | Four exact PHP/WordPress pairs run Docker `composer check`; release entry uploads the verified ZIP. |
| 12 | Scope and conventions missing | should-fix | Scope boundaries, single-site decision, `CLAUDE.md`, `AGENTS.md`, `CONVENTIONS.md`, workflow mode, WPCS, and PHPStan are present. |

## Residual Risks

- WordPress and Docker dependencies are planned but cannot be executed until Task 1 creates implementation files; the plan includes the exact first-run verification.
- WP-Cron remains traffic-driven by design and is documented as such.
- Editing an API-owned record remains temporary because a later sync refreshes API fields; `source` is read-only, matching the approved design.

## Open Gaps

None blocking implementation. Any new Multisite, write-back, retry, React/Gutenberg, calendar, or purchase-link requirement must return to design/specification first.

## Verdict

**READY** — all previous must-fix and should-fix findings are addressed in the plan or project conventions. Proceed with `survey-context`, then execute the approved plan using the required task-by-task implementation skill.
