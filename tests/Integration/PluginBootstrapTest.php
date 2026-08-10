<?php
/**
 * Plugin foundation integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Plugin;
use ReflectionClass;
use WP_UnitTestCase;
use ZipArchive;
use wpdb;

/**
 * Verifies the executable plugin foundation.
 */
final class PluginBootstrapTest extends WP_UnitTestCase {
	/**
	 * Verifies that plugin boot is idempotent.
	 */
	public function test_plugin_bootstraps_once(): void {
		$boot_count = 0;
		$observer   = static function () use ( &$boot_count ): void {
			++$boot_count;
		};

		add_action( 'cinebot_wp_booted', $observer );

		try {
			$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$plugin->boot();
			$plugin->boot();
		} finally {
			remove_action( 'cinebot_wp_booted', $observer );
		}

		self::assertSame( 1, $boot_count );
		self::assertSame( Plugin::instance(), Plugin::instance() );
		self::assertTrue( defined( 'CINEBOT_WP_VERSION' ) );
		self::assertSame( '1.0.0', CINEBOT_WP_VERSION );
	}

	/**
	 * Verifies that runtime loading does not depend on Composer.
	 */
	public function test_runtime_does_not_require_composer_vendor_directory(): void {
		self::assertFileExists( CINEBOT_WP_PATH . 'includes/autoload.php' );
		// Direct access is appropriate for a local test fixture.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entry_point = file_get_contents( CINEBOT_WP_FILE );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertIsString( $entry_point );
		self::assertStringNotContainsString( 'vendor/autoload.php', $entry_point );
	}

	/**
	 * Verifies that the distribution contains only runtime files.
	 */
	public function test_distribution_contains_only_runtime_files(): void {
		$archive_path = CINEBOT_WP_PATH . 'dist/cinebot-wp.zip';

		ob_start();
		try {
			require CINEBOT_WP_PATH . 'tools/build.php';
		} finally {
			$build_output = ob_get_clean();
		}

		self::assertIsString( $build_output );
		self::assertStringContainsString( $archive_path, $build_output );

		$archive = new ZipArchive();
		self::assertTrue( $archive->open( $archive_path ) );

		try {
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/cinebot-wp.php' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/autoload.php' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/Plugin.php' ) );

			for ( $index = 0; $index < $archive->count(); ++$index ) {
				$name = $archive->getNameIndex( $index );
				self::assertIsString( $name );
				self::assertStringStartsWith( 'cinebot-wp/', $name );
				self::assertDoesNotMatchRegularExpression(
					'#^cinebot-wp/(?:\.git|\.github|docker|docs|specs|tests|tools|vendor|dist)(?:/|$)#',
					$name
				);
			}
		} finally {
			$archive->close();
		}
	}

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
}
