<?php
/**
 * Database schema installation.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Database;

use RuntimeException;
use wpdb;

/**
 * Installs the plugin's custom tables and initial data.
 */
final class SchemaInstaller {
	/** @var wpdb */
	private $db;

	/**
	 * Store the WordPress database connection.
	 */
	public function __construct( wpdb $db ) {
		$this->db = $db;
	}

	/**
	 * Install or update the database schema.
	 *
	 * @throws RuntimeException When InnoDB is unavailable or defaults cannot be stored.
	 */
	public function install(): void {
		if ( ! $this->supportsTransactions() ) {
			throw new RuntimeException(
				esc_html__(
					'Cinebot WP requires InnoDB support. Enable InnoDB on the database server, then activate the plugin again.',
					'cinebot-wp'
				)
			);
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( $this->schema_statements() as $statement ) {
			dbDelta( $statement );
		}

		if ( ! add_option( 'cinebot_wp_db_version', '1.0.0', '', false ) ) {
			update_option( 'cinebot_wp_db_version', '1.0.0', false );
		}

		$this->seed_event_types();
	}

	/**
	 * Return whether the server supports transactional InnoDB tables.
	 */
	public function supportsTransactions(): bool {
		// Server engine metadata is not cacheable application data.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$engines = $this->db->get_results( 'SHOW ENGINES', ARRAY_A );

		foreach ( $engines as $engine ) {
			$name    = isset( $engine['Engine'] ) ? $engine['Engine'] : '';
			$support = isset( $engine['Support'] ) ? $engine['Support'] : '';
			if ( 'innodb' === strtolower( (string) $name ) && in_array( strtoupper( (string) $support ), array( 'YES', 'DEFAULT' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Return the seven dbDelta statements.
	 *
	 * @return string[]
	 */
	private function schema_statements(): array {
		$base            = $this->db->prefix . 'cinebot_';
		$charset_collate = $this->db->get_charset_collate();
		$table_options   = "ENGINE=InnoDB {$charset_collate}";

		return array(
			"CREATE TABLE {$base}titoli (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idtitolo bigint(20) unsigned NULL,
				frontend_id bigint(20) unsigned NULL,
				titolo varchar(255) NOT NULL,
				autore varchar(255) NULL,
				esecutore varchar(255) NULL,
				durata int NULL,
				scadenza tinyint(1) unsigned NULL,
				descrizione longtext NULL,
				tipoevento_codice varchar(10) NULL,
				locandina_flag tinyint(1) unsigned NULL,
				locandina_url varchar(500) NULL,
				cinetel varchar(100) NULL,
				tmdb varchar(100) NULL,
				trailer varchar(500) NULL,
				`cast` text NULL,
				tag text NULL,
				source varchar(10) NOT NULL DEFAULT 'api',
				sync_hash varchar(64) NULL,
				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
				last_seen_sync char(36) NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idtitolo (idtitolo),
				KEY source (source),
				KEY tipoevento_codice (tipoevento_codice),
				KEY sync_active (sync_active),
				KEY frontend_sync (frontend_id,sync_active,last_seen_sync)
			) {$table_options};",
			"CREATE TABLE {$base}eventi (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idevento bigint(20) unsigned NULL,
				titolo_id bigint(20) unsigned NOT NULL,
				inizio datetime NOT NULL,
				organizzatore_id bigint(20) NULL,
				organizzatore_cf varchar(50) NULL,
				locale_id bigint(20) unsigned NOT NULL,
				stato tinyint NULL,
				otp tinyint(1) unsigned NULL,
				controlloaccessi tinyint(1) unsigned NULL,
				mappa int NULL,
				source varchar(10) NOT NULL DEFAULT 'api',
				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
				last_seen_sync char(36) NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY idevento (idevento),
				KEY titolo_id (titolo_id),
				KEY locale_id (locale_id),
				KEY inizio (inizio),
				KEY sync_active (sync_active),
				KEY titolo_sync (titolo_id,sync_active,last_seen_sync)
			) {$table_options};",
			"CREATE TABLE {$base}settori (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idsettore bigint(20) unsigned NULL,
				evento_id bigint(20) unsigned NOT NULL,
				nome varchar(255) NULL,
				source varchar(10) NOT NULL DEFAULT 'api',
				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
				last_seen_sync char(36) NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				KEY evento_id (evento_id),
				KEY sync_active (sync_active),
				UNIQUE KEY remote_evento (idsettore,evento_id),
				KEY evento_sync (evento_id,sync_active,last_seen_sync)
			) {$table_options};",
			"CREATE TABLE {$base}prezzi (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				idprezzo bigint(20) unsigned NULL,
				settore_id bigint(20) unsigned NOT NULL,
				nome varchar(255) NULL,
				tipo varchar(5) NULL,
				importo decimal(10,2) NULL,
				prevendita decimal(10,2) NULL,
				stato tinyint NULL,
				source varchar(10) NOT NULL DEFAULT 'api',
				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
				last_seen_sync char(36) NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				KEY settore_id (settore_id),
				KEY sync_active (sync_active),
				UNIQUE KEY remote_settore (idprezzo,settore_id),
				KEY settore_sync (settore_id,sync_active,last_seen_sync)
			) {$table_options};",
			"CREATE TABLE {$base}locali (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				locale_id_remoto bigint(20) unsigned NULL,
				nome varchar(255) NOT NULL,
				codice varchar(50) NULL,
				indirizzo varchar(255) NULL,
				cap varchar(10) NULL,
				comune varchar(100) NULL,
				provincia varchar(10) NULL,
				mappa int NULL,
				source varchar(10) NOT NULL DEFAULT 'manual',
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY locale_id_remoto (locale_id_remoto),
				KEY comune (comune)
			) {$table_options};",
			"CREATE TABLE {$base}tipologie_eventi (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				codice varchar(10) NOT NULL,
				descrizione varchar(255) NOT NULL,
				predefinito tinyint(1) unsigned NOT NULL DEFAULT 0,
				attivo tinyint(1) unsigned NOT NULL DEFAULT 1,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY codice (codice)
			) {$table_options};",
			"CREATE TABLE {$base}sync_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				started_at datetime NOT NULL,
				finished_at datetime NULL,
				status varchar(20) NULL,
				titoli_added int NOT NULL DEFAULT 0,
				titoli_updated int NOT NULL DEFAULT 0,
				eventi_added int NOT NULL DEFAULT 0,
				eventi_updated int NOT NULL DEFAULT 0,
				error_message text NULL,
				payload_hash varchar(64) NULL,
				PRIMARY KEY  (id),
				KEY started_at (started_at),
				KEY status (status)
			) {$table_options};",
		);
	}

	/**
	 * Seed defaults only when no event types exist.
	 *
	 * @throws RuntimeException When a default cannot be inserted.
	 */
	private function seed_event_types(): void {
		$table = $this->db->prefix . 'cinebot_tipologie_eventi';
		// The table identifier is composed only from the trusted WordPress prefix and a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( 0 !== (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" ) ) {
			return;
		}

		$now = current_time( 'mysql', true );
		foreach ( EventTypeDefaults::all() as $event_type ) {
			// wpdb::insert prepares every dynamic value using the supplied formats.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$inserted = $this->db->insert(
				$table,
				array(
					'codice'      => $event_type['codice'],
					'descrizione' => $event_type['descrizione'],
					'predefinito' => 1,
					'attivo'      => 1,
					'created_at'  => $now,
					'updated_at'  => $now,
				),
				array( '%s', '%s', '%d', '%d', '%s', '%s' )
			);

			if ( false === $inserted ) {
				throw new RuntimeException( esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ) );
			}
		}
	}
}
