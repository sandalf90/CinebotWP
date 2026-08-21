# Task 13 Review — Nested title/event editor

**Commit:** `20ee65d` (parent: `d769c2b`)
**Date:** 2026-08-06

## Static Assessment

Verified by direct file inspection:

- **Transaction:** `START TRANSACTION` -> `persist_hierarchy()` -> `COMMIT`, with `ROLLBACK` in catch(Throwable). Safe atomic save.
- **Ownership:** `belongsTo*` methods used to verify existing IDs belong to the edited parent before update. Cross-title tampering prevented.
- **Source preservation:** New title/event/sector/price rows get `source='manual'` and null remote IDs. Imported rows keep their existing `source='api'` and remote IDs.
- **Cascade deletion:** Removed children deleted via `deleteBySettoreId` -> `deleteByEventoId` in price -> sector -> event order. Only under the edited title.
- **Escaping:** `wp_kses_post` for descrizione, `esc_url_raw` for URLs, `esc_attr`/`esc_html`/`esc_textarea` throughout rendering.
- **Nonce + capability:** `check_admin_referer` + `current_user_can(manage_options)` on save.
- **Tags:** Comma-separated input, converted to JSON array on save.
- **JS:** Template-based add/remove with `__INDEX__` replacement, monotonic keys, no reuse.
- **PHP 7.4:** Compatible syntax, WPCS-style tabs/snake_case/docblocks.
- **Test coverage:** 840 lines covering new/edit forms, nonce, capability, source preservation, validation, atomic rollback, removed-child deletion, descrizione sanitization, URL escaping, tags JSON.

## Verdicts

- **Spec: PASS** (static review)
- **Quality: APPROVED** (static review)
- **Findings:** 0 critical, 0 high, 0 medium, 2 low (non-blocking)
  - Low: descrizione uses textarea instead of `wp_editor()` (brief allows "wysiwyg/textarea")
  - Low: venue select capped at 500 results (acceptable for v1.0)

## Verification Limits

Docker/PHPUnit/WPCS/PHPStan not run. All conclusions are static. CI must execute dynamic gates.
