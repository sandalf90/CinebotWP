# Task 12 Review — Programs list page

Fresh-context review of commit `d769c2b` (parent `84cf28b`). Scope: `includes/Admin/Pages/TitoliListPage.php`, `includes/Admin/AdminMenu.php`, `includes/Plugin.php`, `tests/Integration/TitoliListPageTest.php`, and the `tests/Integration/ApiAdminPageTest.php` fixture update. No Docker gates (per task instructions).

## Spec Compliance

| Brief requirement | Status | Evidence |
|---|---|---|
| Extends `WP_List_Table`, loaded only when needed | PASS | `TitoliListPage.php:13-15` guards `require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php'` behind `class_exists` |
| Columns: Titolo \| Autore \| Tipo evento \| Locandina \| Eventi \| Source \| Ultima modifica | PASS | `get_columns()` returns `cb \| titolo \| autore \| tipoevento_codice \| locandina_url \| eventi_count \| source \| updated_at` (`cb` is the standard bulk-select column, not a brief violation) |
| Filter: tipoevento_codice (select from active types) | PASS | `render_filter_controls()` calls `$this->types->findAll( true )` (activeOnly) and renders `<select>` with `selected()` |
| Filter: source (api/manual) | PASS | Source dropdown hard-coded to `api`/`manual`; `current_filters()` allowlists to `['api','manual']` via strict `in_array` |
| Filter: search text (titolo/autore) | PASS | `s` param sanitized; `TitoloRepository::admin_predicate()` builds `LOWER(titolo) LIKE %s OR LOWER(autore) LIKE %s` |
| Bulk delete | PASS | `get_bulk_actions()` returns `['delete']`; bulk form wraps `wp_nonce_field('bulk-titoli')` |
| Per-row delete (nonce-protected) | PASS | `column_titolo()` builds `wp_nonce_url(..., 'cinebot-wp-delete-titolo_' . $id)`; `handle_actions()` calls `check_admin_referer('cinebot-wp-delete-titolo_' . $id)` |
| No edit/new actions | PASS | No edit/new row actions rendered; `test_no_edit_or_new_actions_in_render` asserts absence of `action=edit`, `action=new`, and `Add new` |
| Pagination 50/page, clamped positive | PASS | `$per_page = 50`; `$page = $this->get_pagenum()` (clamps to ≥1); `total_pages` uses `max(1, ...)` |
| Request values `wp_unslash` + sanitized; allowlisted filters | PASS | `current_filters()` and `render_filter_controls()` consistently use `wp_unslash` + `sanitize_text_field`; source strict-allowlisted |
| Uses `TitoloRepository::search/count` | PASS | `prepare_items()` calls both with shared `$filters` |
| Cascade order prices → sectors → events → title, in a transaction | PASS | `delete_titles()` runs `START TRANSACTION` → for each title: `prices->deleteBySettoreId` (per sector) → `sectors->deleteByEventoId` (per event) → `events->deleteByTitoloId` → `titles->delete`; `COMMIT` on success, `ROLLBACK` on `Throwable` |
| Admin notice on success/failure | PASS | `redirect()` adds `deleted=success\|failed` query arg; `render_notices()` echoes `notice-success` / `notice-error` block with `esc_html__` |
| Submenu slug `cinebot-wp-programmazioni` | PASS | `AdminMenu::add_menu()` calls `add_submenu_page('cinebot-wp', ..., 'cinebot-wp-programmazioni', [$this->titoli_page, 'render'])` |
| Plugin composes TitoliListPage | PASS | `Plugin::admin_menu()` constructs `TitoliListPage` with all five repositories and passes to `AdminMenu` (3rd constructor param) |
| `manage_options` capability | PASS | `handle_actions()` gates on `current_user_can( $this->capability() )` where `capability()` returns filtered `manage_options` |
| PHP 7.4 compatible | PASS | No constructor property promotion, no `mixed`/`never` return types, no enums, no named args; typed properties and `??` are 7.4-safe |
| WPCS-style | PASS | Tabs, snake_case, docblocks, `phpcs:ignore` annotations with justifications for nonce and direct-query deviations |
| Tests written for required scenarios | PASS | `TitoliListPageTest` covers columns, 50/page pagination (incl. page 2), title search, author search, type filter, source filter, escaped poster, missing-poster dash, event count, single-delete nonce required, single-delete cascade, bulk-delete cascade, no edit/new actions, submenu registration, plugin composition |
| No edit/new (Task 13 owns them) | PASS | Confirmed absent |

**Spec: PASS**

## Findings

### MEDIUM — `delete_titles()` does not deduplicate IDs (robustness)

`TitoliListPage.php:212-220` filters non-positive IDs but does not unique them:

```php
$ids = array_values(
    array_filter(
        array_map( 'intval', $ids ),
        static function ( int $id ): bool { return $id > 0; }
    )
);
```

If a request carries duplicate IDs (double-submit, browser back/forward cache, manual tampering), the first loop iteration deletes the title row; the second iteration calls `TitoloRepository::delete()` which returns `false` (0 rows affected), throwing `RuntimeException('delete failed')` and triggering ROLLBACK. Net effect: the entire bulk delete fails for the user even though every title was individually deletable. Compare with `EventoRepository::positive_ids()` and `SettoreRepository::positive_ids()`, both of which deduplicate via `array_unique`. Recommend mirroring that pattern here.

### LOW — `updated_at` rendered as raw MySQL datetime

`column_default()` case `'updated_at'` returns `esc_html( $item->updatedAt ?? '' )`, surfacing `2026-08-06 22:23:00` verbatim. The brief only requires "Ultima modifica"; raw output is functional but inconsistent with WP's i18n-friendly `wp_date( get_option( 'date_format' ), strtotime( $item->updatedAt ) )`. Acceptable for v1.

### LOW — Filters not preserved after delete redirect

`redirect()` bounces to `admin.php?page=cinebot-wp-programmazioni&deleted={status}`, dropping any active `tipoevento_codice`/`source`/`s` filters. A user on a filtered view who deletes a title is returned to the unfiltered list. Minor UX nitpick; out of scope for the brief.

### NITPICK — `_column_headers` assigned directly

`prepare_items()` sets `$this->_column_headers = [ ... ]`. The modern `WP_List_Table` idiom is to override `get_column_info()`. Direct assignment still works (the property is `public` in core), but it is the more brittle path forward.

### NITPICK — Search box rendered inline rather than via `WP_List_Table::search_box()`

`render_filter_controls()` hand-rolls `<p class="search-box">` inside the filter `<form>`. Functional and arguably cleaner than the core helper (which must live outside the bulk form), but deviates from the WP convention reviewers may expect.

### NITPICK — `handle_actions()` lacks an explicit `return` after the bulk-redirect branch

`redirect()` calls `exit`, so the second `if ( null !== $titolo )` branch is unreachable after the bulk path. Adding `return;` (or returning from `redirect()`) would make the control flow self-documenting and prevent a future maintainer from reordering the branches.

## Quality

Code is clean, idiomatic, consistently escaped, capability- and nonce-gated, PHP 7.4-safe, WPCS-aligned, and accompanied by a comprehensive integration test suite that exercises every brief requirement. The one MEDIUM finding (ID deduplication) is a robustness gap with a safe failure mode (transaction rolls back, no orphaned children, no data corruption), not a correctness or security defect. All other findings are stylistic or minor UX.

**Quality: APPROVED** (no blocking issues; recommend addressing the MEDIUM deduplication finding in a follow-up or before Task 13 lands).

## Counts

- Findings: 6 total — 0 blocking, 1 medium, 2 low, 3 nitpick
- Spec items verified: 20/20 PASS
- Tests reviewed: 14 test methods in `TitoliListPageTest`, plus 1 fixture update in `ApiAdminPageTest`
