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

$table_suffixes = array(
	'titoli',
	'eventi',
	'settori',
	'prezzi',
	'locali',
	'tipologie_eventi',
	'sync_log',
);

foreach ( $table_suffixes as $table_suffix ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cinebot_' . $table_suffix );
}

delete_option( 'cinebot_wp_settings' );
delete_option( 'cinebot_wp_db_version' );
delete_option( 'cinebot_wp_encryption_salt' );
delete_option( 'cinebot_wp_sync_lock' );
wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );

$value_pattern   = $wpdb->esc_like( '_transient_cinebot_prog_' ) . '%';
$timeout_pattern = $wpdb->esc_like( '_transient_timeout_cinebot_prog_' ) . '%';
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$value_pattern,
		$timeout_pattern
	)
);

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
