<?php
/**
 * Dashboard and Sync Log monitoring integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Admin\Pages\SyncLogPage;
use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SettingsService;
use WP_UnitTestCase;

/**
 * Tests the Dashboard page with counters and the SyncLog page with filters.
 */
final class MonitoringAdminPagesTest extends WP_UnitTestCase {
	/** @var DashboardPage */
	private $dashboard;

	/** @var SyncLogPage */
	private $log_page;

	/** @var SyncLogRepository */
	private $logs;

	/** Set up pages with repositories. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;

		$settings    = new SettingsService();
		$titles      = new TitoloRepository( $wpdb );
		$this->logs  = new SyncLogRepository( $wpdb );

		$this->dashboard = new DashboardPage( $settings, $titles, $this->logs );
		$this->log_page  = new SyncLogPage( $this->logs );

		wp_set_current_user( 1 );
	}

	/** Dashboard renders sync status. */
	public function test_dashboard_renders_sync_status(): void {
		ob_start();
		$this->dashboard->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Cinebot Dashboard', $output );
		self::assertStringContainsString( 'Synchronization', $output );
		self::assertStringContainsString( 'Frequency', $output );
		self::assertStringContainsString( 'Next sync', $output );
	}

	/** Dashboard renders counters. */
	public function test_dashboard_renders_counters(): void {
		ob_start();
		$this->dashboard->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Conteggi rapidi', $output );
		self::assertStringContainsString( 'Titoli', $output );
		self::assertStringContainsString( 'Manuali', $output );
		self::assertStringContainsString( 'Eventi', $output );
		self::assertStringContainsString( 'Locali', $output );
		self::assertStringContainsString( 'Tipologie attive', $output );
	}

	/** Dashboard renders quick links. */
	public function test_dashboard_renders_quick_links(): void {
		ob_start();
		$this->dashboard->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'API Settings', $output );
		self::assertStringContainsString( 'Programmazioni', $output );
		self::assertStringContainsString( 'Locali', $output );
		self::assertStringContainsString( 'Tipologie', $output );
	}

	/** Dashboard renders recent logs section. */
	public function test_dashboard_renders_recent_logs(): void {
		ob_start();
		$this->dashboard->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Ultime sincronizzazioni', $output );
		self::assertStringContainsString( 'Nessuna sincronizzazione registrata', $output );
	}

	/** Dashboard shows log entry after sync. */
	public function test_dashboard_shows_log_after_sync(): void {
		$this->logs->start( 'test-hash' );
		$this->logs->finish( 1, 'success', array(
			'titoli_added'   => 5,
			'titoli_updated' => 2,
			'eventi_added'   => 10,
			'eventi_updated' => 3,
		) );

		ob_start();
		$this->dashboard->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'success', $output );
		self::assertStringNotContainsString( 'Nessuna sincronizzazione registrata', $output );
	}

	/** SyncLog page renders list table. */
	public function test_log_page_renders_table(): void {
		ob_start();
		$this->log_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Log sincronizzazioni', $output );
		self::assertStringContainsString( 'Pulisci log', $output );
		self::assertStringContainsString( 'Started', $output );
		self::assertStringContainsString( 'Status', $output );
	}

	/** SyncLog page renders status filter. */
	public function test_log_page_renders_status_filter(): void {
		ob_start();
		$this->log_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'name="status"', $output );
		self::assertStringContainsString( 'Success', $output );
		self::assertStringContainsString( 'Error', $output );
	}

	/** SyncLog page shows log entries. */
	public function test_log_page_shows_entries(): void {
		$log_id = $this->logs->start( 'test-hash' );
		$this->logs->finish( $log_id, 'error', array(), 'Test error message' );

		ob_start();
		$this->log_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'error', $output );
		self::assertStringContainsString( 'Test error message', $output );
	}

	/** SyncLog page cleanup button deletes old entries. */
	public function test_cleanup_deletes_old_entries(): void {
		// Insert an old log entry by manipulating started_at.
		global $wpdb;

		$log_id = $this->logs->start( 'old-hash' );
		$this->logs->finish( $log_id, 'success', array() );

		// Set started_at to 40 days ago.
		$old_date = gmdate( 'Y-m-d H:i:s', time() - ( 40 * 86400 ) );
		$wpdb->update(
			$wpdb->prefix . 'cinebot_sync_log',
			array( 'started_at' => $old_date ),
			array( 'id' => $log_id ),
			array( '%s' ),
			array( '%d' )
		);

		// Insert a recent entry that should survive.
		$recent_id = $this->logs->start( 'recent-hash' );
		$this->logs->finish( $recent_id, 'success', array() );

		// Simulate the cleanup action.
		$_GET = array(
			'action' => 'cinebot_cleanup_logs',
			'_wpnonce' => wp_create_nonce( 'cinebot_cleanup_logs' ),
		);

		try {
			$this->log_page->deleteOld();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$old = $this->logs->search( array(), 1, 50 );
		$ids = array_map( static function ( $log ) {
			return (int) $log->id;
		}, $old );

		self::assertNotContains( $log_id, $ids );
		self::assertContains( $recent_id, $ids );
	}

	/** SyncLog page cleanup rejects missing nonce. */
	public function test_cleanup_rejects_missing_nonce(): void {
		$this->expectException( \WPDieException::class );

		$_GET = array( 'action' => 'cinebot_cleanup_logs' );
		$this->log_page->deleteOld();
	}

	/** SyncLog page cleanup rejects non-admin. */
	public function test_cleanup_rejects_non_admin(): void {
		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );

		$_GET = array(
			'action' => 'cinebot_cleanup_logs',
			'_wpnonce' => wp_create_nonce( 'cinebot_cleanup_logs' ),
		);
		$this->log_page->deleteOld();
	}
}
