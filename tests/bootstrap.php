<?php
/**
 * Load the WordPress integration test environment.
 *
 * @package CinebotWp
 */

$plugin_root = dirname( __DIR__ );
$tests_dir   = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-develop/tests/phpunit';

require $plugin_root . '/vendor/autoload.php';
define( 'WP_TESTS_CONFIG_FILE_PATH', __DIR__ . '/wp-tests-config.php' );
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' ); // Keep for compatibility if needed.

require $tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		require $plugin_root . '/cinebot-wp.php';
	}
);
require $tests_dir . '/includes/bootstrap.php';

/**
 * Load test helpers after WP test framework is fully bootstrapped.
 *
 * Defines namespace-local wp_send_json* functions in CinebotWp\Frontend
 * and CinebotWp\Admin\Pages so that die; is never reached during tests.
 * PHP resolves unqualified function calls to the current namespace first.
 */
require_once __DIR__ . '/test-helpers.php';
