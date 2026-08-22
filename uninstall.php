<?php
/**
 * Remove all single-site Cinebot WP data.
 *
 * @package CinebotWp
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Uninstall must issue schema changes and delete matching options directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

delete_option( 'cinebot_wp_settings' );
delete_option( 'cinebot_wp_db_version' );
delete_option( 'cinebot_wp_encryption_salt' );
delete_option( 'cinebot_wp_sync_lock' );
wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );

$value_pattern     = $wpdb->esc_like( '_transient_cinebot_prog_' ) . '%';
$timeout_pattern   = $wpdb->esc_like( '_transient_timeout_cinebot_prog_' ) . '%';
$transient_options = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$value_pattern,
		$timeout_pattern
	)
);
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$value_pattern,
		$timeout_pattern
	)
);
foreach ( $transient_options as $transient_option ) {
	wp_cache_delete( (string) $transient_option, 'options' );
}

$table_suffixes = array(
	'prezzi',
	'settori',
	'eventi',
	'titoli',
	'locali',
	'tipologie_eventi',
	'sync_log',
);
$tables        = array_map(
	static function ( string $table_suffix ) use ( $wpdb ): string {
		return $wpdb->prefix . 'cinebot_' . $table_suffix;
	},
	$table_suffixes
);
// Fixed plugin table identifiers do not contain dynamic input.
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
$drop_result = $wpdb->query( 'DROP TABLE IF EXISTS ' . implode( ', ', $tables ) );
if ( false === $drop_result ) {
	throw new RuntimeException( 'Cinebot WP could not remove its database tables.' );
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
