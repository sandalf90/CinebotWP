# Task 14 Brief — Venue and event-type CRUD pages

Implement only Task 14. Read `CONVENTIONS.md`, LocaleRepository, TipologiaRepository, and AdminMenu. Do not implement dashboard/log/frontend.

## Files

- Create: `includes/Admin/Pages/LocaliListPage.php`, `LocaleEditPage.php`
- Create: `includes/Admin/Pages/TipologieListPage.php`, `TipologiaEditPage.php`
- Create: `tests/Integration/ReferenceAdminPagesTest.php`
- Modify: `includes/Admin/AdminMenu.php` (add Locali + Tipologie submenus)
- Modify: `includes/Plugin.php` (compose all four pages)

## Requirements

- LocaliListPage: WP_List_Table with Nome|Codice|Comune|Provincia|Mappa|Eventi(count). Filters: comune, provincia. "Nuovo locale" button. Venue create = manual source.
- LocaleEditPage: nome, codice, indirizzo, cap, comune, provincia, mappa. API venue edit without changing source. Prevent deleting referenced venues (countEvents > 0).
- TipologieListPage: WP_List_Table with Codice|Descrizione|Predefinito(badge)|Attivo(toggle)|Eventi(count). Filters: predefinite/personalizzate, attivo/non attivo. "Nuova tipologia" button. Toggle action: `cinebot_toggle_tipologia` with ID, desired state, nonce.
- TipologiaEditPage: codice (read-only if predefined), descrizione, attivo. Custom code uniqueness. Predefined delete rejection.
- All: nonce + capability, sanitation, escaping, admin notices. Submenus registered in this task only.
- AdminMenu: add Locali submenu (`cinebot-wp-locali`) and Tipologie submenu (`cinebot-wp-tipologie`).
- Plugin: compose all four pages with LocaleRepository, TipologiaRepository, TitoloRepository (for event counts).

## TDD

Tests for: venue filtering, venue creation (manual source), API venue edit (source unchanged), referenced venue delete prevention, event-type filters, immutable predefined code, custom code uniqueness, predefined delete rejection, active toggle. Attempt Docker, static checks. Report and commit `feat: manage cinebot venues and event types`.
