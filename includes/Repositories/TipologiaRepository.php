<?php
/**
 * Event-type persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\TipologiaEvento;
use RuntimeException;
use wpdb;

/**
 * Persists event types in the plugin table.
 */
final class TipologiaRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/**
	 * Store the injected database connection.
	 */
	public function __construct( wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'cinebot_tipologie_eventi';
	}

	/**
	 * Find an event type by its string code.
	 */
	public function findByCode( string $code ): ?TipologiaEvento {
		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE codice = %s", $code ), ARRAY_A );

		return is_array( $row ) ? TipologiaEvento::fromArray( $row ) : null;
	}

	/**
	 * Return active event types that have at least one visible scheduled event.
	 *
	 * Mirrors the public schedule visibility predicates so the frontend
	 * filter dropdown only shows types that actually appear in results.
	 *
	 * @param string $from ISO date (Y-m-d). Defaults to today (UTC).
	 * @param string $to   Optional ISO upper-bound date (inclusive).
	 * @return array<int,TipologiaEvento>
	 */
	public function findUsedInSchedule( string $from = '', string $to = '' ): array {
		$base = $this->db->prefix . 'cinebot_';
		$from = '' !== trim( $from ) ? sanitize_text_field( $from ) : current_time( 'Y-m-d', true );

		$sql      = "SELECT DISTINCT ty.* FROM {$this->table} ty"
			. " INNER JOIN {$base}titoli t ON t.tipoevento_codice = ty.codice"
			. " INNER JOIN {$base}eventi e ON e.titolo_id = t.id"
			. " WHERE ty.attivo = %d AND t.sync_active = %d AND e.sync_active = %d AND e.stato = %d AND e.inizio >= %s";
		$values = array( 1, 1, 1, 3, $from );

		if ( '' !== trim( $to ) ) {
			$sql     .= " AND e.inizio < DATE_ADD(%s, INTERVAL 1 DAY)";
			$values[] = sanitize_text_field( $to );
		}

		$sql .= ' ORDER BY ty.codice ASC';

		// Table identifiers and predicates are fixed internal fragments; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );

		return array_map(
			static function ( array $row ): TipologiaEvento {
				return TipologiaEvento::fromArray( $row );
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Return event types in ascending string-code order.
	 *
	 * @return array<int,TipologiaEvento>
	 */
	public function findAll( bool $activeOnly = false ): array {
		$sql = "SELECT * FROM {$this->table}";
		if ( $activeOnly ) {
			$sql = $this->db->prepare( $sql . ' WHERE attivo = %d', 1 );
		}
		$sql .= ' ORDER BY codice ASC';

		// The table and ordering identifiers are internal fixed fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $sql, ARRAY_A );

		return array_map(
			static function ( array $row ): TipologiaEvento {
				return TipologiaEvento::fromArray( $row );
			},
			$rows
		);
	}

	/**
	 * Insert or update an event type and return its local ID.
	 *
	 * @throws RuntimeException When the row cannot be stored.
	 */
	public function save( TipologiaEvento $type ): int {
		$now  = current_time( 'mysql', true );
		$data = array(
			'codice'       => $type->codice,
			'descrizione'  => $type->descrizione,
			'predefinito'  => $type->predefinito,
			'attivo'       => $type->attivo,
			'updated_at'   => $now,
		);
		$formats = array( '%s', '%s', '%d', '%d', '%s' );

		// wpdb prepares every mapped value using the explicit formats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $type->id ) {
			$data['created_at'] = $now;
			$formats[]          = '%s';
			$result             = $this->db->insert( $this->table, $data, $formats );
			$id                 = (int) $this->db->insert_id;
		} else {
			$id = (int) $type->id;
			if ( $id <= 0 || null === $this->findById( $id ) ) {
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
	 * Enable or disable one event type.
	 *
	 * @throws RuntimeException When the ID is invalid, missing, or cannot be updated.
	 */
	public function setActive( int $id, bool $active ): void {
		if ( $id <= 0 || null === $this->findById( $id ) ) {
			throw new RuntimeException( esc_html__( 'Cinebot WP could not find the event type to update.', 'cinebot-wp' ) );
		}

		// wpdb prepares every mapped value using the explicit formats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->update(
			$this->table,
			array(
				'attivo'     => $active ? 1 : 0,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $id ),
			array( '%d', '%s' ),
			array( '%d' )
		);

		if ( false === $result ) {
			throw new RuntimeException( esc_html__( 'Cinebot WP could not update the event type status. Try again.', 'cinebot-wp' ) );
		}
	}

	/**
	 * Delete a custom event type only.
	 */
	public function deleteCustom( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		// The predefined predicate is part of the explicit delete boundary.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$deleted = $this->db->delete(
			$this->table,
			array(
				'id'          => $id,
				'predefinito' => 0,
			),
			array( '%d', '%d' )
		);

		return 1 === $deleted;
	}

	/**
	 * Find an event type by local ID.
	 */
	public function find( int $id ): ?TipologiaEvento {
		return $this->findById( $id );
	}

	/**
	 * Find an event type by local ID for mutation validation.
	 */
	private function findById( int $id ): ?TipologiaEvento {
		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? TipologiaEvento::fromArray( $row ) : null;
	}

	/**
	 * Build a safe event-type persistence exception.
	 */
	private function save_exception(): RuntimeException {
		return new RuntimeException(
			esc_html__( 'Cinebot WP could not save the event type. Verify that its code is unique and try again.', 'cinebot-wp' )
		);
	}
}
