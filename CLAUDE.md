# Cinebot WP Agent Guide

Cinebot WP is a WordPress 6 plugin that imports Cinebot schedules into custom relational tables, provides native WordPress administration, and renders public schedules through shortcodes.

Read `CONVENTIONS.md` before changing code. The approved design is `docs/superpowers/specs/2026-08-02-cinebot-wp-plugin-design.md`; the implementation plan is `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md`.

## Commands

- Setup image: `docker compose build`
- Setup dependencies: `docker compose run --rm php composer install`
- Test: `docker compose run --rm php composer test`
- Build: `docker compose run --rm php composer build`
- Lint: `docker compose run --rm php composer lint`
- Static analysis: `docker compose run --rm php composer analyse`
- Full gate: `docker compose run --rm php composer check`

## Workflow

- Git mode: `solo-git`.
- Use Conventional Commits.
- Follow TDD and keep each planned task independently executable.
- Do not start implementation while `specs/PLAN-AUDIT.md` says `NOT READY`.
