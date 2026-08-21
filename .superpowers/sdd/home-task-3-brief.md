# Task 3: `pagination="numbered"` server-side pagination

**Files:**
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults), `:176-178` (sanitization), `:93-118` (render logic + cache key + template context)
- Modify: `templates/programmazione-cards.php:49-51` (add numbered nav, adjust conditionals)
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Consumes: `more_url` from Task 2 (precedence: `more_url` hides numbered pagination).
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `pagination` (string, allowlist `ajax`/`numbered`, default `ajax`) and `per_page` (int, clamp 1-100, default = `limit`). When `pagination="numbered"`, `renderProgrammazione()` reads `cinebot_page` from `$_GET` (absint, min 1), overrides `limit`/`offset` accordingly, includes `current_page` in cache key, and passes `current_page`, `total_pages`, `base_url` to template. Template renders `<nav class="cinebot-pagination">` with links `?cinebot_page=N` when `total_pages > 1`.

## Step 1: Write failing tests for numbered pagination

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

## Step 2: Run tests to verify they fail

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `pagination` and `per_page` are unknown attributes, no `cinebot-pagination` in output.

## Step 3: Add `pagination` and `per_page` to shortcode normalization

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

## Step 4: Add numbered pagination logic to `renderProgrammazione()`

In `includes/Frontend/ShortcodeHandler.php`, in `renderProgrammazione()` (lines 93-118), replace the entire method body with:

```php
	public function renderProgrammazione( array $attributes = array() ): string {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}
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

Note: `current_page` is added to `$atts` before the cache key computation so different pages get different cache keys. The `is_array` check and coercion at the top was added in Task 1's prerequisite fix.

## Step 5: Add numbered pagination nav to template

In `templates/programmazione-cards.php`, replace lines 49-51 (the `more_url`/load-more block from Task 2) with the full conditional block:

```php
		<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) >= (int) $atts['limit'] ) : ?>
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

## Step 6: Run tests to verify they pass

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — all four new tests pass, existing tests still pass.

## Step 7: Run quality gate

Run: `docker compose run --rm php composer check`
Expected: PASS (or only pre-existing failures).

## Step 8: Commit

```bash
git add includes/Frontend/ShortcodeHandler.php templates/programmazione-cards.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: add numbered pagination to cinebot schedule shortcode"
```
