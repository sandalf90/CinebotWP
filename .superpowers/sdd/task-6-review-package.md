# Task 6 Reviewer Handoff Package

## Scope

Complete Task 6 range from parent `7bb4fa03eae778b97eff9692a002d7cfac40958e` through implementation commit `f2db8e13163e6ff835af1fc9f2b198cf72ee3b7a` and fix commit `35f5ae162311ea98ffa2a23ea9e43626f7ff9f29`. Task 6 adds synchronization-history persistence and its integration contract; the fix hardens deterministic timing, filtering, ordering, clamp, and formatting coverage.

The updated report records blocked Docker/PHP dynamic gates. Review remediation covers all four deterministic clock values, partial timestamps, 101 tied rows and exact newest-100 results, inclusive from/to boundaries, invalid calendar dates ignored by search/count, paginated tie ordering, line-length conformance, and narrowed PHPCS suppression blocks.

## Commit Metadata

```text
commit f2db8e13163e6ff835af1fc9f2b198cf72ee3b7a
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:23:43 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:23:43 2026 +0200

    feat: persist synchronization history

commit 35f5ae162311ea98ffa2a23ea9e43626f7ff9f29
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:32:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:32:12 2026 +0200

    fix: harden synchronization log tests
```

## Full Stat

Command: `git show --stat --format=fuller f2db8e1 35f5ae1`

```text
commit f2db8e13163e6ff835af1fc9f2b198cf72ee3b7a
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:23:43 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:23:43 2026 +0200

    feat: persist synchronization history

 .superpowers/sdd/task-6-report.md                  |  44 +++
 .../plans/2026-08-02-cinebot-wp-plugin.md          |   1 +
 includes/Repositories/SyncLogRepository.php        | 241 +++++++++++++++
 tests/Integration/SyncLogRepositoryTest.php        | 328 +++++++++++++++++++++
 4 files changed, 614 insertions(+)

commit 35f5ae162311ea98ffa2a23ea9e43626f7ff9f29
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:32:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:32:12 2026 +0200

    fix: harden synchronization log tests

 .superpowers/sdd/task-6-report.md           | 12 +++++
 includes/Repositories/SyncLogRepository.php | 32 ++++++++++---
 tests/Integration/SyncLogRepositoryTest.php | 71 +++++++++++++++++++++++++++--
 3 files changed, 106 insertions(+), 9 deletions(-)
```

The committed Task 6 report and one-line approved-plan interface update are coordination/documentation artifacts represented in the stat and summarized above; they are excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 6 implementation and tests after both commits.

Command: `git diff --unified=10 7bb4fa0 35f5ae1 -- includes/Repositories/SyncLogRepository.php tests/Integration/SyncLogRepositoryTest.php`

```diff
diff --git a/includes/Repositories/SyncLogRepository.php b/includes/Repositories/SyncLogRepository.php
new file mode 100644
index 0000000..f3dac37
--- /dev/null
+++ b/includes/Repositories/SyncLogRepository.php
@@ -0,0 +1,261 @@
+<?php
+/**
+ * Synchronization history persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\SyncLog;
+use DateTimeImmutable;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists synchronization lifecycle records and history queries.
+ */
+final class SyncLogRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/** @var callable():string */
+	private $clock;
+
+	/** Store the injected database connection and UTC clock. */
+	public function __construct( wpdb $db, ?callable $clock = null ) {
+		$this->db    = $db;
+		$this->table = $db->prefix . 'cinebot_sync_log';
+		$this->clock = $clock ?? static function (): string {
+			return current_time( 'mysql', true );
+		};
+	}
+
+	/**
+	 * Start a synchronization run.
+	 *
+	 * @throws RuntimeException When the history row cannot be stored.
+	 */
+	public function start( string $payloadHash = '' ): int {
+		// wpdb::insert prepares every dynamic value using the supplied formats.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->insert(
+			$this->table,
+			array(
+				'started_at'      => ( $this->clock )(),
+				'finished_at'     => null,
+				'status'          => 'running',
+				'titoli_added'    => 0,
+				'titoli_updated'  => 0,
+				'eventi_added'    => 0,
+				'eventi_updated'  => 0,
+				'error_message'   => null,
+				'payload_hash'    => '' === $payloadHash ? null : $payloadHash,
+			),
+			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
+		);
+		$id = (int) $this->db->insert_id;
+		if ( false === $result || $id <= 0 ) {
+			throw $this->persistence_exception();
+		}
+
+		return $id;
+	}
+
+	/**
+	 * Finish an existing synchronization run.
+	 *
+	 * @param array<string,mixed> $stats Synchronization counters.
+	 * @throws RuntimeException When the outcome or target is invalid, or persistence fails.
+	 */
+	public function finish( int $id, string $status, array $stats, ?string $error = null ): void {
+		if ( $id <= 0 || ! in_array( $status, array( 'success', 'error', 'partial' ), true ) ) {
+			throw $this->persistence_exception();
+		}
+
+		$data = array(
+			'finished_at'     => ( $this->clock )(),
+			'status'          => $status,
+			'titoli_added'    => $this->counter( $stats, 'titoli_added' ),
+			'titoli_updated'  => $this->counter( $stats, 'titoli_updated' ),
+			'eventi_added'    => $this->counter( $stats, 'eventi_added' ),
+			'eventi_updated'  => $this->counter( $stats, 'eventi_updated' ),
+			'error_message'   => null === $error ? null : sanitize_textarea_field( $error ),
+		);
+		// wpdb::update prepares every dynamic value and the identifier predicate.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->update(
+			$this->table,
+			$data,
+			array( 'id' => $id ),
+			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' ),
+			array( '%d' )
+		);
+		if ( 1 !== $result ) {
+			throw $this->persistence_exception();
+		}
+	}
+
+	/** Return the newest synchronization row. */
+	public function latest(): ?SyncLog {
+		// The table identifier and ordering are fixed trusted fragments.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
+		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( "SELECT * FROM {$this->table} ORDER BY started_at DESC, id DESC LIMIT 1", ARRAY_A );
+		// phpcs:enable
+
+		return is_array( $row ) ? SyncLog::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Return recent synchronization rows newest first.
+	 *
+	 * @return array<int,SyncLog>
+	 */
+	public function recent( int $limit = 5 ): array {
+		$limit = max( 1, min( 100, $limit ) );
+		// The table identifier and ordering are fixed; the limit is prepared.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
+		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results(
+			$this->db->prepare(
+				"SELECT * FROM {$this->table} ORDER BY started_at DESC, id DESC LIMIT %d",
+				$limit
+			),
+			ARRAY_A
+		);
+		// phpcs:enable
+
+		return $this->hydrate_all( $rows );
+	}
+
+	/**
+	 * Search synchronization history with fixed newest-first ordering.
+	 *
+	 * @param array<string,mixed> $filters Supported status, from, and to filters.
+	 * @return array<int,SyncLog>
+	 */
+	public function search( array $filters, int $page, int $perPage ): array {
+		$page      = max( 1, $page );
+		$perPage   = max( 1, $perPage );
+		$predicate = $this->predicate( $filters );
+		$sql       = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d";
+		$values    = array_merge( $predicate['values'], array( $perPage, ( $page - 1 ) * $perPage ) );
+		// The table identifier and SQL fragments are fixed; every value is prepared.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
+		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
+		// phpcs:enable
+
+		return $this->hydrate_all( $rows );
+	}
+
+	/**
+	 * Count synchronization history using the search predicates.
+	 *
+	 * @param array<string,mixed> $filters Supported status, from, and to filters.
+	 */
+	public function count( array $filters = array() ): int {
+		$predicate = $this->predicate( $filters );
+		$sql       = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";
+		if ( array() !== $predicate['values'] ) {
+			$sql = $this->db->prepare( $sql, $predicate['values'] );
+		}
+		// The table identifier and predicate fragments are fixed, and dynamic values were prepared above.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
+		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
+		$count = (int) $this->db->get_var( $sql );
+		// phpcs:enable
+
+		return $count;
+	}
+
+	/**
+	 * Delete history strictly older than a UTC cutoff.
+	 *
+	 * @throws RuntimeException When deletion fails.
+	 */
+	public function deleteOlderThan( DateTimeImmutable $cutoff ): int {
+		// The table identifier and comparison are fixed; the cutoff is prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$result = $this->db->query(
+			$this->db->prepare(
+				"DELETE FROM {$this->table} WHERE started_at < %s",
+				$cutoff->format( 'Y-m-d H:i:s' )
+			)
+		);
+		if ( false === $result ) {
+			throw $this->persistence_exception();
+		}
+
+		return $result;
+	}
+
+	/** Return one approved counter as a nonnegative integer. */
+	private function counter( array $stats, string $name ): int {
+		return isset( $stats[ $name ] ) ? max( 0, (int) $stats[ $name ] ) : 0;
+	}
+
+	/**
+	 * Build shared search/count predicates.
+	 *
+	 * @param array<string,mixed> $filters Supported status, from, and to filters.
+	 * @return array{sql:string,values:array<int,string>}
+	 */
+	private function predicate( array $filters ): array {
+		$clauses = array();
+		$values  = array();
+		$status  = isset( $filters['status'] ) ? (string) $filters['status'] : '';
+		if ( in_array( $status, array( 'running', 'success', 'error', 'partial' ), true ) ) {
+			$clauses[] = 'status = %s';
+			$values[]  = $status;
+		}
+
+		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $name => $operator ) {
+			$value = isset( $filters[ $name ] ) ? (string) $filters[ $name ] : '';
+			if ( $this->is_mysql_timestamp( $value ) ) {
+				$clauses[] = "started_at {$operator} %s";
+				$values[]  = $value;
+			}
+		}
+
+		return array(
+			'sql'    => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ),
+			'values' => $values,
+		);
+	}
+
+	/** Return whether a value is an exact valid MySQL UTC timestamp shape. */
+	private function is_mysql_timestamp( string $value ): bool {
+		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );
+
+		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
+	}
+
+	/**
+	 * Hydrate database rows into synchronization DTOs.
+	 *
+	 * @param array<int,array<string,mixed>> $rows Database rows.
+	 * @return array<int,SyncLog>
+	 */
+	private function hydrate_all( array $rows ): array {
+		return array_map(
+			static function ( array $row ): SyncLog {
+				return SyncLog::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/** Return a safe persistence failure without database or credential details. */
+	private function persistence_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not update synchronization history.', 'cinebot-wp' ) );
+	}
+}
diff --git a/tests/Integration/SyncLogRepositoryTest.php b/tests/Integration/SyncLogRepositoryTest.php
new file mode 100644
index 0000000..c49065d
--- /dev/null
+++ b/tests/Integration/SyncLogRepositoryTest.php
@@ -0,0 +1,393 @@
+<?php
+/**
+ * Synchronization-log repository integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Test setup and assertions use trusted, fixed plugin table identifiers.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Models\SyncLog;
+use CinebotWp\Repositories\SyncLogRepository;
+use DateTimeImmutable;
+use RuntimeException;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies synchronization history persistence and querying.
+ */
+final class SyncLogRepositoryTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var string */
+	private $table;
+
+	/** @var SyncLogRepository */
+	private $repository;
+
+	/** Store the WordPress database connection. */
+	public static function set_up_before_class(): void {
+		parent::set_up_before_class();
+
+		global $wpdb;
+		self::$db = $wpdb;
+	}
+
+	/** Recreate the synchronization-log table. */
+	public function set_up(): void {
+		parent::set_up();
+
+		$this->table = self::$db->prefix . 'cinebot_sync_log';
+		self::$db->query( 'DROP TABLE IF EXISTS ' . $this->table );
+		( new SchemaInstaller( self::$db ) )->install();
+		$this->repository = new SyncLogRepository(
+			self::$db,
+			static function (): string {
+				return '2026-08-06 10:00:00';
+			}
+		);
+	}
+
+	/** Remove the test table. */
+	public function tear_down(): void {
+		self::$db->query( 'DROP TABLE IF EXISTS ' . $this->table );
+
+		parent::tear_down();
+	}
+
+	/** Starting a run stores complete defaults and normalizes an empty hash. */
+	public function test_start_stores_utc_defaults_and_optional_payload_hash(): void {
+		$without_hash = $this->repository->start();
+		$with_hash    = $this->repository->start( 'abc123' );
+
+		$rows = self::$db->get_results( "SELECT * FROM {$this->table} ORDER BY id ASC", ARRAY_A );
+
+		self::assertSame( array( $without_hash, $with_hash ), array_map( 'intval', array_column( $rows, 'id' ) ) );
+		self::assertSame( '2026-08-06 10:00:00', $rows[0]['started_at'] );
+		self::assertNull( $rows[0]['finished_at'] );
+		self::assertSame( 'running', $rows[0]['status'] );
+		self::assertSame( '0', $rows[0]['titoli_added'] );
+		self::assertSame( '0', $rows[0]['titoli_updated'] );
+		self::assertSame( '0', $rows[0]['eventi_added'] );
+		self::assertSame( '0', $rows[0]['eventi_updated'] );
+		self::assertNull( $rows[0]['error_message'] );
+		self::assertNull( $rows[0]['payload_hash'] );
+		self::assertSame( 'abc123', $rows[1]['payload_hash'] );
+	}
+
+	/** Finishing runs accepts approved outcomes, maps counters, and preserves start data. */
+	public function test_finish_stores_outcomes_counters_and_sanitized_error(): void {
+		$times = array(
+			'2026-08-06 11:00:00',
+			'2026-08-06 12:00:00',
+			'2026-08-06 13:00:00',
+			'2026-08-06 14:00:00',
+		);
+		$clock = static function () use ( &$times ): string {
+			return array_shift( $times );
+		};
+		$repository = new SyncLogRepository( self::$db, $clock );
+
+		$success_id = $repository->start( 'keep-me' );
+		$repository->finish(
+			$success_id,
+			'success',
+			array(
+				'titoli_added'   => 2,
+				'titoli_updated' => 3,
+				'eventi_added'   => 4,
+				'eventi_updated' => 5,
+				'ignored'        => 99,
+			)
+		);
+
+		$partial_id = $repository->start();
+		$repository->finish(
+			$partial_id,
+			'partial',
+			array(
+				'titoli_added'   => -10,
+				'eventi_updated' => '7',
+			),
+			"  Partial <b>failure</b>\r\nretry  "
+		);
+
+		$success = $this->row( $success_id );
+		$partial = $this->row( $partial_id );
+
+		self::assertSame( '2026-08-06 11:00:00', $success['started_at'] );
+		self::assertSame( '2026-08-06 12:00:00', $success['finished_at'] );
+		self::assertSame( 'success', $success['status'] );
+		self::assertSame( array( '2', '3', '4', '5' ), $this->counters( $success ) );
+		self::assertNull( $success['error_message'] );
+		self::assertSame( 'keep-me', $success['payload_hash'] );
+
+		self::assertSame( '2026-08-06 13:00:00', $partial['started_at'] );
+		self::assertSame( '2026-08-06 14:00:00', $partial['finished_at'] );
+		self::assertSame( 'partial', $partial['status'] );
+		self::assertSame( array( '0', '0', '0', '7' ), $this->counters( $partial ) );
+		self::assertSame( "Partial failure\r\nretry", $partial['error_message'] );
+	}
+
+	/** Error outcomes default all counters to zero. */
+	public function test_finish_error_defaults_missing_counters(): void {
+		$id = $this->repository->start();
+
+		$this->repository->finish( $id, 'error', array(), 'Safe failure' );
+		$row = $this->row( $id );
+
+		self::assertSame( 'error', $row['status'] );
+		self::assertSame( array( '0', '0', '0', '0' ), $this->counters( $row ) );
+		self::assertSame( 'Safe failure', $row['error_message'] );
+	}
+
+	/** Invalid, missing, and failed writes throw safe exceptions. */
+	public function test_write_failures_throw_safe_exceptions(): void {
+		foreach ( array( 0, -1, PHP_INT_MAX ) as $id ) {
+			$this->assert_finish_fails( $this->repository, $id, 'success' );
+		}
+		$this->assert_finish_fails( $this->repository, $this->repository->start(), 'running' );
+
+		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			public function insert( $table, $data, $format = null ) {
+				return false;
+			}
+
+			public function update( $table, $data, $where, $format = null, $where_format = null ) {
+				return false;
+			}
+		};
+		$db->set_prefix( self::$db->prefix );
+
+		try {
+			$failing = new SyncLogRepository( $db );
+			$this->assert_start_fails( $failing );
+			$this->assert_finish_fails( $failing, 1, 'success' );
+		} finally {
+			$db->close();
+		}
+	}
+
+	/** Latest and recent return DTOs in deterministic newest-first order. */
+	public function test_latest_and_recent_clamp_limits_and_order_dtos(): void {
+		self::assertNull( $this->repository->latest() );
+
+		$this->insert_log( '2026-08-01 09:00:00', 'success' );
+		$newer  = $this->insert_log( '2026-08-02 09:00:00', 'partial' );
+		$newest = $this->insert_log( '2026-08-02 09:00:00', 'error' );
+
+		$latest = $this->repository->latest();
+		$recent = $this->repository->recent( 2 );
+
+		self::assertInstanceOf( SyncLog::class, $latest );
+		self::assertSame( $newest, $latest->id );
+		self::assertSame(
+			array(
+				'id'               => $newest,
+				'started_at'       => '2026-08-02 09:00:00',
+				'finished_at'      => '2026-08-02 09:00:00',
+				'status'           => 'error',
+				'titoli_added'     => 1,
+				'titoli_updated'   => 2,
+				'eventi_added'     => 3,
+				'eventi_updated'   => 4,
+				'error_message'    => null,
+				'payload_hash'     => null,
+			),
+			$latest->toArray()
+		);
+		self::assertContainsOnlyInstancesOf( SyncLog::class, $recent );
+		self::assertSame( array( $newest, $newer ), $this->ids( $recent ) );
+		self::assertCount( 1, $this->repository->recent( 0 ) );
+	}
+
+	/** Recent results conclusively clamp to 100 and break timestamp ties by descending ID. */
+	public function test_recent_clamps_to_one_hundred_with_deterministic_tie_order(): void {
+		$ids = array();
+		for ( $index = 0; $index < 101; $index++ ) {
+			$ids[] = $this->insert_log( '2026-08-02 09:00:00', 'success' );
+		}
+
+		$expected = array_reverse( array_slice( $ids, 1 ) );
+
+		self::assertSame( $expected, $this->ids( $this->repository->recent( 1000 ) ) );
+	}
+
+	/** Search and count share validated status/date predicates and pagination. */
+	public function test_search_and_count_share_filters_and_pagination(): void {
+		$this->insert_log( '2026-08-01 08:00:00', 'success' );
+		$match_old = $this->insert_log( '2026-08-02 08:00:00', 'error' );
+		$match_new = $this->insert_log( '2026-08-03 08:00:00', 'error' );
+		$this->insert_log( '2026-08-04 08:00:00', 'partial' );
+
+		$filters = array(
+			'status' => 'error',
+			'from'   => '2026-08-02 00:00:00',
+			'to'     => '2026-08-03 23:59:59',
+		);
+		$results = $this->repository->search( $filters, 1, 1 );
+
+		self::assertSame( 2, $this->repository->count( $filters ) );
+		self::assertSame( array( $match_new ), $this->ids( $results ) );
+		self::assertSame( array( $match_old ), $this->ids( $this->repository->search( $filters, 2, 1 ) ) );
+		self::assertSame( array( $match_new ), $this->ids( $this->repository->search( $filters, 0, 0 ) ) );
+
+		$ignored = array(
+			'status' => "error' OR 1=1 --",
+			'from'   => 'not-a-date',
+			'to'     => '',
+		);
+		self::assertSame( 4, $this->repository->count() );
+		self::assertSame( 4, $this->repository->count( $ignored ) );
+		self::assertCount( 4, $this->repository->search( $ignored, 1, 10 ) );
+	}
+
+	/** Search includes exact from/to values and excludes rows beyond either boundary. */
+	public function test_search_includes_exact_date_boundaries(): void {
+		$this->insert_log( '2026-08-01 07:59:59', 'success' );
+		$from = $this->insert_log( '2026-08-01 08:00:00', 'success' );
+		$to   = $this->insert_log( '2026-08-02 08:00:00', 'success' );
+		$this->insert_log( '2026-08-02 08:00:01', 'success' );
+
+		$filters = array(
+			'from' => '2026-08-01 08:00:00',
+			'to'   => '2026-08-02 08:00:00',
+		);
+
+		self::assertSame( array( $to, $from ), $this->ids( $this->repository->search( $filters, 1, 10 ) ) );
+		self::assertSame( 2, $this->repository->count( $filters ) );
+	}
+
+	/** Correctly shaped impossible calendar dates are ignored by search and count. */
+	public function test_search_ignores_invalid_calendar_dates(): void {
+		$old = $this->insert_log( '2026-02-01 08:00:00', 'success' );
+		$new = $this->insert_log( '2026-03-01 08:00:00', 'success' );
+		$filters = array(
+			'from' => '2026-02-30 00:00:00',
+			'to'   => '2026-13-01 00:00:00',
+		);
+
+		self::assertSame( array( $new, $old ), $this->ids( $this->repository->search( $filters, 1, 10 ) ) );
+		self::assertSame( 2, $this->repository->count( $filters ) );
+	}
+
+	/** Search orders timestamp ties by descending ID across pages. */
+	public function test_search_orders_timestamp_ties_by_descending_id(): void {
+		$oldest = $this->insert_log( '2026-08-02 09:00:00', 'success' );
+		$middle = $this->insert_log( '2026-08-02 09:00:00', 'success' );
+		$newest = $this->insert_log( '2026-08-02 09:00:00', 'success' );
+
+		self::assertSame(
+			array( $newest, $middle ),
+			$this->ids( $this->repository->search( array(), 1, 2 ) )
+		);
+		self::assertSame(
+			array( $oldest ),
+			$this->ids( $this->repository->search( array(), 2, 2 ) )
+		);
+	}
+
+	/** Retention uses a strict boundary and reports database failures safely. */
+	public function test_delete_older_than_uses_strict_boundary_and_handles_failure(): void {
+		$old      = $this->insert_log( '2026-08-01 07:59:59', 'success' );
+		$boundary = $this->insert_log( '2026-08-01 08:00:00', 'success' );
+		$new      = $this->insert_log( '2026-08-01 08:00:01', 'success' );
+
+		self::assertSame( 1, $this->repository->deleteOlderThan( new DateTimeImmutable( '2026-08-01 08:00:00' ) ) );
+		self::assertSame( array( $new, $boundary ), $this->ids( $this->repository->recent( 10 ) ) );
+		self::assertNotContains( $old, $this->ids( $this->repository->recent( 10 ) ) );
+
+		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			public function query( $query ) {
+				return false;
+			}
+		};
+		$db->set_prefix( self::$db->prefix );
+
+		try {
+			$failing = new SyncLogRepository( $db );
+			try {
+				$failing->deleteOlderThan( new DateTimeImmutable( '2026-08-01 08:00:00' ) );
+				self::fail( 'A failed synchronization-history deletion should throw.' );
+			} catch ( RuntimeException $exception ) {
+				self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
+				self::assertDoesNotMatchRegularExpression( '/\bdelete\b/i', $exception->getMessage() );
+			}
+		} finally {
+			$db->close();
+		}
+	}
+
+	/** Insert a complete synchronization row for query tests. */
+	private function insert_log( string $started_at, string $status ): int {
+		self::$db->insert(
+			$this->table,
+			array(
+				'started_at'      => $started_at,
+				'finished_at'     => $started_at,
+				'status'          => $status,
+				'titoli_added'    => 1,
+				'titoli_updated'  => 2,
+				'eventi_added'    => 3,
+				'eventi_updated'  => 4,
+				'error_message'   => null,
+				'payload_hash'    => null,
+			),
+			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
+		);
+
+		return (int) self::$db->insert_id;
+	}
+
+	/** Return one raw row. */
+	private function row( int $id ): array {
+		return self::$db->get_row( self::$db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
+	}
+
+	/** Return the four persisted counters. */
+	private function counters( array $row ): array {
+		$names = array( 'titoli_added', 'titoli_updated', 'eventi_added', 'eventi_updated' );
+
+		return array_values( array_intersect_key( $row, array_flip( $names ) ) );
+	}
+
+	/** Return DTO identifiers. */
+	private function ids( array $logs ): array {
+		return array_map(
+			static function ( SyncLog $log ): int {
+				return (int) $log->id;
+			},
+			$logs
+		);
+	}
+
+	/** Assert a start failure is safe and actionable. */
+	private function assert_start_fails( SyncLogRepository $repository ): void {
+		try {
+			$repository->start();
+			self::fail( 'A failed synchronization-log insert should throw.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
+			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
+		}
+	}
+
+	/** Assert a finish failure is safe and actionable. */
+	private function assert_finish_fails( SyncLogRepository $repository, int $id, string $status ): void {
+		try {
+			$repository->finish( $id, $status, array() );
+			self::fail( 'An invalid synchronization-log finish should throw.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'synchronization history', strtolower( $exception->getMessage() ) );
+			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
+		}
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
```

## Current Uncommitted Status

Command: `git status --short --branch --untracked-files=all`

```text
## feat/cinebot-wp
 M specs/execution-status.yaml
 M specs/state.yaml
?? .superpowers/sdd/progress.md
?? .superpowers/sdd/task-1-review-package.md
?? .superpowers/sdd/task-1-review.md
?? .superpowers/sdd/task-2-brief.md
?? .superpowers/sdd/task-2-review-package.md
?? .superpowers/sdd/task-2-review.md
?? .superpowers/sdd/task-3-brief.md
?? .superpowers/sdd/task-3-review-package.md
?? .superpowers/sdd/task-3-review.md
?? .superpowers/sdd/task-4-brief.md
?? .superpowers/sdd/task-4-review-package.md
?? .superpowers/sdd/task-4-review.md
?? .superpowers/sdd/task-5-brief.md
?? .superpowers/sdd/task-5-review-package.md
?? .superpowers/sdd/task-5-review.md
?? .superpowers/sdd/task-6-brief.md
?? .superpowers/sdd/task-6-review-package.md
?? .superpowers/sdd/task-6-review.md
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 6 commits. No Task 6 implementation file is currently modified or untracked.
