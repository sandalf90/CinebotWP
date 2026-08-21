# Task 13 Brief — Nested title/event editor

Implement only Task 13. Read `CONVENTIONS.md`, all DTOs, all repositories, and Task 12's TitoliListPage. Do not implement venue/type/dashboard/log pages or frontend.

## Files

- Create: `includes/Admin/Pages/TitoloEditPage.php`
- Create: `tests/Integration/TitoloEditPageTest.php`
- Modify: `assets/js/cinebot-admin.js` (nested editor controls)
- Modify: `assets/css/cinebot-admin.css` (fieldset styles)
- Modify: `includes/Admin/Pages/TitoliListPage.php` (add "Nuovo titolo" + row "Modifica" links)
- Modify: `includes/Admin/AdminMenu.php` (route edit page)
- Modify: `includes/Plugin.php` (compose TitoloEditPage)

## Requirements

- `render(?int $id = null): void` — new form if null, edit form if ID provided.
- Form fields: titolo (required), autore, esecutore, durata, tipoevento_codice (select from active types), descrizione (wysiwyg/textarea), locandina_url, cinetel, tmdb, trailer, cast, tag (comma-separated), source (read-only display).
- Eventi: list of fieldsets, each with inizio (datetime), locale_id (select), organizzatore_id, organizzatore_cf, stato, otp, controlloaccessi, mappa.
  - Settori per event: list of inline rows with nome + prezzi.
  - Prezzi per sector: nome, tipo (I/R), importo, prevendita, stato.
- `<template>` elements for cloning new event/sector/price rows. JS replaces `__INDEX__` with monotonically increasing keys, supports add/remove without reusing keys.
- `save(): void` — nonce + capability. Full validation first, then one transaction: title -> events -> sectors -> prices. Existing IDs accepted only after `belongsTo*` ownership proof. Removed children deleted via `deleteBy*` in price -> sector -> event order. New rows get `source=manual`, null remote IDs. Editing imported rows keeps `source=api` and remote IDs.
- `wp_kses_post` for descrizione, `esc_url_raw` for URLs, tags become unique JSON array.
- Add "Nuovo titolo" button and "Modifica" row action to TitoliListPage (routing only after this editor exists).
- AdminMenu routes `cinebot-wp-programmazione-edit` to this page.

## TDD

Write tests first for: new form with nonce and all fields, existing form with loaded hierarchy, new rows get source=manual/null remote IDs, editing imported keeps source=api/remote IDs, required field validation, atomic save (invalid child leaves previous unchanged), removed children deleted under correct parent only, descrizione sanitized, URL escaping, tags JSON. Attempt Docker, static checks. Report and commit `feat: edit nested cinebot programs`.
