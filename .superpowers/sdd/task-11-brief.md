# Task 11 Brief — Admin menu, API settings page, sync controls

Implement only Task 11 from the approved plan. Read `CONVENTIONS.md`, SettingsService, SyncService, CronScheduler, and Plugin lifecycle. Do not implement Programs list, editor, venues, types, dashboard, or log pages.

## Files

- Create `includes/Admin/AdminMenu.php`
- Create `includes/Admin/Pages/DashboardPage.php` (minimal concrete shell: status + link to API settings only; Task 15 enhances it)
- Create `includes/Admin/Pages/ApiPage.php`
- Create `assets/js/cinebot-admin.js`
- Create `assets/css/cinebot-admin.css`
- Create `tests/Integration/ApiAdminPageTest.php`
- Modify `includes/Plugin.php` to compose AdminMenu in boot()

## Interfaces

- `AdminMenu::register(): void`
- `DashboardPage::render(): void` (minimal: sync status + link to API)
- `ApiPage::render(): void`
- `ApiPage::save(): void`
- `ApiPage::testConnection(): void` (AJAX)
- `ApiPage::syncNow(): void` (AJAX)
- AJAX actions: `wp_ajax_cinebot_wp_test_connection`, `wp_ajax_cinebot_wp_sync_now`

## Requirements

- Register top-level `Cinebot` menu with Dashboard + API submenu only. Later tasks add their submenus when their pages exist.
- Capability: `apply_filters('cinebot_wp_capability', 'manage_options')`.
- API page: settings form with username, password (hidden if already set, empty preserves existing), frontend (number optional), frequency select, enable checkbox. Nonce-protected save through SettingsService, then CronScheduler::reschedule().
- Test connection AJAX: nonce + capability check, calls ApiClient with current settings (no persistence), returns JSON `{success, http_code, titoli_count}` or safe error.
- Sync now AJAX: nonce + capability check, calls SyncService::sync(), returns JSON `{success, stats, message}`. Rate limited by SyncLock.
- Enqueue admin assets only on Cinebot screens. JS uses fetch() with FormData, shows ARIA live status region.
- DashboardPage is a concrete minimal page, not a placeholder callback. Task 15 enhances it.
- All output escaped: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`.
- No credentials in HTML source, no Authorization headers in responses.

## TDD

Write tests first for: admin access granted/denied, nonce rejection, settings form field names, hidden existing password, sanitized saves, connection test title count, manual sync stats, JSON error responses without credentials, asset enqueuing on correct screen only.

Attempt Docker commands, then static checks. Report task-11-report; commit `feat: add cinebot api administration`; no coordinator/review artifacts staged.
