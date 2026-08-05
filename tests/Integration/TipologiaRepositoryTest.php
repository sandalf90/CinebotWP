<?php
/**
 * Event-type repository integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Test setup and assertions use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Models\TipologiaEvento;
use CinebotWp\Repositories\TipologiaRepository;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies event-type persistence behavior.
 */
final class TipologiaRepositoryTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var TipologiaRepository */
	private $repository;

	/**
	 * Store the WordPress database connection.
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		self::$db = $wpdb;
	}

	/**
	 * Recreate the event-type table and its approved defaults.
	 */
	public function set_up(): void {
		parent::set_up();

		self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_tipologie_eventi' );
		( new SchemaInstaller( self::$db ) )->install();
		$this->repository = new TipologiaRepository( self::$db );
	}

	/**
	 * Remove the test table.
	 */
	public function tear_down(): void {
		self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_tipologie_eventi' );

		parent::tear_down();
	}

	/**
	 * Leading zeroes survive lookup and rows are returned as DTOs.
	 */
	public function test_find_by_code_preserves_leading_zero_code(): void {
		$type = $this->repository->findByCode( '01' );

		self::assertInstanceOf( TipologiaEvento::class, $type );
		self::assertSame( '01', $type->codice );
		self::assertSame( 'CINEMA', $type->descrizione );
		self::assertNull( $this->repository->findByCode( 'missing' ) );
	}

	/**
	 * Active filtering retains ascending string-code order.
	 */
	public function test_find_all_filters_active_rows_and_orders_by_code(): void {
		$disabled = $this->repository->findByCode( '01' );
		self::assertInstanceOf( TipologiaEvento::class, $disabled );
		$this->repository->setActive( (int) $disabled->id, false );

		$all    = $this->repository->findAll();
		$active = $this->repository->findAll( true );
		$codes  = array_map(
			static function ( TipologiaEvento $type ): string {
				return $type->codice;
			},
			$all
		);

		self::assertNotEmpty( $all );
		self::assertContainsOnlyInstancesOf( TipologiaEvento::class, $all );
		self::assertSame( '01', $codes[0] );
		self::assertSame( $codes, array_values( array_unique( $codes ) ) );
		self::assertSame( array_values( $codes ), $this->sorted_codes( $codes ) );
		self::assertNotContains( '01', array_column( array_map( array( $this, 'type_to_array' ), $active ), 'codice' ) );
	}

	/**
	 * Custom rows support insert, update, disable, and delete without replacing creation time.
	 */
	public function test_custom_type_lifecycle_preserves_created_timestamp(): void {
		$type               = new TipologiaEvento();
		$type->codice       = 'CUSTOM';
		$type->descrizione  = 'Custom type';
		$type->predefinito  = 0;
		$type->attivo       = 1;
		$id                 = $this->repository->save( $type );
		$stored             = $this->repository->findByCode( 'CUSTOM' );

		self::assertGreaterThan( 0, $id );
		self::assertInstanceOf( TipologiaEvento::class, $stored );
		self::assertSame( $id, $stored->id );
		self::assertNotNull( $stored->createdAt );
		self::assertSame( $stored->createdAt, $stored->updatedAt );

		$created_at          = $stored->createdAt;
		$stored->descrizione = 'Updated custom type';
		self::assertSame( $id, $this->repository->save( $stored ) );
		$updated = $this->repository->findByCode( 'CUSTOM' );
		self::assertInstanceOf( TipologiaEvento::class, $updated );
		self::assertSame( 'Updated custom type', $updated->descrizione );
		self::assertSame( $created_at, $updated->createdAt );

		$this->repository->setActive( $id, false );
		$disabled = $this->repository->findByCode( 'CUSTOM' );
		self::assertInstanceOf( TipologiaEvento::class, $disabled );
		self::assertSame( 0, $disabled->attivo );
		self::assertTrue( $this->repository->deleteCustom( $id ) );
		self::assertFalse( $this->repository->deleteCustom( $id ) );
		self::assertNull( $this->repository->findByCode( 'CUSTOM' ) );
	}

	/**
	 * Predefined and missing rows cannot be deleted as custom types.
	 */
	public function test_delete_custom_rejects_predefined_and_missing_rows(): void {
		$type = $this->repository->findByCode( '01' );
		self::assertInstanceOf( TipologiaEvento::class, $type );

		self::assertFalse( $this->repository->deleteCustom( (int) $type->id ) );
		self::assertFalse( $this->repository->deleteCustom( PHP_INT_MAX ) );
		self::assertNotNull( $this->repository->findByCode( '01' ) );
	}

	/**
	 * Duplicate codes produce an actionable exception without exposing SQL.
	 */
	public function test_save_rejects_duplicate_codes_with_safe_error(): void {
		$type              = new TipologiaEvento();
		$type->codice      = '01';
		$type->descrizione = 'Duplicate';

		try {
			$this->repository->save( $type );
			self::fail( 'A duplicate event-type code should fail.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
		}
	}

	/**
	 * Failed writes and invalid identifiers produce safe actionable exceptions.
	 */
	public function test_failed_updates_and_missing_activation_targets_throw(): void {
		$type = $this->repository->findByCode( '01' );
		self::assertInstanceOf( TipologiaEvento::class, $type );

		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				return false;
			}
		};
		$db->set_prefix( self::$db->prefix );

		try {
			$failing_repository = new TipologiaRepository( $db );
			try {
				$failing_repository->save( $type );
				self::fail( 'A failed database update should throw.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
				self::assertDoesNotMatchRegularExpression( '/\bupdate\b/i', $exception->getMessage() );
			}
		} finally {
			$db->close();
		}

		foreach ( array( 0, -1, PHP_INT_MAX ) as $id ) {
			try {
				$this->repository->setActive( $id, true );
				self::fail( 'An invalid or missing event-type ID should throw.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
			}
		}
	}

	/**
	 * Convert a DTO for concise assertions.
	 *
	 * @return array<string,mixed>
	 */
	public function type_to_array( TipologiaEvento $type ): array {
		return $type->toArray();
	}

	/**
	 * Sort codes using the repository's documented string ordering.
	 *
	 * @param string[] $codes Codes to sort.
	 * @return string[]
	 */
	private function sorted_codes( array $codes ): array {
		sort( $codes, SORT_STRING );

		return $codes;
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
