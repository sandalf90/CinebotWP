# Task 3 Implementation Report

## Status

Implemented the seven typed domain DTOs, the `ProgrammazioneCard` read model, focused unit coverage, and the `includes/`-only WPCS OO camelCase naming exceptions. Updated the matching approved-plan configuration snippet. No persistence, validation, WordPress hooks, or services were added.

## TDD Evidence

- Red: `docker compose run --rm php composer test:unit -- --filter ModelsTest`
- Green: `docker compose run --rm php composer test:unit -- --filter ModelsTest`
- Full gate: `docker compose run --rm php composer check`
- All three attempts stopped before Composer because Docker could not connect to `npipe:////./pipe/dockerDesktopLinuxEngine`; Windows reported that the file was not found.
- Local fallback `php --version` also failed because `php` is not installed or available on `PATH`.

## Static Evidence

- `git diff --check`: passed with no whitespace errors; Git emitted only existing line-ending conversion warnings.
- Signature inventory: seven `fromArray(array $data): self`, seven `toArray(): array`, and one `fromRow(array $row): self` declarations.
- Property inventory: all DTO and projection properties use PHP 7.4 scalar/array property types with schema-accurate nullability.
- Syntax review: no PHP 8-only runtime syntax; `mixed` appears only in PHPDoc.
- Mapping review: every DTO emits its complete snake_case database key set; the card maps all 13 Task 5 projection keys.
- Test review: seven data-provider round trips plus defaults/nullability and complete projection cases cover integer flags, `source=manual`, reconciliation defaults, leading-zero codes, tags, decimal strings, exact keys, and input immutability.
- Security/scope review: no dependencies, secrets, I/O, SQL, credentials, hooks, persistence, or unrelated refactors.

## Self-Review

- DTOs are final, single-purpose typed row boundaries.
- Nullable values use `isset` deliberately so explicit null remains null instead of becoming zero or an empty string.
- Money is cast only to string, never float.
- The PHPCS exclusion names only method/property OO naming messages and applies only below `includes/`; function, local-variable, hook, and option naming rules remain enabled.
- Hydration methods exceed the general 20-line heuristic because explicit field mapping is a task requirement; a generic mapper would reduce clarity and add unsupported abstraction.

## Concerns

- PHPUnit, PHPCS, PHPStan, and build execution remain unverified until the Docker Desktop Linux engine or local PHP is available.
