# Test Strategy

- Docker Compose provisions PHP and MySQL plus the selected WordPress core test suite.
- PHPUnit integration tests cover schema, repositories, synchronization, cron, admin endpoints, shortcodes, and lifecycle.
- Focused unit tests cover settings encryption, API response handling, URL construction, and lock behavior.
- WPCS and PHPStan are mandatory gates.
- Required commands are documented in `CLAUDE.md` and `CONVENTIONS.md`.
