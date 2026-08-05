<?php
/**
 * Load the WordPress integration test environment.
 *
 * @package CinebotWp
 */

$plugin_root = dirname( __DIR__ );
$tests_dir   = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-develop/tests/phpunit';

require $plugin_root . '/vendor/autoload.php';
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );

require $tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		require $plugin_root . '/cinebot-wp.php';
	}
);
require $tests_dir . '/includes/bootstrap.php';
