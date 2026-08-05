# Cinebot WP Agent Instructions

Follow `CLAUDE.md` and `CONVENTIONS.md`. Treat the approved design and implementation plan under `docs/superpowers/` as the source of product and execution requirements. Lifecycle state and audits live under `specs/`.

Never expose API credentials, overwrite `source=manual` records during synchronization, execute unprepared dynamic SQL, delete plugin data on deactivation, or introduce Multisite/React without a new approved specification.
