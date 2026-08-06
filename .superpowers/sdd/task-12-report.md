# Task 12 Implementation Report

## Status

Implemented the Programs list page (`TitoliListPage`), Programmazioni admin submenu, and Plugin composition. Test file covers columns, 50-row pagination, title/author search, type/source filters, escaped poster thumbnail, event count, nonce-protected single delete, bulk delete cascade, no edit/new actions, submenu registration, and plugin composition. No later-task pages (edit/new) implemented.

## Files

- Create: `includes/Admin/Pages/TitoliListPage.php`
- Create: `tests/Integration/TitoliListPageTest.php`
- Modify: `includes/Admin/AdminMenu.php` (add `TitoliListPage` collaborator + `cinebot-wp-programmazioni` submenu)
- Modify: `includes/Plugin.php` (compose `TitoliListPage` with repositories in `admin_menu()`)
- Modify: `tests/Integration/ApiAdminPageTest.php` (add `TitoliListPage` to `AdminMenu` construction, load admin screen helpers)

## TDD Evidence

- Test file written before implementation.
- Red attempt: `docker compose run --rm php composer test:integration -- --filter TitoliListPageTest` — blocked (Docker engine unavailable).
- Green attempt: same command — blocked.
- Full gate: `docker compose run --rm php composer check` — blocked.

## Static Checks

- `git diff --check`: passed (CRLF warnings only, consistent with all prior tasks).
- `TitoliListPage` extends `WP_List_Table` (loaded conditionally via `require_once`).
- Columns: `cb | titolo | autore | tipoevento_codice | locandina_url | eventi_count | source | updated_at`.
- Pagination: 50 per page, clamped positive via `get_pagenum()` and `set_pagination_args()`.
- Filters: `tipoevento_codice` (select from active types), `source` (allowlisted to api/manual), search text (`s` param) matching titolo/autore via `TitoloRepository::search()`.
- Request values: `wp_unslash` + `sanitize_text_field`; source filter allowlisted; type filter sanitized.
- Poster thumbnail: `esc_url` + `esc_attr`; dash placeholder when URL is empty.
- Event count: `EventoRepository::countByTitoloId()`.
- Single delete: `wp_nonce_url` with action `cinebot-wp-delete-titolo_{ID}`; `check_admin_referer` verifies.
- Bulk delete: `wp_nonce_field('bulk-titoli')`; `check_admin_referer('bulk-titoli')` verifies; IDs from `titolo[]` checkboxes.
- Cascade deletion order: prices (by settore) → sectors (by evento) → events (by titolo) → title, in a `START TRANSACTION` / `COMMIT` / `ROLLBACK` block.
- Admin notice on success/failure via redirect `deleted` query arg.
- Submenu slug `cinebot-wp-programmazioni` registered in `AdminMenu::add_menu()`.
- `Plugin::admin_menu()` composes `TitoliListPage` with all five repositories.
- No edit/new actions rendered (only delete row action and bulk delete).
- All output escaped: `esc_html`, `esc_attr`, `esc_url`, `esc_html_e`, `selected()`, `submit_button()`.
- `manage_options` capability + nonce required for all mutations.
- PHP 7.4 compatible, WPCS-style tabs/snake_case/docblocks.

## Concerns

- PHPUnit, WPCS, PHPStan, build not executed (Docker unavailable by user decision).
- `WP_List_Table` and admin screen helpers loaded via `require_once ABSPATH . 'wp-admin/includes/admin.php'` in test `set_up_before_class()`; production page file loads only `class-wp-list-table.php`.
- PHPDoc `@param Titolo $item` annotations added to `column_cb`, `column_titolo`, and `column_default` to help PHPStan infer the item type; the parent `WP_List_Table` methods have untyped `$item`.
