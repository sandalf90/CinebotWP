<?php
/**
 * API admin page integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Admin\AdminMenu;
use CinebotWp\Admin\Pages\ApiPage;
use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Admin\Pages\TitoloEditPage;
use CinebotWp\Admin\Pages\TitoliListPage;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use WP_UnitTestCase;

/**
 * Tests the API settings admin page, AJAX handlers, and asset enqueuing.
 */
final class ApiAdminPageTest extends WP_UnitTestCase {
	/** @var SettingsService */
	private $settings_service;

	/** @var SyncService */
	private $sync_service;

	/** @var ApiPage */
	private $api_page;

	/** @var DashboardPage */
	private $dashboard_page;

	/** @var TitoliListPage */
	private $titoli_page;

	/** @var TitoloEditPage */
	private $edit_page;

	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** @var TipologiaRepository */
	private $types;

	/** @var LocaleRepository */
	private $venues;

	/** @var SyncLogRepository */
	private $logs;

	/** Set up page collaborators with isolated settings. */
	public function set_up(): void {
		parent::set_up();
		delete_option( 'cinebot_wp_settings' );
		delete_option( 'cinebot_wp_encryption_salt' );

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}
		set_current_screen( 'toplevel_page_cinebot-wp' );

		$this->settings_service = new SettingsService();
		$this->sync_service     = new SyncService( $GLOBALS['wpdb'] );
		global $wpdb;
		$this->titles = new TitoloRepository( $wpdb );
		$this->events = new EventoRepository( $wpdb );
		$this->types  = new TipologiaRepository( $wpdb );
		$this->venues = new LocaleRepository( $wpdb );
		$this->logs   = new SyncLogRepository( $wpdb );

		$this->api_page         = new ApiPage(
			$this->settings_service,
			$this->scheduler(),
			$this->sync_service
		);
		$this->dashboard_page   = new DashboardPage( $this->settings_service, $this->titles, $this->logs );

		$this->titoli_page = new TitoliListPage(
			$this->titles,
			$this->events,
			$this->types
		);

		$this->edit_page = new TitoloEditPage(
			$this->titles,
			$this->events,
			$this->types,
			$this->venues
		);
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/** Restore settings isolation. */
	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
		delete_option( 'cinebot_wp_settings' );
		delete_option( 'cinebot_wp_encryption_salt' );
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/** Stop redirects before WordPress attempts to send test headers. */
	public function intercept_redirect( $location ) {
		throw new \WPDieException( is_string( $location ) ? $location : '' );
	}

	/** Admin menu should register top-level and submenu hooks. */
	public function test_admin_menu_registers_hooks(): void {
		$menu = new AdminMenu(
			$this->dashboard_page,
			$this->api_page,
			$this->titoli_page,
			$this->edit_page,
			new \CinebotWp\Admin\Pages\LocaliListPage( $this->venues, $this->events ),
			new \CinebotWp\Admin\Pages\LocaleEditPage( $this->venues ),
			new \CinebotWp\Admin\Pages\TipologieListPage( $this->types ),
			new \CinebotWp\Admin\Pages\TipologiaEditPage( $this->types ),
			new \CinebotWp\Admin\Pages\SyncLogPage( $this->logs )
		);
		$menu->register();

		self::assertHasAction( 'admin_menu' );
		self::assertHasAction( 'admin_enqueue_scripts' );
		self::assertHasAction( 'admin_post_cinebot_wp_save_api' );
		self::assertHasAction( 'wp_ajax_cinebot_wp_test_connection' );
		self::assertHasAction( 'wp_ajax_cinebot_wp_sync_now' );
	}

	/** Non-admin users are denied save. */
	public function test_save_denies_non_admin(): void {
		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		$this->api_page->save();
	}

	/** Nonce rejection prevents save. */
	public function test_save_rejects_missing_nonce(): void {
		wp_set_current_user( 1 );

		$this->expectException( \WPDieException::class );
		$_POST = array( 'api_username' => 'hacker' );
		$this->api_page->save();
	}

	/** Valid save stores settings and redirects. */
	public function test_valid_save_stores_settings(): void {
		wp_set_current_user( 1 );

		$_POST = array(
			'cinebot_wp_api_nonce' => wp_create_nonce( 'cinebot_wp_save_api' ),
			'action'               => 'cinebot_wp_save_api',
			'api_username'         => 'testuser',
			'api_password'          => 'testpass',
			'api_frontend'         => '50',
			'sync_frequency'       => 'daily',
			'sync_enabled'         => '1',
		);
		$_REQUEST = $_POST;

		try {
			$this->api_page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$saved = $this->settings_service->get();
		self::assertSame( 'testuser', $saved['api_username'] );
		self::assertTrue( $saved['has_password'] );
		self::assertSame( 50, $saved['api_frontend'] );
		self::assertSame( 'daily', $saved['sync_frequency'] );
		self::assertTrue( $saved['sync_enabled'] );
	}

	/** Empty password preserves existing password. */
	public function test_empty_password_preserves_existing(): void {
		$this->settings_service->save( array(
			'api_username' => 'existing',
			'api_password' => 'secret',
		) );

		wp_set_current_user( 1 );

		$_POST = array(
			'cinebot_wp_api_nonce' => wp_create_nonce( 'cinebot_wp_save_api' ),
			'action'               => 'cinebot_wp_save_api',
			'api_username'         => 'existing',
			'api_password'         => '',
			'sync_frequency'       => 'weekly',
		);
		$_REQUEST = $_POST;

		try {
			$this->api_page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$saved = $this->settings_service->get();
		self::assertTrue( $saved['has_password'] );
	}

	/** AJAX test connection rejects missing nonce. */
	public function test_test_connection_rejects_missing_nonce(): void {
		wp_set_current_user( 1 );

		ob_start();
		$this->api_page->testConnection();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertFalse( $json['success'] );
	}

	/** AJAX sync now rejects non-admin. */
	public function test_sync_now_denies_non_admin(): void {
		wp_set_current_user( 0 );

		ob_start();
		$this->api_page->syncNow();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertFalse( $json['success'] );
	}

	/** Dashboard page renders sync status without errors. */
	public function test_dashboard_renders_status(): void {
		wp_set_current_user( 1 );

		ob_start();
		$this->dashboard_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'Cinebot Dashboard', $output );
		self::assertStringContainsString( 'Synchronization', $output );
		self::assertStringContainsString( 'API Settings', $output );
	}

	/** API page form renders password placeholder when password exists. */
	public function test_api_form_shows_password_placeholder(): void {
		$this->settings_service->save( array(
			'api_username' => 'user',
			'api_password' => 'TEST_SECRET_VALUE_42',
		) );

		wp_set_current_user( 1 );

		ob_start();
		$this->api_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'type="password"', $output );
		self::assertStringContainsString( 'leave blank to keep current', $output );
		self::assertStringNotContainsString( 'TEST_SECRET_VALUE_42', $output );
	}

	/** Helper: assert that an action hook is registered. */
	private static function assertHasAction( string $hook ): void {
		self::assertTrue(
			has_action( $hook ) !== false,
			"Action {$hook} is not registered."
		);
	}

	/** Compose a scheduler for page construction. */
	private function scheduler() {
		return new \CinebotWp\Services\CronScheduler(
			$this->settings_service,
			$this->sync_service
		);
	}
}
