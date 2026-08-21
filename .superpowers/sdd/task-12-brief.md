# Task 12 Brief — Programs list page

Implement only Task 12 from the approved plan. Read `CONVENTIONS.md`, TitoloRepository, and AdminMenu. Do not add edit/new actions (Task 13 owns them). Do not implement other admin pages.

## Files

- Create: `includes/Admin/Pages/TitoliListPage.php`
- Create: `tests/Integration/TitoliListPageTest.php`
- Modify: `includes/Admin/AdminMenu.php` (add Programmazioni submenu)
- Modify: `includes/Plugin.php` (compose TitoliListPage)

## Requirements

- Extends `WP_List_Table` (loaded only when needed).
- Columns: Titolo | Autore | Tipo evento | Locandina (thumb) | Eventi (count) | Source | Ultima modifica
- Filters: tipoevento_codice (select from active types), source (api/manual), search text (titolo/autore)
- Bulk: delete
- Per-row: delete (nonce-protected). No edit/new actions yet.
- Pagination 50/page, clamped positive.
- Request values `wp_unslash` + sanitized; filter values allowlisted.
- Uses `TitoloRepository::search/count` for list and count.
- Deletion order: prices -> sectors -> events -> title, via repository delete-by-parent methods, in a transaction. Returns admin notice on success/failure.
- Submenu slug `cinebot-wp-programmazioni`, registered only in this task (AdminMenu modification).

## TDD

Write tests first for: columns, 50-row pagination, title/author search, type/source filters, escaped poster thumbnail, event count, nonce-protected delete, bulk deletion, no edit/new actions. Attempt Docker, static checks. Report and commit `feat: manage cinebot program list`.
