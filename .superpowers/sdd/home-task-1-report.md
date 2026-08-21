# Task 1 Report: exclude_tipo filter in repository

## Status: DONE_WITH_CONCERNS

## What I Implemented

### Primary deliverable: `exclude_tipo` shortcode attribute

Added a new `exclude_tipo` attribute to `[cinebot_programmazione]` that filters public schedules to exclude events of one event-type code. When `tipo` is non-empty, `exclude_tipo` is ignored (tipo takes precedence).

**Repository** (`includes/Repositories/TitoloRepository.php:296-298`):
- Added `elseif` branch in `public_query()` after the existing `tipo` block:
  - Clause: `ty.codice != %s`
  - Value: sanitized via `sanitize_text_field()`

**Shortcode handler** (`includes/Frontend/ShortcodeHandler.php`):
- Added `'exclude_tipo' => ''` to the `$defaults` array in `normalizeAttributes()` (line 153)
- Added `$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );` before `return $atts;` (line 185)

### Prerequisite fixes (pre-existing bugs found and fixed)

Three pre-existing bugs prevented the test suite from running at all. All were fixed as separate commits before the TDD cycle.

1. **Schema not created in `ShortcodeHandlerTest`** (`tests/Integration/ShortcodeHandlerTest.php`):
   - `set_up()` never called `SchemaInstaller::install()`, so all 7 custom tables were missing.
   - Fix: added `use CinebotWp\Database\SchemaInstaller;` import and `( new SchemaInstaller( $wpdb ) )->install();` after `parent::set_up();`.
   - Pattern mirrors `tests/Integration/TipologiaRepositoryTest.php:43-49`.

2. **`array` type hint on shortcode callbacks** (`includes/Frontend/ShortcodeHandler.php`):
   - `renderProgrammazione(array $attributes = array())` and `renderTitolo(array $attributes = array())` type-hinted `array`, but WordPress passes `''` (empty string) when a shortcode has no attributes.
   - Fix: removed `array` type hint, added `if ( ! is_array( $attributes ) ) { $attributes = array(); }` coercion at the start of both methods.

3. **Missing `extract()` in `TemplateRenderer::render()`** (`includes/Frontend/TemplateRenderer.php`):
   - The `render()` method accepted a `$context` array but never called `extract()`, so template variables (`$cards`, `$instance`, `$atts`, `$total`) were always undefined.
   - Fix: added `extract( $context, EXTR_SKIP );` before `require $path;`, with `phpcs:ignore WordPress.PHP.DontExtract` (justified: mirrors WP core `load_template()`).

## TDD Evidence

### RED phase (before implementation)

```
$ docker compose run --rm php vendor/bin/phpunit --filter "test_filters_by_exclude_tipo|test_tipo_takes_precedence_over_exclude_tipo" --testdox

Shortcode Handler (CinebotWp\Tests\Integration\ShortcodeHandler)
 ✘ Filters by exclude tipo
   │ Failed asserting that an HTML string containing both 'Cinema Show' and
   │ 'Teatro Prosa Show' does not contain "Cinema Show".
   │ /plugin/tests/Integration/ShortcodeHandlerTest.php:114

Tests: 2, Assertions: 4, Failures: 1.
```

- `test_filters_by_exclude_tipo`: FAILED — `exclude_tipo` unknown to `shortcode_atts`, both events returned.
- `test_tipo_takes_precedence_over_exclude_tipo`: PASSED — `tipo` already implemented, takes precedence; `exclude_tipo` ignored.

This is the expected RED state: one test fails (driving the implementation), the other passes (confirming precedence rule works without the new code).

### GREEN phase (after implementation)

```
$ docker compose run --rm php vendor/bin/phpunit --filter "test_filters_by_exclude_tipo|test_tipo_takes_precedence_over_exclude_tipo" --testdox

Shortcode Handler (CinebotWp\Tests\Integration\ShortcodeHandler)
 ✔ Filters by exclude tipo
 ✔ Tipo takes precedence over exclude tipo

OK (2 tests, 5 assertions)
```

### Full ShortcodeHandlerTest (no regressions)

```
$ docker compose run --rm php vendor/bin/phpunit --testsuite integration --filter "ShortcodeHandlerTest" --testdox

Shortcode Handler (CinebotWp\Tests\Integration\ShortcodeHandler)
 ✔ Renders container with no results
 ✔ Renders filters by default
 ✔ Hides filters when disabled
 ✔ Renders cards with data
 ✔ Filters by tipo
 ✔ Filters by exclude tipo
 ✔ Tipo takes precedence over exclude tipo
 ✔ Excludes inactive events
 ✔ Excludes non state 3 events
 ✔ Clamps limit
 ✔ Invalid orderby falls back
 ✔ Invalid order falls back
 ✔ Titolo shortcode renders detail
 ✔ Titolo shortcode empty for missing
 ✔ Titolo shortcode empty for invalid id
 ✔ Uses transient cache
 ✔ Registers both shortcodes

OK (17 tests, 29 assertions)
```

## Files Changed

### Committed changes (3 commits)

**Commit `0bbf0a7` — `fix: install schema in ShortcodeHandlerTest setup`**
- `tests/Integration/ShortcodeHandlerTest.php` (+2 lines: import + install call)

**Commit `aae509c` — `fix: coerce shortcode attrs and extract template context`**
- `includes/Frontend/ShortcodeHandler.php` (+14/-4: removed array type hints, added coercion in `renderProgrammazione()` and `renderTitolo()`)
- `includes/Frontend/TemplateRenderer.php` (+3: added `extract( $context, EXTR_SKIP )` with phpcs:ignore)

**Commit `ddeea2b` — `feat: filter cinebot schedules by excluded event type`**
- `includes/Repositories/TitoloRepository.php` (+3: `elseif` branch for `exclude_tipo`)
- `includes/Frontend/ShortcodeHandler.php` (+3: `exclude_tipo` in defaults + sanitization)
- `tests/Integration/ShortcodeHandlerTest.php` (+24: two test methods)

## Quality Gate Results

`docker compose run --rm php composer check` runs: `@lint` → `@analyse` → `@test` → `@build`

| Step | Result | Notes |
|------|--------|-------|
| **lint** (phpcs) | FAIL | Pre-existing errors across many files (missing docblocks, multi-line formatting, camelCase vars). My changes introduced 1 error (`extract()`) which I fixed with `phpcs:ignore`. No other NEW errors from my changes. |
| **analyse** (phpstan) | FAIL | Pre-existing config error: "This file is included multiple times: szepeviktor/phpstan-wordpress/extension.neon". Not related to my changes. |
| **test** (phpunit) | FAIL | Pre-existing failures in other test files + uncommitted changes from previous session in admin/test files. `ShortcodeHandlerTest` passes 17/17. |
| **build** | PASS | Creates `dist/cinebot-wp.zip` successfully. |

## Self-Review Findings

### Positive
- TDD discipline followed: RED → GREEN with verbatim test code from the brief.
- Implementation matches the brief exactly (elseif branch, defaults entry, sanitization line).
- No regressions in `ShortcodeHandlerTest` (17/17 pass).
- Prerequisite fixes follow existing codebase patterns (SchemaInstaller in set_up, phpcs:ignore comments).
- SQL uses `$wpdb->prepare()` via parameterized clauses (no raw SQL injection).
- Input sanitized at boundary (`sanitize_text_field`), output escaped at rendering (`esc_attr`, `esc_html`).
- Conventional Commits used for all 3 commits.

### Concerns
1. **Quality gate does not pass** — pre-existing lint errors (43+ in TitoloRepository, 27+ in ShortcodeHandler), PHPStan config error, and pre-existing test failures in other test files. These are NOT caused by my changes but block the gate.
2. **Three pre-existing bugs found** — the plugin's frontend rendering has never actually worked (missing `extract()`, type-hint crash on no-attribute shortcodes, missing schema in tests). These suggest the codebase was committed without running the test suite.
3. **Uncommitted changes in working tree** — there are uncommitted changes from a previous session in admin pages and test files (`includes/Admin/*`, `tests/Integration/MonitoringAdminPagesTest.php`, etc.) that may cause additional test failures in the full suite. These are not related to my task.
4. **Test data isolation** — `ShortcodeHandlerTest` relies on test definition order (PHPUnit default) for data isolation. Custom table data persists between tests. This works currently but is fragile. The existing tests were designed with this assumption, and my new tests follow the same pattern.

## Concerns for Downstream Tasks (2 and 3)

Tasks 2-3 extend the `[cinebot_programmazione]` shortcode for the home-page feature. They will need:
- The three prerequisite fixes (already committed).
- Awareness that the quality gate has pre-existing failures.
- The `exclude_tipo` attribute is now available for the home-page two-section layout (CINEMA + "Vedi altro").

---

## Appendix: Code Review Fix — phpcs:ignore misplacement in TemplateRenderer

**Date:** 2026-08-09
**Commit:** `91c69203f3fc7f730cbbb0feb1d2094fafbd050e` — `fix: correct phpcs ignore scope in TemplateRenderer`

### Issue

The original `// phpcs:ignore` in `TemplateRenderer::render()` only covered the next line (the `if`), so:
- `extract()` was **not** covered by `WordPress.PHP.DontExtract` — it would be flagged.
- `require $path` lost its `WordPress.Files.DirectFileAccess` coverage.

### Fix Applied

Replaced the single-line `phpcs:ignore` comments with a block-scoped `phpcs:ignoreStart` / `phpcs:ignoreEnd` covering both `extract()` and `require $path`. Also removed the unnecessary `! empty( $context )` guard — `extract( array(), EXTR_SKIP )` is a no-op.

**Before:**
```php
// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted plugin template.
if ( ! empty( $context ) ) {
    // phpcs:ignore WordPress.PHP.DontExtract -- template variable injection mirrors WP core load_template().
    extract( $context, EXTR_SKIP );
}
require $path;
```

**After:**
```php
// phpcs:ignoreStart WordPress.Files.DirectFileAccess, WordPress.PHP.DontExtract -- trusted plugin template, mirrors WP core load_template().
extract( $context, EXTR_SKIP );
require $path;
// phpcs:ignoreEnd
```

### Verification

| Check | Command | Result |
|-------|---------|--------|
| Tests | `docker compose run --rm php vendor/bin/phpunit --filter "ShortcodeHandlerTest" --testdox` | **PASS** — 17 tests, 29 assertions, 0 failures |
| phpcs (file) | `docker compose run --rm php vendor/bin/phpcs --standard=WordPress includes/Frontend/TemplateRenderer.php` | `WordPress.Files.DirectFileAccess` and `WordPress.PHP.DontExtract` **no longer reported** (properly suppressed by block-scoped ignore). 4 pre-existing unrelated errors remain (filename casing, class-file naming, missing `@throws`, missing param doc). |

### Test Output

```
Shortcode Handler (CinebotWp\Tests\Integration\ShortcodeHandler)
 ✔ Renders container with no results
 ✔ Renders filters by default
 ✔ Hides filters when disabled
 ✔ Renders cards with data
 ✔ Filters by tipo
 ✔ Filters by exclude tipo
 ✔ Tipo takes precedence over exclude tipo
 ✔ Excludes inactive events
 ✔ Excludes non state 3 events
 ✔ Clamps limit
 ✔ Invalid orderby falls back
 ✔ Invalid order falls back
 ✔ Titolo shortcode renders detail
 ✔ Titolo shortcode empty for missing
 ✔ Titolo shortcode empty for invalid id
 ✔ Uses transient cache
 ✔ Registers both shortcodes

OK (17 tests, 29 assertions)
```

### Diff

```diff
--- a/includes/Frontend/TemplateRenderer.php
+++ b/includes/Frontend/TemplateRenderer.php
@@ -28,11 +28,10 @@ final class TemplateRenderer {
 
 		ob_start();
 		try {
-			// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted plugin template.
-			if ( ! empty( $context ) ) {
-				extract( $context, EXTR_SKIP );
-			}
+			// phpcs:ignoreStart WordPress.Files.DirectFileAccess, WordPress.PHP.DontExtract -- trusted plugin template, mirrors WP core load_template().
+			extract( $context, EXTR_SKIP );
 			require $path;
+			// phpcs:ignoreEnd
 			return (string) ob_get_clean();
 		} catch ( \Throwable $e ) {
 			ob_end_clean();
```
