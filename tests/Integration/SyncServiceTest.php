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
use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\ApiClient;
use CinebotWp\Services\SettingsService;
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
		foreach ( array( 'eventi', 'titoli', 'locali', 'sync_log' ) as $suffix ) {
			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
		delete_option( 'cinebot_wp_sync_lock' );
	}

	/** Imports every authoritative hierarchy row, mapped source fields, and success log. */
	public function test_imports_complete_fixture_and_records_success(): void {
		$result = $this->service()->syncPayload( $this->fixture() );

		self::assertTrue( $result->isSuccess() );
		self::assertSame(
			array( 'titoli_added' => 17, 'titoli_updated' => 0, 'eventi_added' => 19, 'eventi_updated' => 0 ),
			$result->stats()
		);
		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
		self::assertSame( 'DONNE & UOMINI', $title->titolo );
		self::assertSame( 'api', $title->source );
		self::assertSame( 50, $title->frontendId );
		self::assertSame( 'https://ticket.cinebot.it/martinovich/titolo/491/locandina', $title->locandinaUrl );
		self::assertSame( 'api', $event->source );
		self::assertSame(
			'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
			$event->urlAcquisto
		);
		self::assertSame( 3, $event->stato );
		self::assertSame( 'Cinema Martinovich', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
		self::assertSame( '22.00', $title->prezzoDa );
		self::assertSame( '22.00', $title->prezzoA );
		self::assertSame( 'success', ( new SyncLogRepository( self::$db ) )->latest()->status );
	}

	/** Re-importing canonical-equivalent input is idempotent and tracks no title update. */
	public function test_identical_payload_is_idempotent_and_hash_is_key_order_invariant(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$first_hash = ( new SyncLogRepository( self::$db ) )->latest()->payloadHash;
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0] = array_reverse( $payload['programmazione'][0]['titoli'][0], true );
		$result = $this->service()->syncPayload( $payload );

		self::assertTrue( $result->isSuccess() );
		self::assertSame( 0, $result->stats()['titoli_updated'] );
		self::assertSame( 0, $result->stats()['eventi_updated'] );
		self::assertSame( 17, ( new TitoloRepository( self::$db ) )->count() );
		self::assertSame( $first_hash, ( new SyncLogRepository( self::$db ) )->latest()->payloadHash );
	}

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

	/** Top-level validation fails safely before creating a synchronization log. */
	public function test_invalid_top_level_payload_returns_safe_error_without_log(): void {
		foreach ( array( array( 'programmazione' => 'invalid' ), array( 'programmazione' => array( 'invalid-envelope' ) ) ) as $payload ) {
			$result = $this->service()->syncPayload( $payload );

			self::assertSame( 'error', $result->status() );
			self::assertSame( 'Schedule synchronization failed.', $result->message() );
			self::assertNull( ( new SyncLogRepository( self::$db ) )->latest() );
		}
	}

	/** Changed API-owned title and event fields update exactly once. */
	public function test_changed_api_rows_update_and_increment_stats(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0]['titolo'] = 'Updated API title';
		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['stato'] = 2;

		$result = $this->service()->syncPayload( $payload );
		self::assertSame( 1, $result->stats()['titoli_updated'] );
		self::assertSame( 1, $result->stats()['eventi_updated'] );
		self::assertSame( 'Updated API title', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->titolo );
		self::assertSame( 2, ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 )->stato );
	}

	/** API synchronization never overwrites a matching manual title or venue. */
	public function test_manual_title_and_venue_remain_untouched(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'source' => 'manual', 'titolo' => 'Manual title' ), array( 'id' => $title->id ) );
		self::$db->update( self::$db->prefix . 'cinebot_locali', array( 'source' => 'manual', 'nome' => 'Manual venue' ), array( 'id' => $event->localeId ) );

		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		self::assertSame( 'Manual title', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->titolo );
		self::assertSame( 'Manual venue', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
	}

	/** A manual venue remains unchanged while its API-owned title reaches venue upsert. */
	public function test_manual_venue_remains_untouched_for_api_owned_title(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
		self::$db->update( self::$db->prefix . 'cinebot_locali', array( 'source' => 'manual', 'nome' => 'Manual venue' ), array( 'id' => $event->localeId ) );
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['locale'] = 'Changed API venue';

		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		self::assertSame( 'api', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->source );
		self::assertSame( 'Manual venue', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
	}

	/** Missing optional title and hierarchy arrays are accepted as empty arrays. */
	public function test_missing_optional_arrays_are_treated_as_empty(): void {
		$payload = $this->fixture();
		$title = &$payload['programmazione'][0]['titoli'][0];
		unset( $title['tag'], $title['cast'], $title['eventi'] );
		unset( $payload['programmazione'][0]['titoli'][1]['eventi'][0]['settori'] );
		unset( $payload['programmazione'][0]['titoli'][2]['eventi'][0]['settori'][0]['prezzi'] );
		unset( $title );

		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		self::assertSame( array(), ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->tag );
	}

	/** Price ranges follow the payload while events and titles retain reconciliation IDs. */
	public function test_price_range_and_reconciliation_follow_payload(): void {
		$payload = $this->fixture();
		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		$title_repo = new TitoloRepository( self::$db );
		$event_repo = new EventoRepository( self::$db );
		$title = $title_repo->findByRemoteId( 491 );
		$event = $event_repo->findByRemoteId( 2920 );

		$without_price = $payload;
		$without_price['programmazione'][0]['titoli'][0]['eventi'][0]['settori'][0]['prezzi'] = array();
		self::assertTrue( $this->service()->syncPayload( $without_price )->isSuccess() );
		self::assertNull( $title_repo->findByRemoteId( 491 )->prezzoDa );
		self::assertNull( $title_repo->findByRemoteId( 491 )->prezzoA );
		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		self::assertSame( '22.00', $title_repo->findByRemoteId( 491 )->prezzoDa );

		$without_sector = $payload;
		$without_sector['programmazione'][0]['titoli'][0]['eventi'][0]['settori'] = array();
		self::assertTrue( $this->service()->syncPayload( $without_sector )->isSuccess() );
		self::assertNull( $title_repo->findByRemoteId( 491 )->prezzoDa );

		$without_event = $payload;
		$without_event['programmazione'][0]['titoli'][0]['eventi'] = array();
		self::assertTrue( $this->service()->syncPayload( $without_event )->isSuccess() );
		self::assertSame( 0, $event_repo->findByRemoteId( 2920 )->syncActive );
		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		self::assertSame( $event->id, $event_repo->findByRemoteId( 2920 )->id );

		$without_title = $payload;
		array_shift( $without_title['programmazione'][0]['titoli'] );
		self::assertTrue( $this->service()->syncPayload( $without_title )->isSuccess() );
		self::assertSame( 0, $title_repo->findByRemoteId( 491 )->syncActive );
		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
		self::assertSame( $title->id, $title_repo->findByRemoteId( 491 )->id );
	}

	/** A payload for another frontend cannot deactivate the first frontend's titles. */
	public function test_frontend_reconciliation_is_isolated(): void {
		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		$other = $this->fixture();
		$other['programmazione'][0]['frontend'] = 51;
		$other['programmazione'][0]['titoli'] = array( $other['programmazione'][0]['titoli'][0] );
		$other['programmazione'][0]['titoli'][0]['idtitolo'] = 900491;
		$other['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 902920;
		$other['programmazione'][0]['titoli'][0]['eventi'][0]['localeId'] = 900001;

		self::assertTrue( $this->service()->syncPayload( $other )->isSuccess() );
		self::assertSame( 1, ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->syncActive );
	}

	/** Cache options survive a failed transaction and are removed only after commit. */
	public function test_cache_is_deleted_only_after_successful_commit(): void {
		set_transient( 'cinebot_prog_test', 'cached', HOUR_IN_SECONDS );
		$invalid = $this->fixture();
		$invalid['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;
		self::assertSame( 'error', $this->service()->syncPayload( $invalid )->status() );
		self::assertSame( 'cached', get_transient( 'cinebot_prog_test' ) );

		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
		self::assertFalse( get_transient( 'cinebot_prog_test' ) );
	}

	/** Lock contention returns before a real API client's transport is invoked. */
	public function test_lock_contention_returns_locked_without_transport_call(): void {
		$lock = new \CinebotWp\Services\SyncLock( self::$db );
		$token = $lock->acquire();
		$calls = 0;
		$client = new ApiClient(
			new SettingsService(),
			static function ( string $url, array $args ) use ( &$calls ): array {
				++$calls;
				return array();
			}
		);
		$result = ( new SyncService( self::$db, $client ) )->sync();
		self::assertSame( 'locked', $result->status() );
		self::assertSame( 0, $calls );
		self::assertTrue( $lock->release( $token ) );
	}

	/** An event persistence failure rolls back earlier title and venue writes. */
	public function test_event_insert_failure_rolls_back_partial_hierarchy_and_logs_safely(): void {
		self::$db->query( 'COMMIT' );
		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public function query( $query ) {
				if ( is_string( $query ) && false !== strpos( $query, 'cinebot_eventi' ) && 1 === preg_match( '/^INSERT /i', $query ) ) {
					return false;
				}
				return parent::query( $query );
			}
		};
		$failing_db->set_prefix( self::$db->prefix );

		try {
			$result = $this->service( $failing_db )->syncPayload( $this->fixture() );
			self::assertSame( 'error', $result->status() );
			self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
			self::assertNull( ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 ) );
			self::assertSame( 0, ( new LocaleRepository( self::$db ) )->count() );
			$log = ( new SyncLogRepository( $failing_db ) )->latest();
			self::assertSame( 'error', $log->status );
			self::assertSame( 'Schedule synchronization failed.', $log->errorMessage );
		} finally {
			$failing_db->close();
		}
	}

	/** A reported rollback failure remains safe and is recorded without database detail. */
	public function test_rollback_query_failure_is_recorded_as_safe_error(): void {
		self::$db->query( 'COMMIT' );
		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public function query( $query ) {
				$result = parent::query( $query );
				return 'ROLLBACK' === $query ? false : $result;
			}
		};
		$failing_db->set_prefix( self::$db->prefix );
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;

		try {
			$result = $this->service( $failing_db )->syncPayload( $payload );
			self::assertSame( 'error', $result->status() );
			self::assertSame( 'Schedule synchronization failed.', $result->message() );
			$log = ( new SyncLogRepository( $failing_db ) )->latest();
			self::assertSame( 'error', $log->status );
			self::assertSame( 'Schedule synchronization rollback could not be confirmed.', $log->errorMessage );
			self::assertStringNotContainsString( 'ROLLBACK', $log->errorMessage );
		} finally {
			$failing_db->close();
		}
	}

	/** Safe result and history errors never expose caller-provided secret content. */
	public function test_error_result_and_log_do_not_expose_payload_secrets(): void {
		$payload = $this->fixture();
		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 'secret-api-password';
		$result = $this->service()->syncPayload( $payload );
		$log = ( new SyncLogRepository( self::$db ) )->latest();

		self::assertStringNotContainsString( 'secret-api-password', $result->message() );
		self::assertStringNotContainsString( 'secret-api-password', $log->errorMessage );
	}

	/** Build the service with its concrete repository collaborators. */
	private function service( ?wpdb $db = null ): SyncService {
		$db = $db ?? self::$db;
		return new SyncService(
			$db,
			null,
			new TitoloRepository( $db ),
			new EventoRepository( $db ),
			new LocaleRepository( $db ),
			new SyncLogRepository( $db )
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
