<?php
/**
 * Schedule synchronization integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Fixtures use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SyncService;
use WP_UnitTestCase;
use wpdb;

/** Verifies a complete transactional import and its visible state. */
final class SyncServiceTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** Install a clean schedule schema for every test. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		self::$db = $wpdb;
		( new SchemaInstaller( self::$db ) )->install();
		foreach ( array( 'prezzi', 'settori', 'eventi', 'titoli', 'locali', 'sync_log' ) as $suffix ) {
			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
		delete_option( 'cinebot_wp_sync_lock' );
	}

	/** Imports all hierarchy fields, poster data, counters, and a success log. */
	public function test_imports_complete_fixture_and_records_success(): void {
		$result = $this->service()->syncPayload( $this->fixture() );

		self::assertTrue( $result->isSuccess() );
		self::assertSame(
			array( 'titoli_added' => 1, 'titoli_updated' => 0, 'eventi_added' => 1, 'eventi_updated' => 0 ),
			$result->stats()
		);
		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
		self::assertSame( 'DONNE & UOMINI', $title->titolo );
		self::assertSame( 'api', $title->source );
		self::assertSame( 77, $title->frontendId );
		self::assertSame( 'https://ticket.cinebot.it/martinovich/titolo/491/locandina', $title->locandinaUrl );
		self::assertSame( 'api', $event->source );
		self::assertSame( 3, $event->stato );
		self::assertSame( 'Teatro Comunale', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
		$sector = ( new SettoreRepository( self::$db ) )->findByEventoId( $event->id )[0];
		self::assertSame( 'Platea', $sector->nome );
		self::assertSame( '20.00', ( new PrezzoRepository( self::$db ) )->findBySettoreId( $sector->id )[0]->importo );
		self::assertSame( 'success', ( new SyncLogRepository( self::$db ) )->latest()->status );
	}

	/** Re-importing canonical-equivalent input is idempotent and tracks no title update. */
	public function test_identical_payload_is_idempotent_and_hash_is_key_order_invariant(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0] = array_reverse( $payload['programmazione'][0]['titoli'][0], true );
		$result = $this->service()->syncPayload( $payload );

		self::assertTrue( $result->isSuccess() );
		self::assertSame( 0, $result->stats()['titoli_updated'] );
		self::assertSame( 1, ( new TitoloRepository( self::$db ) )->count() );
	}

	/** Invalid input creates no hierarchy rows and reports a safe error. */
	public function test_malformed_payload_rolls_back_without_leaking_payload_data(): void {
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;
		$result = $this->service()->syncPayload( $payload );

		self::assertFalse( $result->isSuccess() );
		self::assertSame( 'error', $result->status() );
		self::assertStringNotContainsString( 'idevento', $result->message() );
		self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
		self::assertSame( 'error', ( new SyncLogRepository( self::$db ) )->latest()->status );
	}

	/** Build the service with its concrete repository collaborators. */
	private function service(): SyncService {
		return new SyncService(
			self::$db,
			null,
			new TitoloRepository( self::$db ),
			new EventoRepository( self::$db ),
			new SettoreRepository( self::$db ),
			new PrezzoRepository( self::$db ),
			new LocaleRepository( self::$db ),
			new SyncLogRepository( self::$db )
		);
	}

	/** Load the approved static fixture. */
	private function fixture(): array {
		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/cinebot-sample.json' ), true );
		self::assertIsArray( $decoded );
		return $decoded;
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
