<?php
/**
 * Price persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\Prezzo;
use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Persists sector prices and reconciliation state.
 */
final class PrezzoRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/** Store the injected database connection. */
	public function __construct( wpdb $db ) {
		$this->db = $db;
		$this->table = $db->prefix . 'cinebot_prezzi';
	}

	/** Find a price by its sector-scoped API identity. */
	public function findByRemoteId( int $sectorId, int $remoteId ): ?Prezzo {
		if ( $sectorId <= 0 || $remoteId <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE settore_id = %d AND idprezzo = %d", $sectorId, $remoteId ), ARRAY_A );
		return is_array( $row ) ? Prezzo::fromArray( $row ) : null;
	}

	/**
	 * Insert or update a price.
	 *
	 * @throws InvalidArgumentException When source is invalid.
	 * @throws RuntimeException When persistence fails.
	 */
	public function save( Prezzo $price ): int {
		$this->assert_source( $price->source );
		$manual = 'manual' === $price->source;
		$now = current_time( 'mysql', true );
		$data = array(
			'idprezzo' => $price->idprezzo,
			'settore_id' => $price->settoreId,
			'nome' => $price->nome,
			'tipo' => $price->tipo,
			'importo' => $price->importo,
			'prevendita' => $price->prevendita,
			'stato' => $price->stato,
			'source' => $price->source,
			'sync_active' => $manual ? 1 : $price->syncActive,
			'last_seen_sync' => $manual ? null : $price->lastSeenSync,
			'updated_at' => $now,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $price->id ) {
			$data['created_at'] = $now;
			$formats[] = '%s';
			$result = $this->db->insert( $this->table, $data, $formats );
			$id = (int) $this->db->insert_id;
		} else {
			$id = (int) $price->id;
			if ( $id <= 0 || $price->source !== $this->source_for_id( $id ) ) {
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
	 * Return all prices for a sector.
	 *
	 * @return array<int,Prezzo>
	 */
	public function findBySettoreId( int $sectorId ): array {
		if ( $sectorId <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE settore_id = %d ORDER BY id ASC", $sectorId ), ARRAY_A );
		return array_map(
			static function ( array $row ): Prezzo {
				return Prezzo::fromArray( $row );
			},
			$rows
		);
	}

	/** Return whether a positive price belongs to the exact positive sector. */
	public function belongsToSettore( int $priceId, int $sectorId ): bool {
		if ( $priceId <= 0 || $sectorId <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $priceId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND settore_id = %d", $priceId, $sectorId ) );
	}

	/** Delete all prices directly owned by a sector. */
	public function deleteBySettoreId( int $sectorId ): int {
		if ( $sectorId <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->delete( $this->table, array( 'settore_id' => $sectorId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Deactivate unseen API prices under one sector.
	 *
	 * @return array<int,int> Affected price IDs.
	 */
	public function deactivateUnseenApi( int $sectorId, string $syncToken ): array {
		if ( $sectorId <= 0 ) {
			return array();
		}
		return $this->deactivate_where( 'settore_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)', array( $sectorId, 'api', 1, $syncToken ) );
	}

	/**
	 * Deactivate direct API prices for sector IDs.
	 *
	 * @param array<int,int> $sectorIds Parent sector IDs.
	 */
	public function deactivateBySettoreIds( array $sectorIds ): int {
		$ids = $this->positive_ids( $sectorIds );
		if ( array() === $ids ) {
			return 0;
		}
		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return count( $this->deactivate_where( "settore_id IN ({$marks}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) ) );
	}

	/** Delete exactly one price by local ID. */
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
		$affected = array();
		$now = current_time( 'mysql', true );
		foreach ( $ids as $id ) {
			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
			// The predicate is fixed internally and every dynamic value is prepared.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $this->db->query( $this->db->prepare( $sql, array_merge( array( $now, $id ), $values ) ) );
			if ( false === $result ) {
				throw $this->reconciliation_exception();
			}
			if ( 1 === $result ) {
				$affected[] = $id;
			}
		}
		return $affected;
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
			throw new InvalidArgumentException( esc_html__( 'A price source must be api or manual.', 'cinebot-wp' ) );
		}
	}

	/** Build a safe persistence exception. */
	private function save_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not save the price. Verify its identifiers and try again.', 'cinebot-wp' ) );
	}

	/** Build a safe reconciliation exception. */
	private function reconciliation_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule prices. Try again.', 'cinebot-wp' ) );
	}
}
