<?php
/**
 * Synchronization history persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\SyncLog;
use DateTimeImmutable;
use RuntimeException;
use wpdb;

/**
 * Persists synchronization lifecycle records and history queries.
 */
final class SyncLogRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/** @var callable():string */
	private $clock;

	/** Store the injected database connection and UTC clock. */
	public function __construct( wpdb $db, ?callable $clock = null ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'cinebot_sync_log';
		$this->clock = $clock ?? static function (): string {
			return current_time( 'mysql', true );
		};
	}

	/**
	 * Start a synchronization run.
	 *
	 * @throws RuntimeException When the history row cannot be stored.
	 */
	public function start( string $payloadHash = '' ): int {
		// wpdb::insert prepares every dynamic value using the supplied formats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->insert(
			$this->table,
			array(
				'started_at'      => ( $this->clock )(),
				'finished_at'     => null,
				'status'          => 'running',
				'titoli_added'    => 0,
				'titoli_updated'  => 0,
				'eventi_added'    => 0,
				'eventi_updated'  => 0,
				'error_message'   => null,
				'payload_hash'    => '' === $payloadHash ? null : $payloadHash,
			),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		$id = (int) $this->db->insert_id;
		if ( false === $result || $id <= 0 ) {
			throw $this->persistence_exception();
		}

		return $id;
	}

	/**
	 * Finish an existing synchronization run.
	 *
	 * @param array<string,mixed> $stats Synchronization counters.
	 * @throws RuntimeException When the outcome or target is invalid, or persistence fails.
	 */
	public function finish( int $id, string $status, array $stats, ?string $error = null ): void {
		if ( $id <= 0 || ! in_array( $status, array( 'success', 'error', 'partial' ), true ) ) {
			throw $this->persistence_exception();
		}

		$data = array(
			'finished_at'     => ( $this->clock )(),
			'status'          => $status,
			'titoli_added'    => $this->counter( $stats, 'titoli_added' ),
			'titoli_updated'  => $this->counter( $stats, 'titoli_updated' ),
			'eventi_added'    => $this->counter( $stats, 'eventi_added' ),
			'eventi_updated'  => $this->counter( $stats, 'eventi_updated' ),
			'error_message'   => null === $error ? null : sanitize_textarea_field( $error ),
		);
		// wpdb::update prepares every dynamic value and the identifier predicate.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->update(
			$this->table,
			$data,
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
		if ( 1 !== $result ) {
			throw $this->persistence_exception();
		}
	}

	/** Return the newest synchronization row. */
	public function latest(): ?SyncLog {
		// The table identifier and ordering are fixed trusted fragments.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( "SELECT * FROM {$this->table} ORDER BY started_at DESC, id DESC LIMIT 1", ARRAY_A );
		// phpcs:enable

		return is_array( $row ) ? SyncLog::fromArray( $row ) : null;
	}

	/**
	 * Return recent synchronization rows newest first.
	 *
	 * @return array<int,SyncLog>
	 */
	public function recent( int $limit = 5 ): array {
		$limit = max( 1, min( 100, $limit ) );
		// The table identifier and ordering are fixed; the limit is prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results(
			$this->db->prepare(
				"SELECT * FROM {$this->table} ORDER BY started_at DESC, id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		// phpcs:enable

		return $this->hydrate_all( $rows );
	}

	/**
	 * Search synchronization history with fixed newest-first ordering.
	 *
	 * @param array<string,mixed> $filters Supported status, from, and to filters.
	 * @return array<int,SyncLog>
	 */
	public function search( array $filters, int $page, int $perPage ): array {
		$page      = max( 1, $page );
		$perPage   = max( 1, $perPage );
		$predicate = $this->predicate( $filters );
		$sql       = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY started_at DESC, id DESC LIMIT %d OFFSET %d";
		$values    = array_merge( $predicate['values'], array( $perPage, ( $page - 1 ) * $perPage ) );
		// The table identifier and SQL fragments are fixed; every value is prepared.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
		// phpcs:enable

		return $this->hydrate_all( $rows );
	}

	/**
	 * Count synchronization history using the search predicates.
	 *
	 * @param array<string,mixed> $filters Supported status, from, and to filters.
	 */
	public function count( array $filters = array() ): int {
		$predicate = $this->predicate( $filters );
		$sql       = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";
		if ( array() !== $predicate['values'] ) {
			$sql = $this->db->prepare( $sql, $predicate['values'] );
		}
		// The table identifier and predicate fragments are fixed, and dynamic values were prepared above.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $this->db->get_var( $sql );
		// phpcs:enable

		return $count;
	}

	/**
	 * Delete history strictly older than a UTC cutoff.
	 *
	 * @throws RuntimeException When deletion fails.
	 */
	public function deleteOlderThan( DateTimeImmutable $cutoff ): int {
		// The table identifier and comparison are fixed; the cutoff is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $this->db->query(
			$this->db->prepare(
				"DELETE FROM {$this->table} WHERE started_at < %s",
				$cutoff->format( 'Y-m-d H:i:s' )
			)
		);
		if ( false === $result ) {
			throw $this->persistence_exception();
		}

		return $result;
	}

	/** Return one approved counter as a nonnegative integer. */
	private function counter( array $stats, string $name ): int {
		return isset( $stats[ $name ] ) ? max( 0, (int) $stats[ $name ] ) : 0;
	}

	/**
	 * Build shared search/count predicates.
	 *
	 * @param array<string,mixed> $filters Supported status, from, and to filters.
	 * @return array{sql:string,values:array<int,string>}
	 */
	private function predicate( array $filters ): array {
		$clauses = array();
		$values  = array();
		$status  = isset( $filters['status'] ) ? (string) $filters['status'] : '';
		if ( in_array( $status, array( 'running', 'success', 'error', 'partial' ), true ) ) {
			$clauses[] = 'status = %s';
			$values[]  = $status;
		}

		foreach ( array( 'from' => '>=', 'to' => '<=' ) as $name => $operator ) {
			$value = isset( $filters[ $name ] ) ? (string) $filters[ $name ] : '';
			if ( $this->is_mysql_timestamp( $value ) ) {
				$clauses[] = "started_at {$operator} %s";
				$values[]  = $value;
			}
		}

		return array(
			'sql'    => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/** Return whether a value is an exact valid MySQL UTC timestamp shape. */
	private function is_mysql_timestamp( string $value ): bool {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value );

		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	/**
	 * Hydrate database rows into synchronization DTOs.
	 *
	 * @param array<int,array<string,mixed>> $rows Database rows.
	 * @return array<int,SyncLog>
	 */
	private function hydrate_all( array $rows ): array {
		return array_map(
			static function ( array $row ): SyncLog {
				return SyncLog::fromArray( $row );
			},
			$rows
		);
	}

	/** Return a safe persistence failure without database or credential details. */
	private function persistence_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not update synchronization history.', 'cinebot-wp' ) );
	}
}
