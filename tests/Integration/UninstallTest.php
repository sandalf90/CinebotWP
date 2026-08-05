<?php
/**
 * Plugin uninstall integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Integration assertions query trusted, fixed schema identifiers directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies the exact destructive boundary of single-site uninstall.
 */
final class UninstallTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var string[] */
	private const TABLE_SUFFIXES = array(
		'titoli',
		'eventi',
		'settori',
		'prezzi',
		'locali',
		'tipologie_eventi',
		'sync_log',
	);

	/**
	 * Store the WordPress database connection.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		self::$db = $wpdb;
	}

	/**
	 * Verifies uninstall removes approved data and preserves unrelated data.
	 */
	public function test_uninstall_removes_only_approved_single_site_data(): void {
		$installer       = new SchemaInstaller( self::$db );
		$unrelated_table = self::$db->prefix . 'cinebot_unrelated';

		try {
			$installer->install();
			self::$db->query( "CREATE TABLE {$unrelated_table} (id bigint(20) unsigned NOT NULL) ENGINE=InnoDB" );

			update_option( 'cinebot_wp_settings', array( 'remove' => true ) );
			update_option( 'cinebot_wp_db_version', 'remove' );
			update_option( 'cinebot_wp_encryption_salt', 'remove' );
			update_option( 'cinebot_wp_sync_lock', 'remove' );
			update_option( 'cinebot_wp_unrelated', 'preserve' );
			set_transient( 'cinebot_prog_contract', 'remove', HOUR_IN_SECONDS );
			set_transient( 'cinebot_unrelated', 'preserve', HOUR_IN_SECONDS );
			wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
			wp_clear_scheduled_hook( 'cinebot_wp_unrelated_event' );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_sync_event' );
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_unrelated_event' );

			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
				define( 'WP_UNINSTALL_PLUGIN', 'cinebot-wp/cinebot-wp.php' );
			}
			require CINEBOT_WP_PATH . 'uninstall.php';

			foreach ( self::TABLE_SUFFIXES as $suffix ) {
				$table = self::$db->prefix . 'cinebot_' . $suffix;
				self::assertNull( self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
			}

			self::assertSame( $unrelated_table, self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', $unrelated_table ) ) );
			self::assertFalse( get_option( 'cinebot_wp_settings' ) );
			self::assertFalse( get_option( 'cinebot_wp_db_version' ) );
			self::assertFalse( get_option( 'cinebot_wp_encryption_salt' ) );
			self::assertFalse( get_option( 'cinebot_wp_sync_lock' ) );
			self::assertSame( 'preserve', get_option( 'cinebot_wp_unrelated' ) );
			self::assertFalse( get_transient( 'cinebot_prog_contract' ) );
			self::assertFalse( get_option( '_transient_cinebot_prog_contract' ) );
			self::assertFalse( get_option( '_transient_timeout_cinebot_prog_contract' ) );
			self::assertSame( 'preserve', get_transient( 'cinebot_unrelated' ) );
			self::assertSame( 'preserve', get_option( '_transient_cinebot_unrelated' ) );
			self::assertNotFalse( get_option( '_transient_timeout_cinebot_unrelated' ) );
			self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );
			self::assertNotFalse( wp_next_scheduled( 'cinebot_wp_unrelated_event' ) );
		} finally {
			delete_option( 'cinebot_wp_settings' );
			delete_option( 'cinebot_wp_db_version' );
			delete_option( 'cinebot_wp_encryption_salt' );
			delete_option( 'cinebot_wp_sync_lock' );
			delete_option( 'cinebot_wp_unrelated' );
			delete_transient( 'cinebot_prog_contract' );
			delete_transient( 'cinebot_unrelated' );
			wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
			wp_clear_scheduled_hook( 'cinebot_wp_unrelated_event' );
			self::$db->query( "DROP TABLE IF EXISTS {$unrelated_table}" );
			$installer->install();
		}
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
