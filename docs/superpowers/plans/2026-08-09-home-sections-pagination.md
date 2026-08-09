# Home Sections & Pagination Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extend `[cinebot_programmazione]` with `exclude_tipo`, `more_url`, and numbered pagination so the home page can show two sections (CINEMA + other types) with "Vedi altro" buttons linking to full listing pages with pagination.

**Architecture:** Extend the existing shortcode + repository + template (Approach C from the approved design). No new files, no rewrite rules, no React. Three vertical slices: (1) `exclude_tipo` filter, (2) `more_url` "Vedi altro" button, (3) `pagination="numbered"`.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL/MariaDB InnoDB, PHPUnit 9, WPCS, PHPStan.

## Global Constraints

- PHP 7.4+, WordPress 6.0+ (composer.json:7, CLAUDE.md:3).
- Namespace `CinebotWp\`, one class per file (CONVENTIONS.md:12).
- SQL in repositories only, `$wpdb->prepare()` for all dynamic values (CONVENTIONS.md:13-15).
- Sanitize input at boundaries, escape output at rendering (CONVENTIONS.md:30).
- Public queries include only reconciled-active events with `evento.stato = 3` (CONVENTIONS.md:23, already guaranteed by `public_query`).
- TDD: write failing tests before implementation (CONVENTIONS.md:36).
- Quality gate: `docker compose run --rm php composer check` (CLAUDE.md:15).
- Git mode: `solo-git`, Conventional Commits (CLAUDE.md:19-20).
- No Multisite, React, write-back API, or rewrite rules (CONVENTIONS.md:53, spec section 8).

## File Structure

| File | Responsibility | Action |
|---|---|---|
| `includes/Repositories/TitoloRepository.php` | Add `exclude_tipo` branch to `public_query()` | Modify lines 293-296 |
| `includes/Frontend/ShortcodeHandler.php` | Normalize new attributes; compute pagination state | Modify `normalizeAttributes()`, `renderProgrammazione()` |
| `templates/programmazione-cards.php` | Render "Vedi altro" link and numbered pagination nav | Modify lines 49-51 |
| `tests/Integration/ShortcodeHandlerTest.php` | Test all new attributes via `do_shortcode` | Add test methods |

---

## Task 1: `exclude_tipo` filter in repository

**Files:**
- Modify: `includes/Repositories/TitoloRepository.php:293-296`
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults) and `:176-178` (sanitization)
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Produces: `TitoloRepository::public_query()` accepts `exclude_tipo` key in `$filters` array (string code). When `tipo` is non-empty, `exclude_tipo` is ignored. When only `exclude_tipo` is non-empty, clause `ty.codice != %s` is added.
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `exclude_tipo` key (string, sanitized via `sanitize_text_field`, default `''`).

- [ ] **Step 1: Write failing tests for `exclude_tipo`**

Add these two methods to `tests/Integration/ShortcodeHandlerTest.php`, after `test_filters_by_tipo` (line 103):

```php
	/** Shortcode filters by exclude_tipo (all types except the given code). */
	public function test_filters_by_exclude_tipo(): void {
		$this->seed_active_event( '01', 'Cinema Show' );
		$this->seed_active_event( '45', 'Teatro Prosa Show' );

		$html = do_shortcode( '[cinebot_programmazione exclude_tipo="01"]' );

		self::assertStringNotContainsString( 'Cinema Show', $html );
		self::assertStringContainsString( 'Teatro Prosa Show', $html );
	}

	/** tipo takes precedence over exclude_tipo when both are set. */
	public function test_tipo_takes_precedence_over_exclude_tipo(): void {
		$this->seed_active_event( '01', 'Cinema Show' );
		$this->seed_active_event( '45', 'Teatro Prosa Show' );
		$this->seed_active_event( '53', 'Concert Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" exclude_tipo="45"]' );

		self::assertStringContainsString( 'Cinema Show', $html );
		self::assertStringNotContainsString( 'Teatro Prosa Show', $html );
		self::assertStringNotContainsString( 'Concert Show', $html );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `exclude_tipo` attribute is unknown (ignored by `shortcode_atts`), so both tests fail because all events are returned.

- [ ] **Step 3: Add `exclude_tipo` to repository `public_query()`**

In `includes/Repositories/TitoloRepository.php`, replace lines 293-296 (the `tipo` block):

```php
		if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
			$clauses[] = 'ty.codice = %s';
			$values[] = sanitize_text_field( (string) $filters['tipo'] );
		}
```

with:

```php
		if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
			$clauses[] = 'ty.codice = %s';
			$values[] = sanitize_text_field( (string) $filters['tipo'] );
		} elseif ( isset( $filters['exclude_tipo'] ) && '' !== trim( (string) $filters['exclude_tipo'] ) ) {
			$clauses[] = 'ty.codice != %s';
			$values[] = sanitize_text_field( (string) $filters['exclude_tipo'] );
		}
```

- [ ] **Step 4: Add `exclude_tipo` to shortcode attribute normalization**

In `includes/Frontend/ShortcodeHandler.php`, in the `$defaults` array inside `normalizeAttributes()` (line 147-160), add after `'tipo'`:

```php
			'exclude_tipo'  => '',
```

Then after the existing sanitization block (after line 178, before `return $atts;`), add:

```php
		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — both new tests pass, existing tests still pass.

- [ ] **Step 6: Run quality gate**

Run: `docker compose run --rm php composer check`
Expected: PASS — WPCS, PHPStan, PHPUnit, and build all succeed.

- [ ] **Step 7: Commit**

```bash
git add includes/Repositories/TitoloRepository.php includes/Frontend/ShortcodeHandler.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: filter cinebot schedules by excluded event type"
```

---

## Task 2: `more_url` "Vedi altro" button

**Files:**
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults), `:176-178` (sanitization), `:105-110` (template context)
- Modify: `templates/programmazione-cards.php:49-51`
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Consumes: `exclude_tipo` from Task 1 (already in `$atts`).
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `more_url` (string, `esc_url_raw`, default `''`) and `more_label` (string, `sanitize_text_field`, default translated 'Vedi altro'). Template receives `$atts['more_url']` and `$atts['more_label']` in context. When `more_url` is non-empty and `count($cards) < $total`, template renders `<a class="cinebot-vedi-altro" href="...">` instead of the load-more button.

- [ ] **Step 1: Write failing tests for `more_url`**

Add these three methods to `tests/Integration/ShortcodeHandlerTest.php`:

```php
	/** more_url renders "Vedi altro" link when there are more results. */
	public function test_renders_vedi_altro_when_more_url_set(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/programmazione-cinema"]' );

		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
		self::assertStringContainsString( 'href="/programmazione-cinema"', $html );
		self::assertStringContainsString( 'Vedi altro', $html );
		self::assertStringNotContainsString( 'cinebot-load-more', $html );
	}

	/** more_url does not render "Vedi altro" when all results are shown. */
	public function test_no_vedi_altro_when_all_results_shown(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="100" more_url="/x"]' );

		self::assertStringNotContainsString( 'cinebot-vedi-altro', $html );
	}

	/** more_label overrides the default button text. */
	public function test_more_label_overrides_default(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/x" more_label="Tutti i film"]' );

		self::assertStringContainsString( 'Tutti i film', $html );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `more_url` and `more_label` are unknown attributes (ignored), no `cinebot-vedi-altro` in output.

- [ ] **Step 3: Add `more_url` and `more_label` to shortcode normalization**

In `includes/Frontend/ShortcodeHandler.php`, in the `$defaults` array inside `normalizeAttributes()`, add after `'exclude_tipo'`:

```php
			'more_url'      => '',
			'more_label'    => __( 'Vedi altro', 'cinebot-wp' ),
```

Then in the sanitization block (after the `exclude_tipo` line added in Task 1), add:

```php
		$atts['more_url']   = '' !== trim( $atts['more_url'] ) ? esc_url_raw( $atts['more_url'] ) : '';
		$atts['more_label'] = sanitize_text_field( $atts['more_label'] );
```

- [ ] **Step 4: Add "Vedi altro" rendering to template**

In `templates/programmazione-cards.php`, replace lines 49-51 (the load-more button block):

```php
		<?php if ( count( $cards ) < $total ) : ?>
			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
		<?php endif; ?>
```

with:

```php
		<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
			<a class="cinebot-vedi-altro" href="<?php echo esc_url( $atts['more_url'] ); ?>">
				<?php echo esc_html( $atts['more_label'] ); ?>
			</a>
		<?php elseif ( empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
		<?php endif; ?>
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — all three new tests pass, existing tests still pass (default behavior unchanged when `more_url` is empty).

- [ ] **Step 6: Run quality gate**

Run: `docker compose run --rm php composer check`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add includes/Frontend/ShortcodeHandler.php templates/programmazione-cards.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: add vedi altro link to cinebot schedule shortcode"
```

---

## Task 3: `pagination="numbered"` server-side pagination

**Files:**
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults), `:176-178` (sanitization), `:93-118` (render logic + cache key + template context)
- Modify: `templates/programmazione-cards.php:49-51` (add numbered nav, adjust conditionals)
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Consumes: `more_url` from Task 2 (precedence: `more_url` hides numbered pagination).
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `pagination` (string, allowlist `ajax`/`numbered`, default `ajax`) and `per_page` (int, clamp 1-100, default = `limit`). When `pagination="numbered"`, `renderProgrammazione()` reads `cinebot_page` from `$_GET` (absint, min 1), overrides `limit`/`offset` accordingly, includes `current_page` in cache key, and passes `current_page`, `total_pages`, `base_url` to template. Template renders `<nav class="cinebot-pagination">` with links `?cinebot_page=N` when `total_pages > 1`.

- [ ] **Step 1: Write failing tests for numbered pagination**

Add these four methods to `tests/Integration/ShortcodeHandlerTest.php`:

```php
	/** Numbered pagination renders page links when total > per_page. */
	public function test_numbered_pagination_renders_links(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );

		self::assertStringContainsString( 'cinebot-pagination', $html );
		self::assertStringContainsString( 'cinebot_page=1', $html );
		self::assertStringContainsString( 'cinebot_page=2', $html );
		self::assertStringContainsString( 'cinebot_page=3', $html );
		self::assertStringNotContainsString( 'cinebot-load-more', $html );
	}

	/** Numbered pagination returns correct page when cinebot_page is set. */
	public function test_numbered_pagination_page_2_offset(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$_GET['cinebot_page'] = '2';
		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );
		unset( $_GET['cinebot_page'] );

		self::assertStringContainsString( 'cinebot-page-current', $html );
	}

	/** Numbered pagination does not render nav when only one page. */
	public function test_numbered_pagination_no_nav_single_page(): void {
		$this->seed_active_event( '01', 'Single Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="20"]' );

		self::assertStringNotContainsString( 'cinebot-pagination', $html );
	}

	/** more_url takes precedence over numbered pagination. */
	public function test_more_url_takes_precedence_over_numbered(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2" more_url="/x"]' );

		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
		self::assertStringNotContainsString( 'cinebot-pagination', $html );
	}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `pagination` and `per_page` are unknown attributes, no `cinebot-pagination` in output.

- [ ] **Step 3: Add `pagination` and `per_page` to shortcode normalization**

In `includes/Frontend/ShortcodeHandler.php`, in the `$defaults` array inside `normalizeAttributes()`, add after `'more_label'`:

```php
			'pagination'    => 'ajax',
			'per_page'      => 0,
```

Then in the sanitization block (after the `more_label` line added in Task 2), add:

```php
		if ( ! in_array( $atts['pagination'], array( 'ajax', 'numbered' ), true ) ) {
			$atts['pagination'] = 'ajax';
		}
		$atts['per_page'] = (int) $atts['per_page'];
		if ( $atts['per_page'] <= 0 ) {
			$atts['per_page'] = $atts['limit'];
		}
		$atts['per_page'] = max( 1, min( 100, $atts['per_page'] ) );
```

- [ ] **Step 4: Add numbered pagination logic to `renderProgrammazione()`**

In `includes/Frontend/ShortcodeHandler.php`, in `renderProgrammazione()` (lines 93-118), replace the entire method body with:

```php
	public function renderProgrammazione( array $attributes = array() ): string {
		$atts = $this->normalizeAttributes( $attributes );

		$current_page = 1;
		$total_pages  = 0;
		$base_url     = '';

		if ( 'numbered' === $atts['pagination'] && empty( $atts['more_url'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no mutation.
			$current_page = isset( $_GET['cinebot_page'] ) ? max( 1, absint( wp_unslash( $_GET['cinebot_page'] ) ) ) : 1;
			$atts['limit']  = $atts['per_page'];
			$atts['offset'] = ( $current_page - 1 ) * $atts['per_page'];
			$atts['current_page'] = $current_page;
		}

		$cache_key = 'cinebot_prog_' . md5( wp_json_encode( $atts ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$cards = $this->titles->findPublicSchedule( $atts );
		$total = $this->titles->countPublicSchedule( $atts );

		if ( 'numbered' === $atts['pagination'] && empty( $atts['more_url'] ) ) {
			$total_pages = max( 1, (int) ceil( $total / $atts['per_page'] ) );
			$base_url    = esc_url_raw( remove_query_arg( 'cinebot_page' ) );
		}

		$html = $this->renderer->render( 'programmazione-cards', array(
			'cards'        => $cards,
			'total'        => $total,
			'atts'         => $atts,
			'instance'     => ++self::$instance_id,
			'current_page' => $current_page,
			'total_pages'  => $total_pages,
			'base_url'     => $base_url,
		) );

		$this->enqueueFrontendAssets();

		$ttl = (int) apply_filters( 'cinebot_wp_cache_ttl', 900 );
		set_transient( $cache_key, $html, $ttl );

		return $html;
	}
```

Note: `current_page` is added to `$atts` before the cache key computation so different pages get different cache keys.

- [ ] **Step 5: Add numbered pagination nav to template**

In `templates/programmazione-cards.php`, replace lines 49-51 (the `more_url`/load-more block from Task 2) with the full conditional block:

```php
		<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
			<a class="cinebot-vedi-altro" href="<?php echo esc_url( $atts['more_url'] ); ?>">
				<?php echo esc_html( $atts['more_label'] ); ?>
			</a>
		<?php elseif ( 'numbered' === $atts['pagination'] && $total_pages > 1 ) : ?>
			<nav class="cinebot-pagination" aria-label="<?php esc_attr_e( 'Navigazione pagine', 'cinebot-wp' ); ?>">
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
					<?php
					$sep = false !== strpos( $base_url, '?' ) ? '&' : '?';
					$page_url = $base_url . $sep . 'cinebot_page=' . $i;
					$is_current = $i === $current_page;
					?>
					<a href="<?php echo esc_url( $page_url ); ?>" <?php echo $is_current ? 'aria-current="page" class="cinebot-page-current"' : ''; ?>>
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endfor; ?>
			</nav>
		<?php elseif ( 'ajax' === $atts['pagination'] && count( $cards ) < $total ) : ?>
			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
		<?php endif; ?>
```

Precedence: `more_url` → numbered pagination → AJAX load-more (default).

Also add the new template variables to the docblock at the top of `templates/programmazione-cards.php`. After `/** @var int $instance */` (line 13), add:

```php
/** @var int $current_page */
/** @var int $total_pages */
/** @var string $base_url */
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — all four new tests pass, existing tests still pass.

- [ ] **Step 7: Run quality gate**

Run: `docker compose run --rm php composer check`
Expected: PASS — WPCS, PHPStan, PHPUnit, and build all succeed.

- [ ] **Step 8: Commit**

```bash
git add includes/Frontend/ShortcodeHandler.php templates/programmazione-cards.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: add numbered pagination to cinebot schedule shortcode"
```

---

## Post-Implementation

After all three tasks pass the quality gate:

1. **Manual acceptance** — on a WordPress instance with synced data:
   - Home page with `[cinebot_programmazione tipo="01" limit="4" show_filters="false" more_url="/programmazione-cinema"]` shows 4 CINEMA cards + "Vedi altro" button.
   - Home page with `[cinebot_programmazione exclude_tipo="01" limit="8" show_filters="false" more_url="/programmazione-altri-tipi"]` shows 8 non-CINEMA cards + "Vedi altro" button.
   - Page `/programmazione-cinema` with `[cinebot_programmazione tipo="01" pagination="numbered" per_page="20"]` shows full list + numbered pagination.
   - Page `/programmazione-altri-tipi` with `[cinebot_programmazione exclude_tipo="01" pagination="numbered" per_page="20"]` shows full list + numbered pagination.

2. **Regression** — existing `[cinebot_programmazione]` without new attributes still renders filters + AJAX load-more as before (default `pagination="ajax"`, empty `more_url`).
