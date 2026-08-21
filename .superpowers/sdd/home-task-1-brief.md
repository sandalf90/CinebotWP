# Task 1: exclude_tipo filter in repository

**Files:**
- Modify: `includes/Repositories/TitoloRepository.php:293-296`
- Modify: `includes/Frontend/ShortcodeHandler.php:147-160` (defaults) and `:176-178` (sanitization)
- Test: `tests/Integration/ShortcodeHandlerTest.php` (add methods)

**Interfaces:**
- Produces: `TitoloRepository::public_query()` accepts `exclude_tipo` key in `$filters` array (string code). When `tipo` is non-empty, `exclude_tipo` is ignored. When only `exclude_tipo` is non-empty, clause `ty.codice != %s` is added.
- Produces: `ShortcodeHandler::normalizeAttributes()` returns `exclude_tipo` key (string, sanitized via `sanitize_text_field`, default `''`).

## Step 1: Write failing tests for `exclude_tipo`

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

## Step 2: Run tests to verify they fail

Run: `docker compose run --rm php composer test:integration`
Expected: FAIL — `exclude_tipo` attribute is unknown (ignored by `shortcode_atts`), so both tests fail because all events are returned.

## Step 3: Add `exclude_tipo` to repository `public_query()`

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

## Step 4: Add `exclude_tipo` to shortcode attribute normalization

In `includes/Frontend/ShortcodeHandler.php`, in the `$defaults` array inside `normalizeAttributes()` (line 147-160), add after `'tipo'`:

```php
			'exclude_tipo'  => '',
```

Then after the existing sanitization block (after line 178, before `return $atts;`), add:

```php
		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
```

## Step 5: Run tests to verify they pass

Run: `docker compose run --rm php composer test:integration`
Expected: PASS — both new tests pass, existing tests still pass.

## Step 6: Run quality gate

Run: `docker compose run --rm php composer check`
Expected: PASS — WPCS, PHPStan, PHPUnit, and build all succeed.

## Step 7: Commit

```bash
git add includes/Repositories/TitoloRepository.php includes/Frontend/ShortcodeHandler.php tests/Integration/ShortcodeHandlerTest.php
git commit -m "feat: filter cinebot schedules by excluded event type"
```
