<?php
/**
 * End-to-end regression test.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Fixtures use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Frontend\ShortcodeHandler;
use CinebotWp\Frontend\TemplateRenderer;
use CinebotWp\Plugin;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use WP_UnitTestCase;

/**
 * Verifies the complete plugin lifecycle: activation, settings, sync, shortcode, reconciliation, deactivation.
 */
final class CinebotEndToEndTest extends WP_UnitTestCase {
	/** Start each lifecycle test without rows left by other integration tests. */
	public function set_up(): void {
		parent::set_up();
		Plugin::activate();
		$this->clear_schedule_rows();
	}

	/** Remove lifecycle rows so later test classes start clean. */
	public function tear_down(): void {
		Plugin::deactivate();
		$this->clear_schedule_rows();
		delete_option( 'cinebot_wp_settings' );
		delete_option( 'cinebot_wp_encryption_salt' );
		parent::tear_down();
	}

	/** Full lifecycle: activate, configure, sync, render, deactivate. */
	public function test_full_lifecycle(): void {
		// 1. Activate: schema + 62 types installed.
		Plugin::activate();
		global $wpdb;

		$suffixes = array( 'titoli', 'eventi', 'locali', 'tipologie_eventi', 'sync_log' );
		foreach ( $suffixes as $suffix ) {
			$table = $wpdb->prefix . 'cinebot_' . $suffix;
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}

		$types = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_tipologie_eventi" );
		self::assertSame( '62', (string) $types );
		foreach ( array( 'eventi', 'titoli', 'locali', 'sync_log' ) as $suffix ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'cinebot_' . $suffix );
		}

		// 2. Save API settings.
		$settings = new SettingsService();
		$settings->save( array(
			'api_username'   => 'testuser',
			'api_password'   => 'testpass',
			'api_frontend'   => '50',
			'sync_frequency' => 'daily',
			'sync_enabled'   => '1',
		) );

		self::assertTrue( $settings->enabled() );
		self::assertSame( 'testuser', $settings->username() );
		self::assertSame( 50, $settings->frontend() );

		// 3. Import sample fixture via syncPayload.
		$payload = $this->load_fixture();
		$sync    = new SyncService( $wpdb );
		$result  = $sync->syncPayload( $payload );

		self::assertTrue( $result->isSuccess(), 'Sync should succeed: ' . $result->message() );
		self::assertGreaterThan( 0, $result->stats()['titoli_added'], 'Should have added titles' );

		// 4. Assert Dashboard counters reflect imported data.
		$titles  = new TitoloRepository( $wpdb );
		$stats   = $titles->statistics();

		self::assertGreaterThan( 0, $stats['titoli_totali'], 'Dashboard should show imported titles' );
		self::assertGreaterThan( 0, $stats['eventi_totali'], 'Dashboard should show imported events' );

		// 5. Render shortcode with data.
		$handler = new ShortcodeHandler( $titles, new TemplateRenderer(), new EventoRepository( $wpdb ) );
		$handler->register();

		$html = do_shortcode( '[cinebot_programmazione tipo="45" comune="Bassano del Grappa"]' );

		self::assertStringContainsString( 'cinebot-programmazione', $html );
		self::assertStringContainsString( 'cinebot-card', $html );

		// 6. Assert imported title appears in shortcode output.
		$first_title = $wpdb->get_var(
			"SELECT titolo FROM {$wpdb->prefix}cinebot_titoli WHERE source = 'api' LIMIT 1"
		);
		if ( null !== $first_title ) {
			self::assertStringContainsString( esc_html( $first_title ), $html, 'Imported title should appear in shortcode output' );
		}

		// 7. Create a manual title, re-import, assert it remains unchanged.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'      => 'Manual E2E Title',
				'source'      => 'manual',
				'sync_active' => 1,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);
		$manual_id = (int) $wpdb->insert_id;

		// Re-import.
		$result2 = $sync->syncPayload( $payload );
		self::assertTrue( $result2->isSuccess(), 'Second sync should succeed' );

		$manual = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cinebot_titoli WHERE id = %d", $manual_id ),
			ARRAY_A
		);

		self::assertNotNull( $manual );
		self::assertSame( 'manual', $manual['source'] );
		self::assertSame( 'Manual E2E Title', $manual['titolo'] );

		// 8. Deactivate and assert data remains while cron is removed.
		Plugin::deactivate();
		self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );

		foreach ( $suffixes as $suffix ) {
			$table = $wpdb->prefix . 'cinebot_' . $suffix;
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	/** Reconciliation: API event removed from payload gets deactivated, not deleted; reactivated on return. */
	public function test_reconciliation_deactivates_and_reactivates(): void {
		Plugin::activate();
		global $wpdb;

		// Import full payload.
		$payload = $this->load_fixture();
		$sync    = new SyncService( $wpdb );
		$sync->syncPayload( $payload );

		// Count events before.
		$events_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_eventi WHERE source = 'api'" );
		self::assertGreaterThan( 0, $events_before );

		// Import empty payload — all API events should be deactivated.
		$sync->syncPayload(
			array(
				'programmazione' => array(
					array(
						'frontend' => 50,
						'titoli'   => array(),
					),
				),
			)
		);

		$active_events = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_eventi WHERE source = %s AND sync_active = %d",
				'api',
				1
			)
		);
		self::assertSame( 0, $active_events, 'All API events should be deactivated after empty sync' );

		// Events still exist (not deleted).
		$total_events = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_eventi WHERE source = 'api'" );
		self::assertSame( $events_before, $total_events, 'Deactivated events should still exist in database' );

		// Re-import original payload — events should reactivate.
		$sync->syncPayload( $payload );

		$active_after = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_eventi WHERE source = %s AND sync_active = %d",
				'api',
				1
			)
		);
		self::assertSame( $events_before, $active_after, 'Events should reactivate on re-import' );

		Plugin::deactivate();
	}

	/** Events with stato != 3 are never public. */
	public function test_non_state_3_events_not_public(): void {
		Plugin::activate();
		global $wpdb;

		// Seed a future event with stato = 2.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_locali',
			array(
				'nome'       => 'Test Venue',
				'comune'     => 'Test City',
				'source'     => 'manual',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$venue_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'            => 'Non-State-3 Show',
				'tipoevento_codice' => '45',
				'source'            => 'manual',
				'sync_active'       => 1,
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			)
		);
		$title_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'cinebot_eventi',
			array(
				'titolo_id'   => $title_id,
				'inizio'      => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
				'locale_id'   => $venue_id,
				'stato'       => 2,
				'source'      => 'manual',
				'sync_active' => 1,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		$handler = new ShortcodeHandler(
			new TitoloRepository( $wpdb ),
			new TemplateRenderer(),
			new EventoRepository( $wpdb )
		);
		$handler->register();

		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringNotContainsString( 'Non-State-3 Show', $html, 'stato=2 event should not be public' );

		Plugin::deactivate();
	}

	/** Clear current schedule rows in child-first order. */
	private function clear_schedule_rows(): void {
		global $wpdb;
		foreach ( array( 'eventi', 'titoli', 'locali', 'sync_log' ) as $suffix ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'cinebot_' . $suffix );
		}
		delete_option( 'cinebot_wp_sync_lock' );
	}

	/** Manual title remains unchanged after re-import. */
	public function test_manual_title_preserved_on_sync(): void {
		Plugin::activate();
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'      => 'Manual Test Title',
				'source'      => 'manual',
				'sync_active' => 1,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);
		$manual_id = (int) $wpdb->insert_id;

		$sync   = new SyncService( $wpdb );
		$result = $sync->syncPayload( array( 'programmazione' => array() ) );

		$manual = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}cinebot_titoli WHERE id = %d", $manual_id ),
			ARRAY_A
		);

		self::assertNotNull( $manual );
		self::assertSame( 'manual', $manual['source'] );
		self::assertSame( 'Manual Test Title', $manual['titolo'] );

		Plugin::deactivate();
	}

	/** Load the approved fixture JSON. */
	private function load_fixture(): array {
		$path = CINEBOT_WP_PATH . 'tests/fixtures/cinebot-sample.json';
		$json = (string) file_get_contents( $path );

		return (array) json_decode( $json, true );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
