<?php
/**
 * End-to-end regression test.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Plugin;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use WP_UnitTestCase;

/**
 * Verifies the complete plugin lifecycle: activation, settings, sync, shortcode, deactivation.
 */
final class CinebotEndToEndTest extends WP_UnitTestCase {
	/** Full lifecycle: activate, configure, sync, render, deactivate. */
	public function test_full_lifecycle(): void {
		// 1. Activate: schema + 62 types installed.
		Plugin::activate();
		global $wpdb;

		$suffixes = array( 'titoli', 'eventi', 'settori', 'prezzi', 'locali', 'tipologie_eventi', 'sync_log' );
		foreach ( $suffixes as $suffix ) {
			$table = $wpdb->prefix . 'cinebot_' . $suffix;
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}

		$types = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}cinebot_tipologie_eventi" );
		self::assertSame( '62', (string) $types );

		// 2. Save API settings.
		$settings = new SettingsService();
		$settings->save( array(
			'api_username'  => 'testuser',
			'api_password'  => 'testpass',
			'api_frontend'  => '50',
			'sync_frequency' => 'daily',
			'sync_enabled'  => '1',
		) );

		self::assertTrue( $settings->enabled() );
		self::assertSame( 'testuser', $settings->username() );
		self::assertSame( 50, $settings->frontend() );

		// 3. Render shortcode returns HTML (even with no data).
		$html = do_shortcode( '[cinebot_programmazione tipo="45" comune="Bassano del Grappa"]' );
		self::assertStringContainsString( 'cinebot-programmazione', $html );

		// 4. Deactivate: data remains, cron removed.
		Plugin::deactivate();
		self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );

		// Tables still exist after deactivation.
		foreach ( $suffixes as $suffix ) {
			$table = $wpdb->prefix . 'cinebot_' . $suffix;
			self::assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
		}
	}

	/** Manual title remains unchanged after re-import. */
	public function test_manual_title_preserved_on_sync(): void {
		Plugin::activate();
		global $wpdb;

		// Insert a manual title directly.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'       => 'Manual Test Title',
				'source'       => 'manual',
				'sync_active'  => 1,
				'created_at'   => current_time( 'mysql', true ),
				'updated_at'   => current_time( 'mysql', true ),
			)
		);
		$manual_id = (int) $wpdb->insert_id;

		// SyncService::syncPayload with empty programmazione should not touch manual rows.
		$sync = new SyncService( $wpdb );
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
}
