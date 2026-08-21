# Task 2 Report: more_url "Vedi altro" button

## What I Implemented

Added `more_url` and `more_label` attributes to the `[cinebot_programmazione]` shortcode so the home page can show a "Vedi altro" link button pointing to a full listing page, instead of the default AJAX "Carica altri" load-more button.

### Changes:

1. **`includes/Frontend/ShortcodeHandler.php`** — `normalizeAttributes()`:
   - Added `'more_url' => ''` and `'more_label' => __( 'Vedi altro', 'cinebot-wp' )` to the `$defaults` array.
   - Added sanitization: `more_url` via `esc_url_raw()` (only when non-empty after trim), `more_label` via `sanitize_text_field()`.
   - Minor Boy Scout fix: aligned `'exclude_tipo' => ''` (was inconsistently indented with an extra space).

2. **`templates/programmazione-cards.php`** — replaced the load-more button block (lines 49-51) with a conditional:
   - If `more_url` is set AND `count($cards) >= $atts['limit']` → render `<a class="cinebot-vedi-altro">` link.
   - Else if `more_url` is empty AND `count($cards) < $total` → render the original `<button class="cinebot-load-more">` (unchanged behavior).

3. **`tests/Integration/ShortcodeHandlerTest.php`** — added 3 test methods (verbatim from the brief):
   - `test_renders_vedi_altro_when_more_url_set`
   - `test_no_vedi_altro_when_all_results_shown`
   - `test_more_label_overrides_default`

## TDD Evidence

### RED (before implementation)
```
Tests: 20, Assertions: 32, Failures: 2.
 ✘ Renders vedi altro when more url set    (cinebot-vedi-altro not in output)
 ✘ More label overrides default             (Tutti i film not in output)
 ✔ No vedi altro when all results shown     (passes trivially — feature absent)
```

### GREEN (after implementation)
```
Tests: 20, Assertions: 35.
 ✔ Renders vedi altro when more url set
 ✔ No vedi altro when all results shown
 ✔ More label overrides default
OK (20 tests, 35 assertions)
```

## Brief Deviation (Important)

The brief's template code specified `count($cards) < $total` as the condition for the vedi-altro link. This does NOT work with the brief's own test cases:

- `test_renders_vedi_altro_when_more_url_set` seeds 1 event with `limit="1"`. With 1 event, `findPublicSchedule` returns 1 card and `countPublicSchedule` returns 1, making `count($cards) < $total` = `1 < 1` = **false**. The link would never render, failing the test.

**Fix:** I used `count($cards) >= (int) $atts['limit']` instead. This is the standard "has_more" heuristic: if we returned as many cards as the limit allows, there might be more results. This correctly distinguishes the two test cases:
- 1 event, `limit=1` → `1 >= 1` = true → link appears (might have more)
- 1 event, `limit=100` → `1 >= 100` = false → no link (definitely no more)

The load-more button retains its stricter `count($cards) < $total` condition — AJAX load-more should not show a button that loads nothing. The two conditions have different semantics:
- "Vedi altro" (link to full page): show when we *might* have more (hit the limit)
- "Carica altri" (AJAX load): show when we *definitely* have more (count < total)

## Files Changed

| File | Changes |
|------|---------|
| `includes/Frontend/ShortcodeHandler.php` | +4 lines (2 defaults, 2 sanitization), 1 alignment fix |
| `templates/programmazione-cards.php` | +5 lines, -1 line (conditional vedi-altro/load-more) |
| `tests/Integration/ShortcodeHandlerTest.php` | +30 lines (3 test methods) |

## Self-Review Findings

1. **Security**: `more_url` sanitized with `esc_url_raw()` on input, `esc_url()` on output. `more_label` sanitized with `sanitize_text_field()` on input, `esc_html()` on output. Proper escaping throughout.

2. **Backward Compatibility**: When `more_url` is empty (default), the behavior is identical to before — load-more button appears when `count($cards) < $total`.

3. **PHPCodeSniffer**: No new violations in `ShortcodeHandler.php` or `programmazione-cards.php`. 3 minor "doc comment capitalization" warnings in the test file (lines 230, 242, 251) — these follow the exact pattern established by Task 1's test at line 118 (`/** tipo takes precedence... */`), and the brief says to use the tests verbatim.

4. **Edge Cases Verified**:
   - 0 events, limit=1, more_url set → no link (0 < 1)
   - 1 event, limit=1, more_url set → link (1 >= 1)
   - 1 event, limit=100, more_url set → no link (1 < 100)
   - 2 events, limit=1, no more_url → load-more button (1 < 2)
   - Exact match (count = limit = total) → link shows (minor false positive, acceptable for "Vedi altro" to full listing page)

## Concerns

1. **Brief deviation**: The condition for the vedi-altro link was changed from `count($cards) < $total` (brief) to `count($cards) >= (int) $atts['limit']` to make the brief's own tests pass. This is documented above and is the correct "has_more" heuristic for a link-to-full-page button.

2. **Minor false positive**: When the number of results exactly equals the limit (and total = limit), the "Vedi altro" link still shows. This is acceptable — the user clicks through to a full listing page that shows the same results. No data loss or error occurs.

3. **Pre-existing phpcs failures**: The quality gate has many pre-existing failures across the codebase (indentation, array spacing, naming conventions). None were introduced by this task.
