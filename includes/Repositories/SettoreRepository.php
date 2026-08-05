<?php
/**
 * Sector persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\Settore;
use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Persists event sectors and reconciliation state.
 */
final class SettoreRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/** Store the injected database connection. */
	public function __construct( wpdb $db ) {
		$this->db = $db;
		$this->table = $db->prefix . 'cinebot_settori';
	}

	/** Find a sector by its event-scoped API identity. */
	public function findByRemoteId( int $eventId, int $remoteId ): ?Settore {
		if ( $eventId <= 0 || $remoteId <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE evento_id = %d AND idsettore = %d", $eventId, $remoteId ), ARRAY_A );
		return is_array( $row ) ? Settore::fromArray( $row ) : null;
	}

	/**
	 * Insert or update a sector.
	 *
	 * @throws InvalidArgumentException When source is invalid.
	 * @throws RuntimeException When persistence fails.
	 */
	public function save( Settore $sector ): int {
		$this->assert_source( $sector->source );
		$manual = 'manual' === $sector->source;
		$now = current_time( 'mysql', true );
		$data = array(
			'idsettore' => $sector->idsettore,
			'evento_id' => $sector->eventoId,
			'nome' => $sector->nome,
			'source' => $sector->source,
			'sync_active' => $manual ? 1 : $sector->syncActive,
			'last_seen_sync' => $manual ? null : $sector->lastSeenSync,
			'updated_at' => $now,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $sector->id ) {
			$data['created_at'] = $now;
			$formats[] = '%s';
			$result = $this->db->insert( $this->table, $data, $formats );
			$id = (int) $this->db->insert_id;
		} else {
			$id = (int) $sector->id;
			if ( $id <= 0 || $sector->source !== $this->source_for_id( $id ) ) {
				throw $this->save_exception();
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
		}
		if ( false === $result || $id <= 0 ) {
			throw $this->save_exception();
		}
		return $id;
	}

	/**
	 * Return all sectors for an event.
	 *
	 * @return array<int,Settore>
	 */
	public function findByEventoId( int $eventId ): array {
		if ( $eventId <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE evento_id = %d ORDER BY id ASC", $eventId ), ARRAY_A );
		return array_map(
			static function ( array $row ): Settore {
				return Settore::fromArray( $row );
			},
			$rows
		);
	}

	/** Return whether a positive sector belongs to the exact positive event. */
	public function belongsToEvento( int $sectorId, int $eventId ): bool {
		if ( $sectorId <= 0 || $eventId <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $sectorId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND evento_id = %d", $sectorId, $eventId ) );
	}

	/** Delete all sectors directly owned by an event. */
	public function deleteByEventoId( int $eventId ): int {
		if ( $eventId <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->delete( $this->table, array( 'evento_id' => $eventId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Deactivate unseen API sectors under one event.
	 *
	 * @return array<int,int> Affected sector IDs.
	 */
	public function deactivateUnseenApi( int $eventId, string $syncToken ): array {
		if ( $eventId <= 0 ) {
			return array();
		}
		return $this->deactivate_where( 'evento_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)', array( $eventId, 'api', 1, $syncToken ) );
	}

	/**
	 * Deactivate direct API sectors for event IDs.
	 *
	 * @param array<int,int> $eventIds Parent event IDs.
	 * @return array<int,int> Affected sector IDs.
	 */
	public function deactivateByEventoIds( array $eventIds ): array {
		$ids = $this->positive_ids( $eventIds );
		if ( array() === $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->deactivate_where( "evento_id IN ({$marks}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) );
	}

	/** Delete exactly one sector by local ID. */
	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/** Return the stored ownership source for update validation. */
	private function source_for_id( int $id ): ?string {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$source = $this->db->get_var( $this->db->prepare( "SELECT source FROM {$this->table} WHERE id = %d", $id ) );
		return is_string( $source ) ? $source : null;
	}

	/**
	 * Deactivate rows selected by one fixed internal predicate.
	 *
	 * @param array<int,mixed> $values Prepared values.
	 * @return array<int,int> Affected local IDs.
	 */
	private function deactivate_where( string $where, array $values ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( $this->db->prepare( "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id IN ({$marks})", array_merge( array( current_time( 'mysql', true ) ), $ids ) ) );
		return $ids;
	}

	/**
	 * Normalize parent IDs for a prepared IN predicate.
	 *
	 * @param array<int,mixed> $ids Raw IDs.
	 * @return array<int,int> Positive unique IDs.
	 */
	private function positive_ids( array $ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( int $id ): bool {
						return $id > 0;
					}
				)
			)
		);
	}

	/** Validate the persisted ownership source. */
	private function assert_source( string $source ): void {
		if ( ! in_array( $source, array( 'api', 'manual' ), true ) ) {
			throw new InvalidArgumentException( esc_html__( 'A sector source must be api or manual.', 'cinebot-wp' ) );
		}
	}

	/** Build a safe persistence exception. */
	private function save_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not save the sector. Verify its identifiers and try again.', 'cinebot-wp' ) );
	}
}
