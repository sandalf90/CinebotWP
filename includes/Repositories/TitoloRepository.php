<?php
/**
 * Title persistence and schedule reads.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\Titolo;
use CinebotWp\ReadModels\EventoRiga;
use CinebotWp\ReadModels\ProgrammazioneCard;
use CinebotWp\ReadModels\TitoloDetail;
use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Persists titles and owns the public joined schedule projection.
 */
final class TitoloRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/** Store the injected database connection. */
	public function __construct( wpdb $db ) {
		$this->db = $db;
		$this->table = $db->prefix . 'cinebot_titoli';
	}

	/** Find a title by local ID. */
	public function find( int $id ): ?Titolo {
		if ( $id <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** Find a title by its globally unique API identity. */
	public function findByRemoteId( int $remoteId ): ?Titolo {
		if ( $remoteId <= 0 ) {
			return null;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE idtitolo = %d", $remoteId ), ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/**
	 * Load the aggregated detail projection for a title.
	 *
	 * Joins visible events with their venue names and per-event price ranges,
	 * then computes title-level aggregates (overall price min/max, distinct
	 * days, first/last day, distinct venue names).
	 *
	 * @return TitoloDetail|null Null when the title does not exist.
	 */
	public function findDetail( int $titleId ): ?TitoloDetail {
		$title = $this->find( $titleId );
		if ( null === $title ) {
			return null;
		}

		$base   = $this->db->prefix . 'cinebot_';
		$sql    = "SELECT e.id evento_id, e.inizio, e.url_acquisto, l.nome locale_nome, t.prezzo_da as prezzo_da, t.prezzo_a as prezzo_a FROM {$base}eventi e INNER JOIN {$base}locali l ON l.id = e.locale_id INNER JOIN {$base}titoli t ON t.id = e.titolo_id WHERE e.titolo_id = %d AND e.sync_active = %d AND e.stato = %d ORDER BY e.inizio ASC, e.id ASC";

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, array( $titleId, 1, 3 ) ), ARRAY_A );

		$detail           = new TitoloDetail();
		$detail->title    = $title;
		$detail->eventi   = array_map(
			static function ( array $row ): EventoRiga {
				return EventoRiga::fromRow( $row );
			},
			is_array( $rows ) ? $rows : array()
		);
		$detail->eventiCount = count( $detail->eventi );

		$days       = array();
		$locali     = array();

		foreach ( $detail->eventi as $riga ) {
			$day = substr( $riga->inizio, 0, 10 );
			if ( '' !== $day && ! in_array( $day, $days, true ) ) {
				$days[] = $day;
			}
			if ( '' !== $riga->localeNome && ! in_array( $riga->localeNome, $locali, true ) ) {
				$locali[] = $riga->localeNome;
			}
		}

		sort( $days );
		$detail->prezzoDa     = $title->prezzoDa;
		$detail->prezzoA      = $title->prezzoA;
		$detail->prevenditaDa = $title->prevenditaDa;
		$detail->prevenditaA  = $title->prevenditaA;
		$detail->giorniCount  = count( $days );
		$detail->primoGiorno  = $days ? $days[0] : null;
		$detail->ultimoGiorno = $days ? $days[ count( $days ) - 1 ] : null;
		$detail->localeNomi   = $locali ? implode( ', ', $locali ) : null;

		return $detail;
	}

	/**
	 * Insert or update a title.
	 *
	 * @throws InvalidArgumentException When source is invalid.
	 * @throws RuntimeException When JSON encoding or persistence fails.
	 */
	public function save( Titolo $title ): int {
		if ( ! in_array( $title->source, array( 'api', 'manual' ), true ) ) {
			throw new InvalidArgumentException( esc_html__( 'A title source must be api or manual.', 'cinebot-wp' ) );
		}
		$tag = wp_json_encode( $title->tag );
		if ( false === $tag ) {
			throw new RuntimeException( esc_html__( 'Cinebot WP could not encode the title tags.', 'cinebot-wp' ) );
		}
		$manual = 'manual' === $title->source;
		$now = current_time( 'mysql', true );
		$data = array(
			'idtitolo' => $title->idtitolo,
			'frontend_id' => $title->frontendId,
			'titolo' => $title->titolo,
			'autore' => $title->autore,
			'esecutore' => $title->esecutore,
			'durata' => $title->durata,
			'scadenza' => $title->scadenza,
			'descrizione' => $title->descrizione,
			'tipoevento_codice' => $title->tipoeventoCodice,
			'locandina_flag' => $title->locandinaFlag,
			'locandina_url' => $title->locandinaUrl,
			'cinetel' => $title->cinetel,
			'tmdb' => $title->tmdb,
			'trailer' => $title->trailer,
			'cast' => $title->cast,
			'tag' => $tag,
			'prezzo_da' => $title->prezzoDa,
			'prezzo_a' => $title->prezzoA,
			'prevendita_da' => $title->prevenditaDa,
			'prevendita_a' => $title->prevenditaA,
			'source' => $title->source,
			'sync_hash' => $title->syncHash,
			'sync_active' => $manual ? 1 : $title->syncActive,
			'last_seen_sync' => $manual ? null : $title->lastSeenSync,
			'updated_at' => $now,
		);
		$formats = array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $title->id ) {
			$data['created_at'] = $now;
			$formats[] = '%s';
			$result = $this->db->insert( $this->table, $data, $formats );
			$id = (int) $this->db->insert_id;
		} else {
			$id = (int) $title->id;
			$stored = $id > 0 ? $this->find( $id ) : null;
			if ( null === $stored || $stored->source !== $title->source ) {
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
	 * Search admin titles using fixed ordering.
	 *
	 * @param array<string,mixed> $filters Supported type, source, and search filters.
	 * @return array<int,Titolo>
	 */
	public function search( array $filters, int $page, int $perPage ): array {
		$page = max( 1, $page );
		$perPage = max( 1, $perPage );
		$predicate = $this->admin_predicate( $filters );
		$sql = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY titolo ASC, id ASC LIMIT %d OFFSET %d";
		$values = array_merge( $predicate['values'], array( $perPage, ( $page - 1 ) * $perPage ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), $rows );
	}

	/**
	 * Count titles using the same predicates as search.
	 *
	 * @param array<string,mixed> $filters Admin filters.
	 */
	public function count( array $filters = array() ): int {
		$predicate = $this->admin_predicate( $filters );
		$sql = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";
		if ( array() !== $predicate['values'] ) {
			$sql = $this->db->prepare( $sql, $predicate['values'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $sql );
	}

	/**
	 * Return dashboard counters.
	 *
	 * @return array{titoli_totali:int,titoli_manuali:int,eventi_totali:int,locali_totali:int,tipologie_attive:int}
	 */
	public function statistics(): array {
		$base = $this->db->prefix . 'cinebot_';
		// All identifiers and scalar predicates are fixed internal fragments.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array(
			'titoli_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}titoli" ),
			'titoli_manuali' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$base}titoli WHERE source = %s", 'manual' ) ),
			'eventi_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}eventi" ),
			'locali_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}locali" ),
			'tipologie_attive' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$base}tipologie_eventi WHERE attivo = %d", 1 ) ),
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Count titles assigned to an exact string type code. */
	public function countByTypeCode( string $code ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE tipoevento_codice = %s", $code ) );
	}

	/**
	 * Return one public read model per visible event.
	 *
	 * @param array<string,mixed> $filters Public filters and pagination.
	 * @return array<int,ProgrammazioneCard>
	 */
	public function findPublicSchedule( array $filters ): array {
		$query = $this->public_query( $filters );
		$orderby = isset( $filters['orderby'] ) && 'titolo' === $filters['orderby'] ? 't.titolo' : 'e.inizio';
		$order = isset( $filters['order'] ) && in_array( strtoupper( (string) $filters['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( (string) $filters['order'] ) : 'ASC';
		$limit = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 50;
		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$sql = $this->public_projection_sql() . $query['joins'] . $query['where'] . " ORDER BY {$orderby} {$order}, e.id ASC LIMIT %d OFFSET %d";
		$values = array_merge( $query['values'], array( $limit, $offset ) );
		// Projection, joins, and ordering are fixed or allowlisted; all values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
		return array_map(
			static function ( array $row ): ProgrammazioneCard {
				return ProgrammazioneCard::fromRow( $row );
			},
			$rows
		);
	}

	/**
	 * Count public events using the projection's visibility predicates.
	 *
	 * @param array<string,mixed> $filters Public filters.
	 */
	public function countPublicSchedule( array $filters ): int {
		$query = $this->public_query( $filters );
		$base = $this->db->prefix . 'cinebot_';
		$sql = "SELECT COUNT(*) FROM {$base}eventi e" . $query['joins'] . $query['where'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( $this->db->prepare( $sql, $query['values'] ) );
	}

	/**
	 * Deactivate unseen API titles in one frontend scope.
	 *
	 * @return array<int,int> Affected title IDs.
	 */
	public function deactivateUnseenApi( int $frontendId, string $syncToken ): array {
		if ( $frontendId <= 0 ) {
			return array();
		}
		$where = 'frontend_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)';
		$values = array( $frontendId, 'api', 1, $syncToken );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
		if ( array() === $ids ) {
			return array();
		}
		$affected = array();
		$now = current_time( 'mysql', true );
		foreach ( $ids as $id ) {
			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
			// The predicate is fixed and every dynamic value is prepared.
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

	/** Delete exactly one title by local ID. */
	public function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * @param array<string,mixed> $filters Admin filters.
	 * @return array{sql:string,values:array<int,string>}
	 */
	private function admin_predicate( array $filters ): array {
		$clauses = array();
		$values = array();
		$type = isset( $filters['tipoevento_codice'] ) ? trim( sanitize_text_field( (string) $filters['tipoevento_codice'] ) ) : '';
		if ( '' !== $type ) {
			$clauses[] = 'tipoevento_codice = %s';
			$values[] = $type;
		}
		$source = isset( $filters['source'] ) ? trim( sanitize_text_field( (string) $filters['source'] ) ) : '';
		if ( in_array( $source, array( 'api', 'manual' ), true ) ) {
			$clauses[] = 'source = %s';
			$values[] = $source;
		}
		$search = isset( $filters['search'] ) ? trim( sanitize_text_field( (string) $filters['search'] ) ) : '';
		if ( '' !== $search ) {
			$like = '%' . $this->db->esc_like( $search ) . '%';
			$clauses[] = '(LOWER(titolo) LIKE LOWER(%s) OR LOWER(autore) LIKE LOWER(%s))';
			$values[] = $like;
			$values[] = $like;
		}
		return array( 'sql' => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ), 'values' => $values );
	}

	/**
	 * Build public joins and shared visibility predicates.
	 *
	 * @param array<string,mixed> $filters Public filters.
	 * @return array{joins:string,where:string,values:array<int,mixed>}
	 */
	private function public_query( array $filters ): array {
		$base = $this->db->prefix . 'cinebot_';
		$joins = " INNER JOIN {$base}titoli t ON t.id = e.titolo_id INNER JOIN {$base}tipologie_eventi ty ON ty.codice = t.tipoevento_codice AND ty.attivo = 1 INNER JOIN {$base}locali l ON l.id = e.locale_id";
		$clauses = array( 't.sync_active = %d', 'e.sync_active = %d', 'e.stato = %d', 'e.inizio >= %s' );
		$from = isset( $filters['from'] ) && '' !== trim( (string) $filters['from'] ) ? sanitize_text_field( (string) $filters['from'] ) : current_time( 'Y-m-d', true );
		$values = array( 1, 1, 3, $from );
		if ( isset( $filters['to'] ) && '' !== trim( (string) $filters['to'] ) ) {
			$clauses[] = "e.inizio < DATE_ADD(%s, INTERVAL 1 DAY)";
			$values[] = sanitize_text_field( (string) $filters['to'] );
		}
		if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
			$clauses[] = 'ty.codice = %s';
			$values[] = sanitize_text_field( (string) $filters['tipo'] );
		} elseif ( isset( $filters['exclude_tipo'] ) && '' !== trim( (string) $filters['exclude_tipo'] ) ) {
			$clauses[] = 'ty.codice != %s';
			$values[] = sanitize_text_field( (string) $filters['exclude_tipo'] );
		}
		$locale = isset( $filters['locale'] ) ? (int) $filters['locale'] : 0;
		if ( $locale > 0 ) {
			$clauses[] = 'l.id = %d';
			$values[] = $locale;
		}
		if ( isset( $filters['comune'] ) && '' !== trim( (string) $filters['comune'] ) ) {
			$clauses[] = 'l.comune = %s';
			$values[] = sanitize_text_field( (string) $filters['comune'] );
		}
		return array( 'joins' => $joins, 'where' => ' WHERE ' . implode( ' AND ', $clauses ), 'values' => $values );
	}

	/** Return the fixed public projection and active-price subquery. */
	private function public_projection_sql(): string {
		$base = $this->db->prefix . 'cinebot_';
		return "SELECT e.id evento_id, e.inizio, t.id titolo_id, t.titolo, COALESCE(t.descrizione, '') descrizione, t.locandina_url, ty.codice tipo_codice, ty.descrizione tipo_descrizione, l.id locale_id, l.nome locale_nome, l.comune, t.prezzo_da as prezzo_da, t.prezzo_a as prezzo_a, t.prevendita_da as prevendita_da, t.prevendita_a as prevendita_a FROM {$base}eventi e";
	}

	/**
	 * Hydrate a title while decoding its JSON tag field safely.
	 *
	 * @param array<string,mixed> $row Database row.
	 */
	private function hydrate( array $row ): Titolo {
		$decoded = isset( $row['tag'] ) && is_string( $row['tag'] ) ? json_decode( $row['tag'], true ) : array();
		$row['tag'] = is_array( $decoded ) ? $decoded : array();
		return Titolo::fromArray( $row );
	}

	/** Build a safe persistence exception. */
	private function save_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not save the title. Verify its identifiers and try again.', 'cinebot-wp' ) );
	}

	/** Build a safe reconciliation exception. */
	private function reconciliation_exception(): RuntimeException {
		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule titles. Try again.', 'cinebot-wp' ) );
	}
}
