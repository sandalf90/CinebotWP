# Task 2: more_url "Vedi altro" button

**Files:**
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults), `:176-178` (sanitization), `:105-110` (template context)
- Modify: `templates/programmazione-cards.php:49-51`
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Consumes: `exclude_tipo` from Task 1 (already in `$atts`).
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `more_url` (string, `esc_url_raw`, default `''`) and `more_label` (string, `sanitize_text_field`, default translated 'Vedi altro'). Template receives `$atts['more_url']` and `$atts['more_label']` in context. When `more_url` is non-empty and `count($cards) < $total`, template renders `<a class="cinebot-vedi-altro" href="...">` instead of the load-more button.

## Step 1: Write failing tests for `more_url`

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

## Step 2: Run tests to verify they fail

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `more_url` and `more_label` are unknown attributes (ignored), no `cinebot-vedi-altro` in output.

## Step 3: Add `more_url` and `more_label` to shortcode normalization

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

## Step 4: Add "Vedi altro" rendering to template

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

## Step 5: Run tests to verify they pass

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — all three new tests pass, existing tests still pass (default behavior unchanged when `more_url` is empty).

## Step 6: Run quality gate

Run: `docker compose run --rm php composer check`
Expected: PASS (or only pre-existing failures).

## Step 7: Commit

```bash
git add includes/Frontend/ShortcodeHandler.php templates/programmazione-cards.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: add vedi altro link to cinebot schedule shortcode"
```
