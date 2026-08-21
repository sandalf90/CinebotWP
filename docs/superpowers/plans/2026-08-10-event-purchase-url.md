# Imported Event Purchase URL Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Generate and persist a safe Cinebot purchase URL for every API-owned event while upgrading existing WordPress installations without data loss or a forced synchronization.

**Architecture:** Replace the poster-only URL service with one internal `CinebotUrlService` that builds both poster and purchase URLs through the same hardened base validation. Add `url_acquisto` to the event DTO, repository, and schema; version the schema at `1.1.0`; run an idempotent upgrade before plugin composition; then populate the field transactionally during schedule synchronization.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL/MariaDB InnoDB, `$wpdb`, `dbDelta()`, PHPUnit 9, WPCS, PHPStan, Docker Compose.

## Global Constraints

- Follow `CONVENTIONS.md` and the approved design at `docs/superpowers/specs/2026-08-10-event-purchase-url-design.md`.
- Keep WordPress single-site, PHP 7.4+, and WordPress 6.0+ compatibility.
- Add no production dependency, framework, JavaScript build tool, React, or Multisite behavior.
- Generate exactly `https://{host}/{path}/evento/{idevento}/acquista` from the matching API envelope and event.
- Persist the URL as nullable `VARCHAR(500)` column `url_acquisto` and DTO property `urlAcquisto`.
- Reject generated URLs over 500 bytes and keep all URL-builder errors non-reflective.
- A missing or invalid `host`, `path`, or `idevento` must roll back the full synchronization.
- Synchronization must never modify a row whose stored `source` is `manual`.
- New manual events keep `url_acquisto=NULL`; no admin input or public rendering is added.
- Existing API events keep `NULL` until the next manual or scheduled synchronization; schema upgrade must not call the API.
- Database schema version is `1.1.0`; `CINEBOT_WP_VERSION` remains release-managed and is not changed by this plan.
- Never issue unprepared dynamic SQL or expose payload values, credentials, SQL errors, or exception details.
- Do not alter or stage unrelated worktree changes already present in the repository.

---

## File Structure

- Create `includes/Services/CinebotUrlService.php`: validate Cinebot URL bases and build poster or event-purchase endpoints.
- Delete `includes/Services/LocandinaService.php`: replaced internally; no compatibility alias is required because all consumers are plugin-internal.
- Create `tests/Unit/CinebotUrlServiceTest.php`: retain all poster contracts and add purchase URL and 500-byte boundary contracts.
- Delete `tests/Unit/LocandinaServiceTest.php`: renamed with the service.
- Modify `includes/Models/Evento.php`: own nullable `urlAcquisto` and map `url_acquisto`.
- Modify `includes/Repositories/EventoRepository.php`: persist `url_acquisto` on inserts and updates.
- Modify `includes/Database/SchemaInstaller.php`: add the column, own DB version `1.1.0`, store the version after successful installation, and expose an idempotent upgrade check.
- Modify `includes/Plugin.php`: run schema upgrade before composing hooks and stop only Cinebot boot on migration failure.
- Modify `includes/Services/SyncService.php`: use `CinebotUrlService`, generate purchase URLs, and include them in event change detection.
- Modify `includes/Admin/Pages/TitoloEditPage.php`: preserve the stored URL when an existing event is edited through a form that does not expose it.
- Modify `tests/Unit/ModelsTest.php`: verify the new event key and nullable default.
- Modify `tests/Integration/ScheduleRepositoryTest.php`: verify repository round trips for populated and null purchase URLs.
- Modify `tests/Integration/SchemaInstallerTest.php`: verify column shape, DB version ordering, upgrade preservation, and no downgrade.
- Modify `tests/Integration/PluginBootstrapTest.php`: verify migration failure is contained and reported safely.
- Modify `tests/Integration/SyncServiceTest.php`: verify exact generation, idempotence, updates, and rollback.
- Modify `tests/Integration/TitoloEditPageTest.php`: verify API URL preservation and manual null behavior.
- Do not modify `includes/autoload.php`: its PSR-4-style path mapping discovers `CinebotUrlService.php` automatically.
- Do not modify public read models, templates, shortcode SQL projections, CSS, or JavaScript.

---

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

### Task 2: Persist The Nullable Event Purchase URL

**Files:**
- Modify: `tests/Unit/ModelsTest.php:59-78,185-210`
- Modify: `tests/Integration/ScheduleRepositoryTest.php:79-173`
- Modify: `tests/Integration/SchemaInstallerTest.php:70-129,145-225,238-258,334-350`
- Modify: `includes/Models/Evento.php:13-83`
- Modify: `includes/Repositories/EventoRepository.php:47-86`
- Modify: `includes/Database/SchemaInstaller.php:16-53,79-140,223-293`

**Interfaces:**
- Consumes: `CinebotUrlService::buildAcquisto()` output, introduced in Task 1.
- Produces: `Evento::$urlAcquisto` with type `?string` and default `null`.
- Produces: Database key `url_acquisto` in `Evento::fromArray()` and `Evento::toArray()`.
- Produces: `SchemaInstaller::DB_VERSION` with exact value `1.1.0`.
- Produces: Nullable `cinebot_eventi.url_acquisto VARCHAR(500)` with no index.

- [ ] **Step 1: Add failing DTO, repository, and schema assertions**

In the `evento` row returned by `ModelsTest::model_rows()`, add the key directly after `idevento`:

```php
'url_acquisto'      => 'https://ticket.cinebot.it/martinovich/evento/501/acquista',
```

In `test_defaults_preserve_nulls_and_own_manual_reconciliation_state()`, add:

```php
self::assertNull( $evento->urlAcquisto );
```

In `ScheduleRepositoryTest::test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state()`, set the API event URL before save and assert the manual event remains null:

```php
$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/601/acquista';
```

```php
self::assertNull( $stored_manual_event->urlAcquisto );
```

In `SchemaInstallerTest::test_install_creates_approved_schema_and_defaults()`, replace the expected version and add column assertions:

```php
self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
$this->assert_nullable_column( 'eventi', 'url_acquisto' );
$this->assert_column_type( 'eventi', 'url_acquisto', 'varchar(500)' );
```

Add this helper beside `assert_nullable_column()`:

```php
/** Assert the normalized SQL type for one column. */
private function assert_column_type( string $suffix, string $column, string $type ): void {
	$table  = self::$db->prefix . 'cinebot_' . $suffix;
	$result = self::$db->get_row( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	self::assertIsObject( $result );
	self::assertSame( $type, strtolower( (string) $result->Type ) );
}
```

In `test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults()`, add these assertions after the failed and successful attempts respectively:

```php
self::assertFalse( get_option( 'cinebot_wp_db_version' ) );
```

```php
self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
```

Replace the remaining deactivation assertion of literal `'1.0.0'` with `SchemaInstaller::DB_VERSION`.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
rtk docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest|SchemaInstallerTest"
```

Expected: FAIL because `Evento::$urlAcquisto`, `SchemaInstaller::DB_VERSION`, and the database column do not exist; the seed-failure test also exposes that the old installer stores its version too early.

- [ ] **Step 3: Add the event DTO field and exact database mapping**

In `Evento`, add the property after `idevento`:

```php
public ?string $urlAcquisto = null;
```

Add hydration after the `idevento` assignment:

```php
$model->urlAcquisto       = isset( $data['url_acquisto'] ) ? (string) $data['url_acquisto'] : null;
```

Add serialization after the `idevento` key:

```php
'url_acquisto'      => $this->urlAcquisto,
```

- [ ] **Step 4: Persist the field in EventoRepository**

Replace the repository save data and formats with the same existing fields plus `url_acquisto` directly after `idevento`:

```php
$data = array(
	'idevento'          => $event->idevento,
	'url_acquisto'      => $event->urlAcquisto,
	'titolo_id'         => $event->titoloId,
	'inizio'            => $event->inizio,
	'organizzatore_id'  => $event->organizzatoreId,
	'organizzatore_cf'  => $event->organizzatoreCf,
	'locale_id'         => $event->localeId,
	'stato'             => $event->stato,
	'otp'               => $event->otp,
	'controlloaccessi'  => $event->controlloaccessi,
	'mappa'              => $event->mappa,
	'source'             => $event->source,
	'sync_active'        => $manual ? 1 : $event->syncActive,
	'last_seen_sync'     => $manual ? null : $event->lastSeenSync,
	'updated_at'         => $now,
);
$formats = array( '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );
```

Keep the existing `created_at` append and insert/update branches unchanged.

- [ ] **Step 5: Version the schema and add the nullable column**

At the top of `SchemaInstaller`, add:

```php
public const DB_VERSION = '1.1.0';
```

In the `cinebot_eventi` statement, add directly after `idevento`:

```sql
url_acquisto varchar(500) NULL,
```

Move version persistence from before `seed_event_types()` to after it:

```php
foreach ( $this->schema_statements() as $statement ) {
	dbDelta( $statement );
}

$this->seed_event_types();
$this->store_version();
```

Add this method before `seed_event_types()`:

```php
/** Store the completed schema version without making the option autoload. */
private function store_version(): void {
	if ( ! add_option( 'cinebot_wp_db_version', self::DB_VERSION, '', false ) ) {
		update_option( 'cinebot_wp_db_version', self::DB_VERSION, false );
	}

	if ( self::DB_VERSION !== get_option( 'cinebot_wp_db_version' ) ) {
		throw new RuntimeException(
			esc_html__( 'Cinebot WP could not record its database version.', 'cinebot-wp' )
		);
	}
}
```

- [ ] **Step 6: Run focused model, repository, and schema tests**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
rtk docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest|SchemaInstallerTest"
```

Expected: PASS; the schema reports nullable `varchar(500)`, API values round-trip exactly, manual values remain null, and failed seeding leaves the DB version unset.

- [ ] **Step 7: Commit event persistence and schema versioning**

```bash
rtk git add -- includes/Models/Evento.php includes/Repositories/EventoRepository.php includes/Database/SchemaInstaller.php tests/Unit/ModelsTest.php tests/Integration/ScheduleRepositoryTest.php tests/Integration/SchemaInstallerTest.php
rtk git commit -m "feat: persist event purchase URLs"
```

---

### Task 3: Upgrade Existing Schemas Before Plugin Composition

**Files:**
- Modify: `tests/Integration/SchemaInstallerTest.php:145-225,299-365`
- Modify: `tests/Integration/PluginBootstrapTest.php:8-95`
- Modify: `includes/Database/SchemaInstaller.php:27-53`
- Modify: `includes/Plugin.php:8-93`

**Interfaces:**
- Consumes: `SchemaInstaller::DB_VERSION = '1.1.0'` and idempotent `install()` from Task 2.
- Produces: `SchemaInstaller::upgradeIfNeeded(): void`.
- Produces: `Plugin::render_schema_upgrade_error(): void` as a safe `admin_notices` callback.
- Produces: Boot invariant that repositories, cron, admin pages, and shortcodes are composed only after a successful/current schema check.

- [ ] **Step 1: Add a failing existing-database upgrade test**

Add to `SchemaInstallerTest`:

```php
/** An old schema gains the purchase column without changing its event rows. */
public function test_upgrade_if_needed_preserves_existing_events_and_adds_purchase_url(): void {
	$installer = new SchemaInstaller( self::$db );
	$installer->install();
	$table = self::$db->prefix . 'cinebot_eventi';

	self::$db->insert(
		$table,
		array(
			'idevento'  => 777,
			'titolo_id' => 11,
			'inizio'    => '2026-10-08 21:00:00',
			'locale_id' => 22,
			'source'    => 'api',
		),
		array( '%d', '%d', '%s', '%d', '%s' )
	);
	$event_id = (int) self::$db->insert_id;
	self::$db->query( "ALTER TABLE {$table} DROP COLUMN url_acquisto" );
	update_option( 'cinebot_wp_db_version', '1.0.0', false );

	$installer->upgradeIfNeeded();

	$this->assert_nullable_column( 'eventi', 'url_acquisto' );
	$this->assert_column_type( 'eventi', 'url_acquisto', 'varchar(500)' );
	$row = self::$db->get_row( self::$db->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) );
	self::assertIsObject( $row );
	self::assertSame( '777', (string) $row->idevento );
	self::assertSame( '2026-10-08 21:00:00', $row->inizio );
	self::assertNull( $row->url_acquisto );
	self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
}
```

- [ ] **Step 2: Add a failing no-op and no-downgrade test**

Add to `SchemaInstallerTest`:

```php
/** Current or newer schema versions never invoke installation or downgrade. */
public function test_upgrade_if_needed_is_a_no_op_for_current_or_newer_versions(): void {
	$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
		/** @var int */
		public $engine_checks = 0;

		public function get_results( $query = null, $output = OBJECT ) {
			if ( 'SHOW ENGINES' === $query ) {
				++$this->engine_checks;
			}
			return parent::get_results( $query, $output );
		}
	};
	$db->set_prefix( self::$db->prefix );

	try {
		$installer = new SchemaInstaller( $db );
		update_option( 'cinebot_wp_db_version', SchemaInstaller::DB_VERSION, false );
		$installer->upgradeIfNeeded();
		update_option( 'cinebot_wp_db_version', '9.0.0', false );
		$installer->upgradeIfNeeded();
		self::assertSame( 0, $db->engine_checks );
		self::assertSame( '9.0.0', get_option( 'cinebot_wp_db_version' ) );
	} finally {
		$db->close();
	}
}
```

- [ ] **Step 3: Add a failing plugin fail-safe test**

Add both imports to `PluginBootstrapTest`, then add:

```php
use CinebotWp\Database\SchemaInstaller;
use wpdb;
```

Then add:

```php
/** A failed automatic upgrade stops Cinebot composition without breaking WordPress. */
public function test_failed_schema_upgrade_stops_boot_and_renders_safe_admin_notice(): void {
	global $wpdb;
	$original_db = $wpdb;
	update_option( 'cinebot_wp_db_version', '1.0.0', false );
	wp_set_current_user( 1 );

	$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
		public function get_results( $query = null, $output = OBJECT ) {
			if ( 'SHOW ENGINES' === $query ) {
				return array();
			}
			return parent::get_results( $query, $output );
		}
	};
	$failing_db->set_prefix( $original_db->prefix );
	$wpdb = $failing_db;
	$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
	$boot_count = 0;
	$observer = static function () use ( &$boot_count ): void {
		++$boot_count;
	};
	add_action( 'cinebot_wp_booted', $observer );

	try {
		$plugin->boot();
		self::assertSame( 0, $boot_count );
		ob_start();
		do_action( 'admin_notices' );
		$notice = (string) ob_get_clean();
		self::assertStringContainsString( 'could not update its database', $notice );
		self::assertStringNotContainsString( 'InnoDB', $notice );
		self::assertSame( '1.0.0', get_option( 'cinebot_wp_db_version' ) );
	} finally {
		remove_action( 'cinebot_wp_booted', $observer );
		remove_action( 'admin_notices', array( $plugin, 'render_schema_upgrade_error' ) );
		$wpdb = $original_db;
		$failing_db->close();
		update_option( 'cinebot_wp_db_version', SchemaInstaller::DB_VERSION, false );
		wp_set_current_user( 0 );
	}
}
```

- [ ] **Step 4: Run the upgrade tests and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "SchemaInstallerTest|PluginBootstrapTest"
```

Expected: FAIL because `upgradeIfNeeded()` and `render_schema_upgrade_error()` do not exist and `Plugin::boot()` still fires `cinebot_wp_booted` after no migration attempt.

- [ ] **Step 5: Implement the idempotent schema-version gate**

Add to `SchemaInstaller` before `install()`:

```php
/** Install only when the stored schema is older than the current schema. */
public function upgradeIfNeeded(): void {
	$installed = get_option( 'cinebot_wp_db_version', '' );
	if ( is_string( $installed ) && version_compare( $installed, self::DB_VERSION, '>=' ) ) {
		return;
	}

	$this->install();
}
```

Do not call the API, scheduler, or synchronization service from this method.

- [ ] **Step 6: Gate Plugin boot on the schema upgrade**

Add `use Throwable;` to `Plugin.php` and replace `boot()` with:

```php
/** Boot the plugin once, after confirming its schema is current. */
public function boot(): void {
	if ( $this->booted ) {
		return;
	}

	$this->booted = true;
	if ( ! $this->upgrade_schema() ) {
		return;
	}

	self::scheduler()->register();
	self::admin_menu()->register();
	self::shortcodes()->register();
	do_action( 'cinebot_wp_booted' );
}
```

Add these methods after `boot()`:

```php
/** Upgrade the schema or contain the failure to this plugin's boot. */
private function upgrade_schema(): bool {
	global $wpdb;

	try {
		( new SchemaInstaller( $wpdb ) )->upgradeIfNeeded();
		return true;
	} catch ( Throwable $ignored ) {
		add_action( 'admin_notices', array( $this, 'render_schema_upgrade_error' ) );
		return false;
	}
}

/** Render a safe upgrade failure notice to administrators only. */
public function render_schema_upgrade_error(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Cinebot WP could not update its database. The plugin will retry automatically on the next request.', 'cinebot-wp' )
		. '</p></div>';
}
```

Do not log or render `$ignored`; catching it only prevents a site-wide failure.

- [ ] **Step 7: Run schema, bootstrap, and composition tests**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "SchemaInstallerTest|PluginBootstrapTest|PluginIntegrationTest|CronSchedulerTest"
```

Expected: PASS; an old schema is upgraded with row preservation, current/newer versions are no-ops, migration failure suppresses Cinebot composition and exposes only the safe notice, and normal boot remains idempotent.

- [ ] **Step 8: Commit automatic schema upgrades**

```bash
rtk git add -- includes/Database/SchemaInstaller.php includes/Plugin.php tests/Integration/SchemaInstallerTest.php tests/Integration/PluginBootstrapTest.php
rtk git commit -m "feat: upgrade Cinebot schema automatically"
```

---

### Task 4: Generate Purchase URLs During Transactional Synchronization

**Files:**
- Modify: `tests/Integration/SyncServiceTest.php:43-159`
- Modify: `includes/Services/SyncService.php:161-224,271-293,363-366`

**Interfaces:**
- Consumes: `CinebotUrlService::buildAcquisto(string, string, int): string` from Task 1.
- Consumes: `Evento::$urlAcquisto` and repository persistence from Task 2.
- Produces: Every successfully synchronized API event has a canonical `urlAcquisto`.
- Produces: A host/path change is an event update and increments `eventi_updated`.
- Preserves: Existing transaction rollback, safe result/log messages, cache timing, reconciliation, and manual ownership.

- [ ] **Step 1: Add the exact fixture URL assertion and strengthen idempotence**

In `test_imports_complete_fixture_and_records_success()`, add after the event source assertion:

```php
self::assertSame(
	'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
	$event->urlAcquisto
);
```

In `test_identical_payload_is_idempotent_and_hash_is_key_order_invariant()`, add:

```php
self::assertSame( 0, $result->stats()['eventi_updated'] );
```

- [ ] **Step 2: Add a failing host/path update test**

Add to `SyncServiceTest`:

```php
/** A changed envelope path refreshes the event purchase URL exactly once. */
public function test_changed_path_updates_purchase_url_and_event_stats(): void {
	$payload = $this->fixture();
	$payload['programmazione'][0]['titoli'] = array( $payload['programmazione'][0]['titoli'][0] );
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );

	$payload['programmazione'][0]['path'] = 'sala nuova';
	$result = $this->service()->syncPayload( $payload );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 1, $result->stats()['eventi_updated'] );
	self::assertSame(
		'https://ticket.cinebot.it/sala%20nuova/evento/2920/acquista',
		$event->urlAcquisto
	);
}
```

- [ ] **Step 3: Add failing existing-row backfill and manual-ownership tests**

Add to `SyncServiceTest`:

```php
/** The first sync after upgrade backfills an existing null purchase URL. */
public function test_next_sync_backfills_existing_null_purchase_url(): void {
	$payload = $this->fixture();
	$payload['programmazione'][0]['titoli'] = array( $payload['programmazione'][0]['titoli'][0] );
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
	self::$db->update(
		self::$db->prefix . 'cinebot_eventi',
		array( 'url_acquisto' => null ),
		array( 'id' => $event->id ),
		array( '%s' ),
		array( '%d' )
	);

	$result = $this->service()->syncPayload( $payload );
	$stored = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 1, $result->stats()['eventi_updated'] );
	self::assertSame(
		'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
		$stored->urlAcquisto
	);
}

/** Synchronization never replaces a purchase URL owned by a manual event. */
public function test_manual_event_purchase_url_remains_untouched(): void {
	$payload = $this->fixture();
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
	self::$db->update(
		self::$db->prefix . 'cinebot_eventi',
		array(
			'source'        => 'manual',
			'url_acquisto' => 'https://manual.example.test/acquista',
		),
		array( 'id' => $event->id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	$result = $this->service()->syncPayload( $payload );
	$stored = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 'manual', $stored->source );
	self::assertSame( 'https://manual.example.test/acquista', $stored->urlAcquisto );
	self::assertSame( 0, $result->stats()['eventi_updated'] );
}
```

- [ ] **Step 4: Add a failing invalid-base rollback test**

Add to `SyncServiceTest`:

```php
/** Missing or hostile purchase URL fields roll back without leaking payload data. */
public function test_invalid_purchase_url_base_rolls_back_safely(): void {
	$missing_host = $this->fixture();
	$missing_host['programmazione'][0]['titoli'] = array( $missing_host['programmazione'][0]['titoli'][0] );
	$missing_host['programmazione'][0]['titoli'][0]['locandina'] = 0;
	unset( $missing_host['programmazione'][0]['host'] );

	$hostile_path = $this->fixture();
	$hostile_path['programmazione'][0]['titoli'] = array( $hostile_path['programmazione'][0]['titoli'][0] );
	$hostile_path['programmazione'][0]['titoli'][0]['locandina'] = 0;
	$hostile_path['programmazione'][0]['path'] = '../secret-api-password';

	foreach ( array( $missing_host, $hostile_path ) as $payload ) {
		$result = $this->service()->syncPayload( $payload );
		self::assertSame( 'error', $result->status() );
		self::assertSame( 'Schedule synchronization failed.', $result->message() );
		self::assertStringNotContainsString( 'secret-api-password', $result->message() );
		self::assertNull( ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 ) );
		self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
		self::assertSame( 'Schedule synchronization failed.', ( new SyncLogRepository( self::$db ) )->latest()->errorMessage );
	}
}
```

- [ ] **Step 5: Run SyncServiceTest and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter SyncServiceTest
```

Expected: FAIL because imported events still have `urlAcquisto=NULL`, existing null values are not backfilled, path changes do not increment `eventi_updated`, and invalid purchase-only paths are not yet validated. The manual-ownership assertion already remains green.

- [ ] **Step 6: Propagate the envelope base and generate the event URL**

In `sync_title()`, preserve the manual-title early return, then extract and pass the base:

```php
$existing = $this->titles->findByRemoteId( $remote );
if ( null !== $existing && 'manual' === $existing->source ) {
	return;
}
$host = $this->required_string( $envelope, 'host' );
$path = $this->required_string( $envelope, 'path' );
$title = null !== $existing ? $existing : new Titolo();
$this->map_title( $title, $data, $host, $path, $frontend, $token );
```

Change the event call to:

```php
$this->sync_event( $event_data, $title_id, $host, $path, $token, $stats );
```

Change the event signature and add URL generation after the existing manual-event early return:

```php
private function sync_event( array $data, int $title_id, string $host, string $path, string $token, array &$stats ): void {
	$remote = $this->positive_int( $data['idevento'] ?? null, 'event' );
	$existing = $this->events->findByRemoteId( $remote );
	if ( null !== $existing && 'manual' === $existing->source ) {
		return;
	}
	$event = null !== $existing ? $existing : new Evento();
	$event->idevento = $remote;
	$event->urlAcquisto = $this->urls->buildAcquisto( $host, $path, $remote );
```

Keep all remaining event mapping, persistence, statistics, sectors, and reconciliation code unchanged.

- [ ] **Step 7: Pass the validated base into poster mapping**

Change `map_title()` to this signature:

```php
private function map_title( Titolo $title, array $data, string $host, string $path, int $frontend, string $token ): void {
```

Replace its poster call with:

```php
$title->locandinaUrl = $this->urls->buildLocandina(
	$host,
	$path,
	$title->idtitolo,
	(int) $title->locandinaFlag
);
```

- [ ] **Step 8: Include the purchase URL in event change detection**

Replace `event_changed()` with:

```php
/** Compare stored and mapped event values before counting an update. */
private function event_changed( Evento $old, Evento $new ): bool {
	return $old->titoloId !== $new->titoloId
		|| $old->urlAcquisto !== $new->urlAcquisto
		|| $old->inizio !== $new->inizio
		|| $old->organizzatoreId !== $new->organizzatoreId
		|| $old->organizzatoreCf !== $new->organizzatoreCf
		|| $old->localeId !== $new->localeId
		|| $old->stato !== $new->stato
		|| $old->otp !== $new->otp
		|| $old->controlloaccessi !== $new->controlloaccessi
		|| $old->mappa !== $new->mappa
		|| 1 !== $old->syncActive;
}
```

- [ ] **Step 9: Run the complete synchronization integration test**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter SyncServiceTest
```

Expected: PASS; the fixture URL is exact, repeat import reports zero event updates, an existing null value is backfilled, manual ownership is preserved, a changed path reports one update, and invalid base data produces full rollback with safe messages.

- [ ] **Step 10: Commit transactional URL generation**

```bash
rtk git add -- includes/Services/SyncService.php tests/Integration/SyncServiceTest.php
rtk git commit -m "feat: generate imported event purchase URLs"
```

---

### Task 5: Preserve Purchase URLs Through Admin Event Edits

**Files:**
- Modify: `tests/Integration/TitoloEditPageTest.php:382-449`
- Modify: `includes/Admin/Pages/TitoloEditPage.php:692-725`

**Interfaces:**
- Consumes: `Evento::$urlAcquisto` and repository persistence from Task 2.
- Produces: Existing API and future manually populated purchase URLs survive admin edits while the field remains absent from the form.
- Preserves: New manual events have `idevento=NULL`, `urlAcquisto=NULL`, `source=manual`, `syncActive=1`, and `lastSeenSync=NULL`.

- [ ] **Step 1: Strengthen the existing admin ownership tests**

In `test_new_event_gets_source_manual()`, add:

```php
self::assertNull( $events[0]->urlAcquisto );
```

Rename `test_edit_api_event_keeps_source_api()` to `test_edit_api_event_keeps_source_identity_and_purchase_url()`.

Replace its event setup with:

```php
$event = $this->event( 999, $title_id, $venue, 'api' );
$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/999/acquista';
$event_id = $this->events->save( $event );
```

Add after its remote-ID assertion:

```php
self::assertSame(
	'https://ticket.cinebot.it/martinovich/evento/999/acquista',
	$events[0]->urlAcquisto
);
```

- [ ] **Step 2: Run the focused admin test and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "test_new_event_gets_source_manual|test_edit_api_event_keeps_source_identity_and_purchase_url"
```

Expected: FAIL because `build_events()` creates a fresh DTO and `persist_hierarchy()` does not copy the stored purchase URL before repository update.

- [ ] **Step 3: Preserve the stored URL for existing events and make the manual default explicit**

In the existing-event branch of `persist_hierarchy()`, add the purchase URL copy with the other stored ownership fields:

```php
$stored                = $existing_events[ $event_id ];
$event->source         = $stored->source;
$event->idevento       = $stored->idevento;
$event->urlAcquisto    = $stored->urlAcquisto;
$event->syncActive     = $stored->syncActive;
$event->lastSeenSync   = $stored->lastSeenSync;
```

In the new-event branch, set the field explicitly:

```php
$event->source         = 'manual';
$event->idevento       = null;
$event->urlAcquisto    = null;
$event->syncActive     = 1;
$event->lastSeenSync   = null;
```

Do not add hidden fields, URL inputs, labels, read-only output, JavaScript templates, or sanitization for `url_acquisto`.

- [ ] **Step 4: Run the complete title editor integration test**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter TitoloEditPageTest
```

Expected: PASS; existing API identity and URL survive, new manual events remain null, and all hierarchy ownership/security tests stay green.

- [ ] **Step 5: Commit admin preservation**

```bash
rtk git add -- includes/Admin/Pages/TitoloEditPage.php tests/Integration/TitoloEditPageTest.php
rtk git commit -m "fix: preserve event purchase URLs in admin edits"
```

---

## Final Verification

- [ ] **Step 1: Run every focused feature test together**

```bash
rtk docker compose run --rm php composer test:unit -- --filter "CinebotUrlServiceTest|ModelsTest"
rtk docker compose run --rm php composer test:integration -- --filter "SchemaInstallerTest|PluginBootstrapTest|PluginIntegrationTest|ScheduleRepositoryTest|SyncServiceTest|TitoloEditPageTest"
```

Expected: PASS with no failures, errors, warnings, or risky tests introduced by this feature.

- [ ] **Step 2: Run the complete project quality gate**

```bash
rtk docker compose run --rm php composer check
```

Expected: WPCS passes, PHPStan passes, the complete unit/integration suite passes, and `dist/cinebot-wp.zip` builds successfully without development-only files.

- [ ] **Step 3: Inspect only the feature diff and repository status**

```bash
rtk git diff HEAD~5..HEAD -- includes/Services/CinebotUrlService.php includes/Services/SyncService.php includes/Models/Evento.php includes/Repositories/EventoRepository.php includes/Database/SchemaInstaller.php includes/Plugin.php includes/Admin/Pages/TitoloEditPage.php tests/Unit/CinebotUrlServiceTest.php tests/Unit/ModelsTest.php tests/Integration/ScheduleRepositoryTest.php tests/Integration/SchemaInstallerTest.php tests/Integration/PluginBootstrapTest.php tests/Integration/SyncServiceTest.php tests/Integration/TitoloEditPageTest.php
rtk git status --short
```

Expected: the reviewed commit range contains only the planned feature files; pre-existing unrelated worktree changes remain unstaged and untouched.

- [ ] **Step 4: Perform the acceptance check against the imported fixture**

Confirm from `SyncServiceTest` and repository output that remote event `2920` stores exactly:

```text
https://ticket.cinebot.it/martinovich/evento/2920/acquista
```

Confirm no `url_acquisto` field appears in `ProgrammazioneCard`, templates, shortcodes, or admin form markup.
