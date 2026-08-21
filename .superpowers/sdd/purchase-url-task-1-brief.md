### Task 1: Generalize The Hardened Cinebot URL Service

**Files:**
- Create: `includes/Services/CinebotUrlService.php`
- Delete: `includes/Services/LocandinaService.php`
- Create: `tests/Unit/CinebotUrlServiceTest.php`
- Delete: `tests/Unit/LocandinaServiceTest.php`
- Modify: `includes/Services/SyncService.php:45-70,271-293`

**Interfaces:**
- Consumes: Existing DNS/path validation and poster flag semantics from `LocandinaService::build()`.
- Produces: `CinebotUrlService::buildLocandina(string $host, string $path, int $titleId, int $flag): ?string`.
- Produces: `CinebotUrlService::buildAcquisto(string $host, string $path, int $eventId): string`.
- Produces: A single safe error string, `Unable to build Cinebot URL.`, that never includes caller input.

- [ ] **Step 1: Rename the unit test contract and add failing purchase URL cases**

Move `tests/Unit/LocandinaServiceTest.php` to `tests/Unit/CinebotUrlServiceTest.php`, then make these exact symbol replacements throughout the moved test:

```php
use CinebotWp\Services\CinebotUrlService;

final class CinebotUrlServiceTest extends TestCase {
```

Replace every `new LocandinaService()` with `new CinebotUrlService()`, every poster call `->build(` with `->buildLocandina(`, and every expected error string `Unable to build poster URL.` with `Unable to build Cinebot URL.`.

Add these tests before the existing providers:

```php
/** The canonical event purchase endpoint uses the remote event identity. */
public function test_build_acquisto_returns_exact_sample_url(): void {
	self::assertSame(
		'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
		( new CinebotUrlService() )->buildAcquisto( 'ticket.cinebot.it', 'martinovich', 2920 )
	);
}

/** Purchase URLs share host normalization and per-segment encoding. */
public function test_build_acquisto_normalizes_and_encodes_the_safe_base(): void {
	self::assertSame(
		'https://ticket.cinebot.it/cinema%20uno/sala%2Bdue/evento/2920/acquista',
		( new CinebotUrlService() )->buildAcquisto( 'TICKET.CINEBOT.IT', '/cinema uno/sala+due/', 2920 )
	);
}

/** A generated URL whose encoded representation is exactly 500 bytes is valid. */
public function test_build_acquisto_accepts_exactly_500_bytes(): void {
	$url = ( new CinebotUrlService() )->buildAcquisto(
		'ticket.cinebot.it',
		str_repeat( 'a', 456 ),
		1
	);

	self::assertSame( 500, strlen( $url ) );
}

/** A generated URL cannot exceed the database column width. */
public function test_build_acquisto_rejects_501_bytes(): void {
	$this->expectException( InvalidArgumentException::class );
	$this->expectExceptionMessage( 'Unable to build Cinebot URL.' );

	( new CinebotUrlService() )->buildAcquisto(
		'ticket.cinebot.it',
		str_repeat( 'a', 457 ),
		1
	);
}

/** A purchase URL requires a positive event identity. */
public function test_build_acquisto_rejects_non_positive_event_id(): void {
	$this->expectException( InvalidArgumentException::class );
	$this->expectExceptionMessage( 'Unable to build Cinebot URL.' );

	( new CinebotUrlService() )->buildAcquisto( 'ticket.cinebot.it', 'martinovich', 0 );
}

/** Purchase validation never reflects hostile path content. */
public function test_build_acquisto_rejects_hostile_path_without_reflection(): void {
	$path = '../secret-api-password';

	try {
		( new CinebotUrlService() )->buildAcquisto( 'ticket.cinebot.it', $path, 2920 );
		self::fail( 'Expected an invalid Cinebot purchase path.' );
	} catch ( InvalidArgumentException $exception ) {
		self::assertSame( 'Unable to build Cinebot URL.', $exception->getMessage() );
		self::assertStringNotContainsString( $path, $exception->getMessage() );
	}
}
```

- [ ] **Step 2: Run the renamed URL service test and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter CinebotUrlServiceTest
```

Expected: FAIL because `CinebotWp\Services\CinebotUrlService` does not exist.

- [ ] **Step 3: Replace the poster-only service with the unified implementation**

Move `includes/Services/LocandinaService.php` to `includes/Services/CinebotUrlService.php` and replace its contents with:

```php
<?php
/**
 * Safe Cinebot URL construction.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use InvalidArgumentException;

/** Builds deterministic HTTPS URLs from validated Cinebot API fields. */
final class CinebotUrlService {
	private const SAFE_ERROR = 'Unable to build Cinebot URL.';
	private const MAX_URL_LENGTH = 500;

	/** Build a poster URL when the API flag enables one. */
	public function buildLocandina( string $host, string $path, int $titleId, int $flag ): ?string {
		if ( $flag <= 0 ) {
			return null;
		}

		return $this->buildUrl( $host, $path, 'titolo', $titleId, 'locandina' );
	}

	/** Build the purchase URL for one positive remote event identity. */
	public function buildAcquisto( string $host, string $path, int $eventId ): string {
		return $this->buildUrl( $host, $path, 'evento', $eventId, 'acquista' );
	}

	/** Validate the shared base and append one fixed Cinebot endpoint. */
	private function buildUrl( string $host, string $path, string $resource, int $remoteId, string $action ): string {
		if ( $remoteId <= 0 ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$host = strtolower( $host );
		if ( ! $this->isValidHost( $host ) ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$path = trim( $path, '/' );
		if ( '' === $path ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		$segments = explode( '/', $path );
		foreach ( $segments as &$segment ) {
			if ( ! $this->isValidSegment( $segment ) ) {
				throw new InvalidArgumentException( self::SAFE_ERROR );
			}
			$segment = rawurlencode( $segment );
		}
		unset( $segment );

		$url = 'https://' . $host . '/' . implode( '/', $segments ) . '/' . $resource . '/' . $remoteId . '/' . $action;
		if ( strlen( $url ) > self::MAX_URL_LENGTH ) {
			throw new InvalidArgumentException( self::SAFE_ERROR );
		}

		return $url;
	}

	/** Check a DNS hostname without accepting ports, IPs, or localhost. */
	private function isValidHost( string $host ): bool {
		if (
			'' === $host
			|| strlen( $host ) > 253
			|| false === strpos( $host, '.' )
			|| false !== filter_var( $host, FILTER_VALIDATE_IP )
		) {
			return false;
		}

		$labels = explode( '.', $host );
		foreach ( $labels as $label ) {
			if (
				'' === $label
				|| strlen( $label ) > 63
				|| 1 !== preg_match( '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/D', $label )
			) {
				return false;
			}
		}

		return true;
	}

	/** Check one relative path segment before URL encoding. */
	private function isValidSegment( string $segment ): bool {
		return '' !== $segment
			&& '.' !== $segment
			&& '..' !== $segment
			&& false === strpos( $segment, '\\' )
			&& false === strpos( $segment, '?' )
			&& false === strpos( $segment, '#' )
			&& 1 !== preg_match( '/[\x00-\x1F\x7F]/', $segment )
			&& 1 !== preg_match( '/[a-z][a-z0-9+.-]*:/i', $segment )
			&& 1 !== preg_match( '/%(?:2f|3f|23|5c|0[0-9a-f]|1[0-9a-f]|7f)/i', $segment );
	}
}
```

- [ ] **Step 4: Adapt SyncService to the renamed poster method without adding purchase persistence yet**

Replace the service property, final constructor parameter, assignment, and poster call with:

```php
/** @var CinebotUrlService */
private $urls;
```

```php
?SyncLock $lock = null,
?CinebotUrlService $urls = null
```

```php
$this->urls = $urls ?? new CinebotUrlService();
```

```php
$title->locandinaUrl = $this->urls->buildLocandina(
	$this->required_string( $envelope, 'host' ),
	$this->required_string( $envelope, 'path' ),
	$title->idtitolo,
	(int) $title->locandinaFlag
);
```

Do not add a `LocandinaService` alias and do not change constructor argument order.

- [ ] **Step 5: Run focused unit and sync regression tests**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter CinebotUrlServiceTest
rtk docker compose run --rm php composer test:integration -- --filter SyncServiceTest
```

Expected: PASS; existing poster output remains exactly `https://ticket.cinebot.it/martinovich/titolo/491/locandina`, and all new purchase-builder tests pass.

- [ ] **Step 6: Commit the unified service**

```bash
rtk git add -A -- includes/Services/LocandinaService.php includes/Services/CinebotUrlService.php includes/Services/SyncService.php tests/Unit/LocandinaServiceTest.php tests/Unit/CinebotUrlServiceTest.php
rtk git commit -m "refactor: generalize Cinebot URL service"
```

---
