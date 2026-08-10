<?php
/**
 * Database schema integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Integration assertions query trusted, fixed schema identifiers directly.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\EventTypeDefaults;
use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Plugin;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies schema installation and plugin lifecycle behavior.
 */
final class SchemaInstallerTest extends WP_UnitTestCase {
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
	 * Remove plugin data before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$this->drop_plugin_tables();
		delete_option( 'cinebot_wp_db_version' );
		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
	}

	/**
	 * Remove plugin data after each test.
	 */
	public function tear_down(): void {
		$this->drop_plugin_tables();
		delete_option( 'cinebot_wp_db_version' );
		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );

		parent::tear_down();
	}

	/**
	 * Verifies all approved tables, fields, indexes, and defaults.
	 */
	public function test_install_creates_approved_schema_and_defaults(): void {
		( new SchemaInstaller( self::$db ) )->install();
		$expected_tables = array_map(
			static function ( string $suffix ): string {
				return self::$db->prefix . 'cinebot_' . $suffix;
			},
			self::TABLE_SUFFIXES
		);
		$actual_tables   = self::$db->get_col(
			self::$db->prepare(
				'SHOW TABLES LIKE %s',
				self::$db->esc_like( self::$db->prefix . 'cinebot_' ) . '%'
			)
		);
		sort( $expected_tables );
		sort( $actual_tables );
		self::assertSame( $expected_tables, $actual_tables );

		foreach ( self::TABLE_SUFFIXES as $suffix ) {
			$table = self::$db->prefix . 'cinebot_' . $suffix;
			self::assertSame(
				$table,
				self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', self::$db->esc_like( $table ) ) )
			);
			self::assertSame( 'InnoDB', $this->table_engine( $table ) );
		}

		self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
		self::assertArrayNotHasKey( 'cinebot_wp_db_version', wp_load_alloptions() );
		self::assertSame(
			62,
			(int) self::$db->get_var( 'SELECT COUNT(*) FROM ' . self::$db->prefix . 'cinebot_tipologie_eventi' )
		);

		$this->assert_nullable_column( 'titoli', 'idtitolo' );
		$this->assert_nullable_column( 'titoli', 'frontend_id' );
		$this->assert_nullable_column( 'eventi', 'idevento' );
		$this->assert_nullable_column( 'settori', 'idsettore' );
		$this->assert_nullable_column( 'prezzi', 'idprezzo' );
		$this->assert_nullable_column( 'locali', 'locale_id_remoto' );

		$this->assert_nullable_column( 'eventi', 'url_acquisto' );
		$this->assert_column_type( 'eventi', 'url_acquisto', 'varchar(500)' );

		$this->assert_index( 'titoli', 'idtitolo', array( 'idtitolo' ), true );
		$this->assert_index( 'titoli', 'frontend_sync', array( 'frontend_id', 'sync_active', 'last_seen_sync' ) );
		$this->assert_index( 'eventi', 'idevento', array( 'idevento' ), true );
		$this->assert_index( 'eventi', 'inizio', array( 'inizio' ) );
		$this->assert_index( 'eventi', 'titolo_sync', array( 'titolo_id', 'sync_active', 'last_seen_sync' ) );
		$this->assert_index( 'settori', 'remote_evento', array( 'idsettore', 'evento_id' ), true );
		$this->assert_index( 'settori', 'evento_sync', array( 'evento_id', 'sync_active', 'last_seen_sync' ) );
		$this->assert_index( 'prezzi', 'remote_settore', array( 'idprezzo', 'settore_id' ), true );
		$this->assert_index( 'prezzi', 'settore_sync', array( 'settore_id', 'sync_active', 'last_seen_sync' ) );
		$this->assert_index( 'locali', 'locale_id_remoto', array( 'locale_id_remoto' ), true );

		foreach ( array( 'titoli', 'eventi', 'settori', 'prezzi' ) as $suffix ) {
			$this->assert_column_exists( $suffix, 'sync_active' );
			$this->assert_nullable_column( $suffix, 'last_seen_sync' );
		}
	}

	/**
	 * Verifies every approved default code and description through a canonical fingerprint.
	 */
	public function test_defaults_match_approved_catalog(): void {
		$defaults = EventTypeDefaults::all();

		self::assertCount( 62, $defaults );
		self::assertCount( 62, array_unique( array_column( $defaults, 'codice' ) ) );
		self::assertSame(
			'26e7c32546f10b24f2260373b2d65c06dbf94d44d84c66963c69ce7d0d4ef380',
			$this->event_type_fingerprint( $defaults )
		);
	}

	/**
	 * Verifies repeated activation preserves existing event-type choices.
	 */
	public function test_install_is_idempotent_and_preserves_disabled_defaults(): void {
		$installer = new SchemaInstaller( self::$db );
		$installer->install();

		$table = self::$db->prefix . 'cinebot_tipologie_eventi';
		self::$db->update( $table, array( 'attivo' => 0 ), array( 'codice' => '01' ), array( '%d' ), array( '%s' ) );
		$installer->install();

		self::assertSame( 62, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		self::assertSame(
			'0',
			self::$db->get_var( self::$db->prepare( "SELECT attivo FROM {$table} WHERE codice = %s", '01' ) )
		);
	}

	/**
	 * Verifies a failed partial seed rolls back and can be retried completely.
	 */
	public function test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults(): void {
		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			/** @var bool */
			public $failed = false;

			/** @var string[] */
			public $transaction_queries = array();

			/** @var int */
			private $insert_count = 0;

			public function insert( $table, $data, $format = null ) {
				++$this->insert_count;
				if ( ! $this->failed && 5 === $this->insert_count ) {
					$this->failed = true;

					return false;
				}

				return parent::insert( $table, $data, $format );
			}

			public function query( $query ) {
				$normalized = strtoupper( trim( $query ) );
				if ( in_array( $normalized, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
					$this->transaction_queries[] = $normalized;
				}

				return parent::query( $query );
			}
		};
		$db->set_prefix( self::$db->prefix );
		$installer = new SchemaInstaller( $db );
		$table     = self::$db->prefix . 'cinebot_tipologie_eventi';

		try {
			try {
				$installer->install();
				self::fail( 'A forced mid-seed insert failure should abort installation.' );
			} catch ( RuntimeException $exception ) {
				self::assertSame(
					esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ),
					$exception->getMessage()
				);
			}

		self::assertSame( 0, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->transaction_queries );
		self::assertFalse( get_option( 'cinebot_wp_db_version' ) );

		$installer->install();

		self::assertSame( 62, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
		self::assertSame(
			array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ),
			$db->transaction_queries
		);
		self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
		} finally {
			$db->close();
		}
	}

	/**
	 * Verifies lifecycle hooks use only the plugin coordinator callbacks.
	 */
	public function test_entry_point_registers_plugin_lifecycle_callbacks(): void {
		$activation_hook   = 'activate_' . plugin_basename( CINEBOT_WP_FILE );
		$deactivation_hook = 'deactivate_' . plugin_basename( CINEBOT_WP_FILE );

		self::assertSame( 10, has_action( $activation_hook, array( Plugin::class, 'activate' ) ) );
		self::assertSame( 10, has_action( $deactivation_hook, array( Plugin::class, 'deactivate' ) ) );
	}

	/**
	 * Verifies deactivation clears cron without deleting persisted data.
	 */
	public function test_deactivation_retains_schema_and_data(): void {
		Plugin::activate();
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_sync_event' );

		Plugin::deactivate();

		self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );
		self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
		self::assertSame(
			self::$db->prefix . 'cinebot_titoli',
			self::$db->get_var(
				self::$db->prepare(
					'SHOW TABLES LIKE %s',
					self::$db->esc_like( self::$db->prefix . 'cinebot_titoli' )
				)
			)
		);
	}

	/**
	 * Verifies unsupported storage engines abort before schema statements.
	 */
	public function test_install_fails_before_creation_without_innodb(): void {
		$db = new class() extends wpdb {
			/** @var string[] */
			public $queries = array();

			public function __construct() {
				$this->prefix = 'wp_';
			}

			public function get_results( $query = null, $output = OBJECT ) {
				$this->queries[] = $query;

				return array( array( 'Engine' => 'MyISAM', 'Support' => 'DEFAULT' ) );
			}

			public function query( $query ) {
				$this->queries[] = $query;

				return false;
			}
		};

		$installer = new SchemaInstaller( $db );
		self::assertFalse( $installer->supportsTransactions() );

		try {
			$installer->install();
			self::fail( 'Installation should fail when InnoDB is unavailable.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'InnoDB', $exception->getMessage() );
		}

		self::assertCount( 2, $db->queries );
		self::assertSame( array( 'SHOW ENGINES', 'SHOW ENGINES' ), $db->queries );
	}

	/**
	 * Drop all fixed plugin tables.
	 */
	private function drop_plugin_tables(): void {
		foreach ( array_reverse( self::TABLE_SUFFIXES ) as $suffix ) {
			self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
	}

	/**
	 * Hash UTF-8 `code<TAB>description` rows joined by LF with no trailing LF.
	 *
	 * @param array<int,array{codice:string,descrizione:string}> $defaults Event type catalog.
	 */
	private function event_type_fingerprint( array $defaults ): string {
		$rows = array_map(
			static function ( array $event_type ): string {
				return $event_type['codice'] . "\t" . $event_type['descrizione'];
			},
			$defaults
		);

		return hash( 'sha256', implode( "\n", $rows ) );
	}

	/**
	 * Return a table storage engine.
	 */
	private function table_engine( string $table ): string {
		$status = self::$db->get_row( self::$db->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
		self::assertIsObject( $status );

		return $status->Engine;
	}

	/**
	 * Assert that a column exists.
	 */
	private function assert_column_exists( string $suffix, string $column ): void {
		$table = self::$db->prefix . 'cinebot_' . $suffix;
		self::assertSame( $column, self::$db->get_var( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ) );
	}

	/**
	 * Assert that a column permits NULL.
	 */
	private function assert_nullable_column( string $suffix, string $column ): void {
		$table  = self::$db->prefix . 'cinebot_' . $suffix;
		$result = self::$db->get_row( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		self::assertIsObject( $result );
		self::assertSame( 'YES', $result->Null );
	}

	/** Assert the normalized SQL type for one column. */
	private function assert_column_type( string $suffix, string $column, string $type ): void {
		$table  = self::$db->prefix . 'cinebot_' . $suffix;
		$result = self::$db->get_row( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		self::assertIsObject( $result );
		self::assertSame( $type, strtolower( (string) $result->Type ) );
	}

	/**
	 * Assert an index's ordered columns and uniqueness.
	 *
	 * @param string[] $columns Expected ordered columns.
	 */
	private function assert_index( string $suffix, string $name, array $columns, bool $unique = false ): void {
		$table = self::$db->prefix . 'cinebot_' . $suffix;
		$rows  = self::$db->get_results( self::$db->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) );

		self::assertCount( count( $columns ), $rows );
		self::assertSame( $columns, array_column( $rows, 'Column_name' ) );
		self::assertSame( $unique ? '0' : '1', (string) $rows[0]->Non_unique );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
