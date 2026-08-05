<?php
/**
 * Synchronization-log repository integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Test setup and assertions use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Models\SyncLog;
use CinebotWp\Repositories\SyncLogRepository;
use DateTimeImmutable;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies synchronization history persistence and querying.
 */
final class SyncLogRepositoryTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var string */
	private $table;

	/** @var SyncLogRepository */
	private $repository;

	/** Store the WordPress database connection. */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		global $wpdb;
		self::$db = $wpdb;
	}

	/** Recreate the synchronization-log table. */
	public function set_up(): void {
		parent::set_up();

		$this->table = self::$db->prefix . 'cinebot_sync_log';
		self::$db->query( 'DROP TABLE IF EXISTS ' . $this->table );
		( new SchemaInstaller( self::$db ) )->install();
		$this->repository = new SyncLogRepository(
			self::$db,
			static function (): string {
				return '2026-08-06 10:00:00';
			}
		);
	}

	/** Remove the test table. */
	public function tear_down(): void {
		self::$db->query( 'DROP TABLE IF EXISTS ' . $this->table );

		parent::tear_down();
	}

	/** Starting a run stores complete defaults and normalizes an empty hash. */
	public function test_start_stores_utc_defaults_and_optional_payload_hash(): void {
		$without_hash = $this->repository->start();
		$with_hash    = $this->repository->start( 'abc123' );

		$rows = self::$db->get_results( "SELECT * FROM {$this->table} ORDER BY id ASC", ARRAY_A );

		self::assertSame( array( $without_hash, $with_hash ), array_map( 'intval', array_column( $rows, 'id' ) ) );
		self::assertSame( '2026-08-06 10:00:00', $rows[0]['started_at'] );
		self::assertNull( $rows[0]['finished_at'] );
		self::assertSame( 'running', $rows[0]['status'] );
		self::assertSame( '0', $rows[0]['titoli_added'] );
		self::assertSame( '0', $rows[0]['titoli_updated'] );
		self::assertSame( '0', $rows[0]['eventi_added'] );
		self::assertSame( '0', $rows[0]['eventi_updated'] );
		self::assertNull( $rows[0]['error_message'] );
		self::assertNull( $rows[0]['payload_hash'] );
		self::assertSame( 'abc123', $rows[1]['payload_hash'] );
	}

	/** Finishing runs accepts approved outcomes, maps counters, and preserves start data. */
	public function test_finish_stores_outcomes_counters_and_sanitized_error(): void {
		$times = array(
			'2026-08-06 11:00:00',
			'2026-08-06 12:00:00',
			'2026-08-06 13:00:00',
			'2026-08-06 14:00:00',
		);
		$clock = static function () use ( &$times ): string {
			return array_shift( $times );
		};
		$repository = new SyncLogRepository( self::$db, $clock );

		$success_id = $repository->start( 'keep-me' );
		$repository->finish(
			$success_id,
			'success',
			array(
				'titoli_added'   => 2,
				'titoli_updated' => 3,
				'eventi_added'   => 4,
				'eventi_updated' => 5,
				'ignored'        => 99,
			)
		);

		$partial_id = $repository->start();
		$repository->finish(
			$partial_id,
			'partial',
			array(
				'titoli_added'   => -10,
				'eventi_updated' => '7',
			),
			"  Partial <b>failure</b>\r\nretry  "
		);

		$success = $this->row( $success_id );
		$partial = $this->row( $partial_id );

		self::assertSame( '2026-08-06 11:00:00', $success['started_at'] );
		self::assertSame( '2026-08-06 12:00:00', $success['finished_at'] );
		self::assertSame( 'success', $success['status'] );
		self::assertSame( array( '2', '3', '4', '5' ), $this->counters( $success ) );
		self::assertNull( $success['error_message'] );
		self::assertSame( 'keep-me', $success['payload_hash'] );

		self::assertSame( '2026-08-06 13:00:00', $partial['started_at'] );
		self::assertSame( '2026-08-06 14:00:00', $partial['finished_at'] );
		self::assertSame( 'partial', $partial['status'] );
		self::assertSame( array( '0', '0', '0', '7' ), $this->counters( $partial ) );
		self::assertSame( "Partial failure\r\nretry", $partial['error_message'] );
	}

	/** Error outcomes default all counters to zero. */
	public function test_finish_error_defaults_missing_counters(): void {
		$id = $this->repository->start();

		$this->repository->finish( $id, 'error', array(), 'Safe failure' );
		$row = $this->row( $id );

		self::assertSame( 'error', $row['status'] );
		self::assertSame( array( '0', '0', '0', '0' ), $this->counters( $row ) );
		self::assertSame( 'Safe failure', $row['error_message'] );
	}

	/** Invalid, missing, and failed writes throw safe exceptions. */
	public function test_write_failures_throw_safe_exceptions(): void {
		foreach ( array( 0, -1, PHP_INT_MAX ) as $id ) {
			$this->assert_finish_fails( $this->repository, $id, 'success' );
		}
		$this->assert_finish_fails( $this->repository, $this->repository->start(), 'running' );

		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public function insert( $table, $data, $format = null ) {
				return false;
			}

			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				return false;
			}
		};
		$db->set_prefix( self::$db->prefix );

		try {
			$failing = new SyncLogRepository( $db );
			$this->assert_start_fails( $failing );
			$this->assert_finish_fails( $failing, 1, 'success' );
		} finally {
			$db->close();
		}
	}

	/** Latest and recent return DTOs in deterministic newest-first order. */
	public function test_latest_and_recent_clamp_limits_and_order_dtos(): void {
		self::assertNull( $this->repository->latest() );

		$this->insert_log( '2026-08-01 09:00:00', 'success' );
		$newer  = $this->insert_log( '2026-08-02 09:00:00', 'partial' );
		$newest = $this->insert_log( '2026-08-02 09:00:00', 'error' );

		$latest = $this->repository->latest();
		$recent = $this->repository->recent( 2 );

		self::assertInstanceOf( SyncLog::class, $latest );
		self::assertSame( $newest, $latest->id );
		self::assertSame(
			array(
				'id'               => $newest,
				'started_at'       => '2026-08-02 09:00:00',
				'finished_at'      => '2026-08-02 09:00:00',
				'status'           => 'error',
				'titoli_added'     => 1,
				'titoli_updated'   => 2,
				'eventi_added'     => 3,
				'eventi_updated'   => 4,
				'error_message'    => null,
				'payload_hash'     => null,
			),
			$latest->toArray()
		);
		self::assertContainsOnlyInstancesOf( SyncLog::class, $recent );
		self::assertSame( array( $newest, $newer ), $this->ids( $recent ) );
		self::assertCount( 1, $this->repository->recent( 0 ) );
	}

	/** Recent results conclusively clamp to 100 and break timestamp ties by descending ID. */
	public function test_recent_clamps_to_one_hundred_with_deterministic_tie_order(): void {
		$ids = array();
		for ( $index = 0; $index < 101; $index++ ) {
			$ids[] = $this->insert_log( '2026-08-02 09:00:00', 'success' );
		}

		$expected = array_reverse( array_slice( $ids, 1 ) );

		self::assertSame( $expected, $this->ids( $this->repository->recent( 1000 ) ) );
	}

	/** Search and count share validated status/date predicates and pagination. */
	public function test_search_and_count_share_filters_and_pagination(): void {
		$this->insert_log( '2026-08-01 08:00:00', 'success' );
		$match_old = $this->insert_log( '2026-08-02 08:00:00', 'error' );
		$match_new = $this->insert_log( '2026-08-03 08:00:00', 'error' );
		$this->insert_log( '2026-08-04 08:00:00', 'partial' );

		$filters = array(
			'status' => 'error',
			'from'   => '2026-08-02 00:00:00',
			'to'     => '2026-08-03 23:59:59',
		);
		$results = $this->repository->search( $filters, 1, 1 );

		self::assertSame( 2, $this->repository->count( $filters ) );
		self::assertSame( array( $match_new ), $this->ids( $results ) );
		self::assertSame( array( $match_old ), $this->ids( $this->repository->search( $filters, 2, 1 ) ) );
		self::assertSame( array( $match_new ), $this->ids( $this->repository->search( $filters, 0, 0 ) ) );

		$ignored = array(
			'status' => "error' OR 1=1 --",
			'from'   => 'not-a-date',
			'to'     => '',
		);
		self::assertSame( 4, $this->repository->count() );
		self::assertSame( 4, $this->repository->count( $ignored ) );
		self::assertCount( 4, $this->repository->search( $ignored, 1, 10 ) );
	}

	/** Search includes exact from/to values and excludes rows beyond either boundary. */
	public function test_search_includes_exact_date_boundaries(): void {
		$this->insert_log( '2026-08-01 07:59:59', 'success' );
		$from = $this->insert_log( '2026-08-01 08:00:00', 'success' );
		$to   = $this->insert_log( '2026-08-02 08:00:00', 'success' );
		$this->insert_log( '2026-08-02 08:00:01', 'success' );

		$filters = array(
			'from' => '2026-08-01 08:00:00',
			'to'   => '2026-08-02 08:00:00',
		);

		self::assertSame( array( $to, $from ), $this->ids( $this->repository->search( $filters, 1, 10 ) ) );
		self::assertSame( 2, $this->repository->count( $filters ) );
	}

	/** Correctly shaped impossible calendar dates are ignored by search and count. */
	public function test_search_ignores_invalid_calendar_dates(): void {
		$old = $this->insert_log( '2026-02-01 08:00:00', 'success' );
		$new = $this->insert_log( '2026-03-01 08:00:00', 'success' );
		$filters = array(
			'from' => '2026-02-30 00:00:00',
			'to'   => '2026-13-01 00:00:00',
		);

		self::assertSame( array( $new, $old ), $this->ids( $this->repository->search( $filters, 1, 10 ) ) );
		self::assertSame( 2, $this->repository->count( $filters ) );
	}

	/** Search orders timestamp ties by descending ID across pages. */
	public function test_search_orders_timestamp_ties_by_descending_id(): void {
		$oldest = $this->insert_log( '2026-08-02 09:00:00', 'success' );
		$middle = $this->insert_log( '2026-08-02 09:00:00', 'success' );
		$newest = $this->insert_log( '2026-08-02 09:00:00', 'success' );

		self::assertSame(
			array( $newest, $middle ),
			$this->ids( $this->repository->search( array(), 1, 2 ) )
		);
		self::assertSame(
			array( $oldest ),
			$this->ids( $this->repository->search( array(), 2, 2 ) )
		);
	}

	/** Retention uses a strict boundary and reports database failures safely. */
	public function test_delete_older_than_uses_strict_boundary_and_handles_failure(): void {
		$old      = $this->insert_log( '2026-08-01 07:59:59', 'success' );
		$boundary = $this->insert_log( '2026-08-01 08:00:00', 'success' );
		$new      = $this->insert_log( '2026-08-01 08:00:01', 'success' );

		self::assertSame( 1, $this->repository->deleteOlderThan( new DateTimeImmutable( '2026-08-01 08:00:00' ) ) );
		self::assertSame( array( $new, $boundary ), $this->ids( $this->repository->recent( 10 ) ) );
		self::assertNotContains( $old, $this->ids( $this->repository->recent( 10 ) ) );

		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			public function query( $query ) {
				return false;
			}
		};
		$db->set_prefix( self::$db->prefix );

		try {
			$failing = new SyncLogRepository( $db );
			try {
				$failing->deleteOlderThan( new DateTimeImmutable( '2026-08-01 08:00:00' ) );
				self::fail( 'A failed synchronization-history deletion should throw.' );
			} catch ( RuntimeException $exception ) {
				self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
				self::assertDoesNotMatchRegularExpression( '/\bdelete\b/i', $exception->getMessage() );
			}
		} finally {
			$db->close();
		}
	}

	/** Insert a complete synchronization row for query tests. */
	private function insert_log( string $started_at, string $status ): int {
		self::$db->insert(
			$this->table,
			array(
				'started_at'      => $started_at,
				'finished_at'     => $started_at,
				'status'          => $status,
				'titoli_added'    => 1,
				'titoli_updated'  => 2,
				'eventi_added'    => 3,
				'eventi_updated'  => 4,
				'error_message'   => null,
				'payload_hash'    => null,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		return (int) self::$db->insert_id;
	}

	/** Return one raw row. */
	private function row( int $id ): array {
		return self::$db->get_row( self::$db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
	}

	/** Return the four persisted counters. */
	private function counters( array $row ): array {
		$names = array( 'titoli_added', 'titoli_updated', 'eventi_added', 'eventi_updated' );

		return array_values( array_intersect_key( $row, array_flip( $names ) ) );
	}

	/** Return DTO identifiers. */
	private function ids( array $logs ): array {
		return array_map(
			static function ( SyncLog $log ): int {
				return (int) $log->id;
			},
			$logs
		);
	}

	/** Assert a start failure is safe and actionable. */
	private function assert_start_fails( SyncLogRepository $repository ): void {
		try {
			$repository->start();
			self::fail( 'A failed synchronization-log insert should throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
		}
	}

	/** Assert a finish failure is safe and actionable. */
	private function assert_finish_fails( SyncLogRepository $repository, int $id, string $status ): void {
		try {
			$repository->finish( $id, $status, array() );
			self::fail( 'An invalid synchronization-log finish should throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
		}
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
