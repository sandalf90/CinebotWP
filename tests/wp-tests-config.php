<?php
/**
 * WordPress integration test configuration.
 *
 * @package CinebotWp
 */

define( 'ABSPATH', rtrim( (string) getenv( 'WP_CORE_DIR' ), '/\\' ) . '/' );
define( 'DB_NAME', (string) getenv( 'WP_TESTS_DB_NAME' ) );
define( 'DB_USER', (string) getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', (string) getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Cinebot WP Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
$table_prefix = 'wptests_';
