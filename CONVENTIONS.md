# Cinebot WP Conventions

## Scope

- WordPress single-site only for version 1.0; Multisite is out of scope.
- PHP 7.4+ and WordPress 6.0+.
- Native WordPress admin UI, custom tables, server-rendered shortcodes, vanilla JavaScript.
- No production framework and no JavaScript build tool.

## Structure

- Namespace PHP classes under `CinebotWp\`; one class per file.
- Keep WordPress hook adapters thin. Put SQL in repositories and business behavior in services.
- Repositories return domain DTOs. Purpose-built joined queries return named read models, never undocumented arrays.
- Use `$wpdb->prefix . 'cinebot_'` table names and `$wpdb->prepare()` for all dynamic values.
- Tables participating in atomic hierarchy changes use InnoDB.

## Ownership And Synchronization

- API IDs are globally unique.
- Synchronization never changes rows with `source=manual`.
- API rows absent from a completed synchronization are deactivated, not deleted, and reactivate when seen again.
- Public queries include only reconciled-active events whose API `stato` is `3`.
- Acquire synchronization ownership atomically; only the lock owner may release it.

## Security

- Encrypt API passwords at rest and never render or log plaintext credentials or Authorization headers.
- Require `manage_options` and a nonce for admin mutations.
- Sanitize input at boundaries and escape output at rendering time.
- Use a 60-second API timeout, transactional rollback, safe errors, and graceful no-data output.
- Version 1.0 does not perform automatic retries or implement a circuit breaker.

## Quality

- Write failing tests before implementation.
- Run WPCS, PHPStan, PHPUnit, and the distribution build before completion.
- Use Docker Compose so local and CI commands are reproducible.
- Use Conventional Commits such as `feat: synchronize schedules` or `fix: make sync lock atomic`.

## Documentation

- Approved product designs: `docs/superpowers/specs/`.
- Executable implementation plans: `docs/superpowers/plans/`.
- Lifecycle state, architecture summaries, audits, bugs, and verification records: `specs/`.

## Never Do

- Never expose credentials.
- Never overwrite manual records through synchronization.
- Never issue unprepared dynamic SQL.
- Never remove data during plugin deactivation.
- Never add Multisite support, React, or write-back API behavior without an approved design change.
