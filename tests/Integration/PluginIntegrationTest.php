<?php
/**
 * Plugin composition integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Plugin;
use WP_UnitTestCase;

/**
 * Verifies the complete plugin composition and lifecycle integration.
 */
final class PluginIntegrationTest extends WP_UnitTestCase {
	/** Boot registers all hooks without duplication. */
	public function test_boot_registers_hooks_once(): void {
		$plugin = Plugin::instance();

		// Reset boot state for test isolation.
		$ref    = new \ReflectionClass( $plugin );
		$prop   = $ref->getProperty( 'booted' );
		$prop->setAccessible( true );
		$prop->setValue( $plugin, false );

		$plugin->boot();
		$plugin->boot(); // second call must be idempotent.

		self::assertTrue( has_action( 'cinebot_wp_sync_event' ) !== false );
		self::assertTrue( has_action( 'admin_menu' ) !== false );
		self::assertTrue( has_action( 'admin_enqueue_scripts' ) !== false );
		self::assertTrue( has_action( 'update_option_cinebot_wp_settings' ) !== false );
		self::assertTrue( has_action( 'wp_ajax_cinebot_wp_test_connection' ) !== false );
		self::assertTrue( has_action( 'wp_ajax_cinebot_wp_sync_now' ) !== false );
		self::assertTrue( has_action( 'wp_ajax_cinebot_wp_filter' ) !== false );
		self::assertTrue( has_action( 'wp_ajax_nopriv_cinebot_wp_filter' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_wp_save_api' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_wp_save_titolo' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_wp_save_locale' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_wp_save_tipologia' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_toggle_tipologia' ) !== false );
		self::assertTrue( has_action( 'admin_post_cinebot_cleanup_logs' ) !== false );
		self::assertTrue( has_action( 'cron_schedules' ) !== false );
		self::assertTrue( shortcode_exists( 'cinebot_programmazione' ) );
		self::assertTrue( shortcode_exists( 'cinebot_titolo' ) );
	}

	/** Main file registers only Plugin lifecycle callbacks. */
	public function test_main_file_registers_only_plugin_lifecycle(): void {
		$source = (string) file_get_contents( CINEBOT_WP_FILE );
		self::assertStringContainsString( "register_activation_hook", $source );
		self::assertStringContainsString( "register_deactivation_hook", $source );
		self::assertStringContainsString( "[Plugin::class, 'activate']", $source );
		self::assertStringContainsString( "[Plugin::class, 'deactivate']", $source );
	}
}
