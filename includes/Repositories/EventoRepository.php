<?php
/**
 * Event persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\Evento;
use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Persists events and exposes title-scoped reconciliation operations.
 */
final class EventoRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/** Store the injected database connection. */
	public function __construct( wpdb $db ) {
		$this->db = $db;
		$this->table = $db->prefix . 'cinebot_eventi';
	}

	/** Find an event by its globally unique API identity. */
	public function findByRemoteId( int $remoteId ): ?Evento {
		if ( $remoteId <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE idevento = %d", $remoteId ), ARRAY_A );
		return is_array( $row ) ? Evento::fromArray( $row ) : null;
	}

	/**
	 * Insert or update an event.
	 *
	 * @throws InvalidArgumentException When source is invalid.
	 * @throws RuntimeException When persistence fails.
	 */
	public function save( Evento $event ): int {
		$this->assert_source( $event->source );
		$manual = 'manual' === $event->source;
		$now = current_time( 'mysql', true );
		$data = array(
			'idevento'          => $event->idevento,
			'url_acquisto'      => $event->urlAcquisto,
			'titolo_id'         => $event->titoloId,
			'inizio'            => $event->inizio,
			'organizzatore_id'  => $event->organizzatoreId,
			'organizzatore_cf'  => $event->organizzatoreCf,
			'locale_id'         => $event->localeId,
			'stato'             => $event->stato,
			'otp'               => $event->otp,
			'controlloaccessi'  => $event->controlloaccessi,
			'mappa'              => $event->mappa,
			'source'             => $event->source,
			'sync_active'        => $manual ? 1 : $event->syncActive,
			'last_seen_sync'     => $manual ? null : $event->lastSeenSync,
			'updated_at'         => $now,
		);
		$formats = array( '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $event->id ) {
			$data['created_at'] = $now;
			$formats[] = '%s';
			$result = $this->db->insert( $this->table, $data, $formats );
			$id = (int) $this->db->insert_id;
		} else {
			$id = (int) $event->id;
			if ( $id <= 0 || $event->source !== $this->source_for_id( $id ) ) {
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
	 * Return all events for a title.
	 *
	 * @return array<int,Evento>
	 */
	public function findByTitoloId( int $titleId ): array {
		if ( $titleId <= 0 ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE titolo_id = %d ORDER BY inizio ASC, id ASC", $titleId ), ARRAY_A );
		return array_map(
			static function ( array $row ): Evento {
				return Evento::fromArray( $row );
			},
			$rows
		);
	}

	/** Return whether a positive event belongs to the exact positive title. */
	public function belongsToTitolo( int $eventId, int $titleId ): bool {
		if ( $eventId <= 0 || $titleId <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $eventId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND titolo_id = %d", $eventId, $titleId ) );
	}

	/** Count events directly owned by a title. */
	public function countByTitoloId( int $titleId ): int {
		return $this->count_by( 'titolo_id', $titleId );
	}

	/** Count events directly assigned to a venue. */
	public function countByLocaleId( int $localeId ): int {
		return $this->count_by( 'locale_id', $localeId );
	}

	/** Delete all events directly owned by a title. */
	public function deleteByTitoloId( int $titleId ): int {
		if ( $titleId <= 0 ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $this->db->delete( $this->table, array( 'titolo_id' => $titleId ), array( '%d' ) );
		return false === $result ? 0 : (int) $result;
	}

	/**
	 * Deactivate unseen API events under one title.
	 *
	 * @return array<int,int> Affected event IDs.
	 */
	public function deactivateUnseenApi( int $titleId, string $syncToken ): array {
		if ( $titleId <= 0 ) {
			return array();
		}
		$where = 'titolo_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)';
		return $this->deactivate_where( $where, array( $titleId, 'api', 1, $syncToken ) );
	}

	/**
	 * Deactivate direct API events for title IDs.
	 *
	 * @param array<int,int> $titleIds Parent title IDs.
	 * @return array<int,int> Affected event IDs.
	 */
	public function deactivateByTitoloIds( array $titleIds ): array {
		$ids = $this->positive_ids( $titleIds );
		if ( array() === $ids ) {
			return array();
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return $this->deactivate_where( "titolo_id IN ({$placeholders}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) );
	}

	/** Delete exactly one event by local ID. */
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

	/** Count rows by one fixed internal parent column. */
	private function count_by( string $column, int $id ): int {
		if ( $id <= 0 ) {
			return 0;
		}
		// Column is selected only by the two fixed public callers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$column} = %d", $id ) );
	}

	/**
	 * Deactivate rows selected by one fixed internal predicate.
	 *
	 * @param array<int,mixed> $values Prepared values.
	 * @return array<int,int> Affected local IDs.
	 */
	private function deactivate_where( string $where, array $values ): array {
		// The where fragment is assembled only by fixed internal callers.
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
			throw new InvalidArgumentException( esc_html__( 'An event source must be api or manual.', 'cinebot-wp' ) );
		}
	}

	/** Build a safe persistence exception. */
	private function save_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not save the event. Verify its identifiers and try again.', 'cinebot-wp' ) );
	}

	/** Build a safe reconciliation exception. */
	private function reconciliation_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule events. Try again.', 'cinebot-wp' ) );
	}
}
