<?php
/**
 * Venue persistence.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Repositories;

use CinebotWp\Models\Locale;
use InvalidArgumentException;
use RuntimeException;
use wpdb;

/**
 * Persists manual and API-owned venues in the plugin table.
 */
final class LocaleRepository {
	/** @var wpdb */
	private $db;

	/** @var string */
	private $table;

	/**
	 * Store the injected database connection.
	 */
	public function __construct( wpdb $db ) {
		$this->db    = $db;
		$this->table = $db->prefix . 'cinebot_locali';
	}

	/**
	 * Find a venue by local ID.
	 */
	public function find( int $id ): ?Locale {
		if ( $id <= 0 ) {
			return null;
		}

		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );

		return is_array( $row ) ? Locale::fromArray( $row ) : null;
	}

	/**
	 * Find a venue by globally unique API ID.
	 */
	public function findByRemoteId( int $remoteId ): ?Locale {
		if ( $remoteId <= 0 ) {
			return null;
		}

		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE locale_id_remoto = %d", $remoteId ), ARRAY_A );

		return is_array( $row ) ? Locale::fromArray( $row ) : null;
	}

	/**
	 * Insert or update a venue and return its local ID.
	 *
	 * @throws InvalidArgumentException When the source is not api or manual.
	 * @throws RuntimeException When the row cannot be stored.
	 */
	public function save( Locale $locale ): int {
		if ( ! in_array( $locale->source, array( 'api', 'manual' ), true ) ) {
			throw new InvalidArgumentException( esc_html__( 'A venue source must be api or manual.', 'cinebot-wp' ) );
		}

		$now  = current_time( 'mysql', true );
		$data = array(
			'locale_id_remoto' => $locale->localeIdRemoto,
			'nome'             => $locale->nome,
			'codice'           => $locale->codice,
			'indirizzo'        => $locale->indirizzo,
			'cap'              => $locale->cap,
			'comune'           => $locale->comune,
			'provincia'        => $locale->provincia,
			'mappa'            => $locale->mappa,
			'source'           => $locale->source,
			'updated_at'       => $now,
		);
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );

		// wpdb prepares every mapped value using the explicit formats.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( null === $locale->id ) {
			$data['created_at'] = $now;
			$formats[]          = '%s';
			$result             = $this->db->insert( $this->table, $data, $formats );
			$id                 = (int) $this->db->insert_id;
		} else {
			$id = (int) $locale->id;
			if ( $id <= 0 || null === $this->find( $id ) ) {
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
	 * Insert or update one API venue without overwriting manual ownership.
	 *
	 * @param array<string,mixed> $data API venue data.
	 * @throws InvalidArgumentException When required API identity data is invalid.
	 */
	public function upsertApi( array $data ): int {
		$remote_id = isset( $data['localeId'] ) ? (int) $data['localeId'] : 0;
		$name      = isset( $data['locale'] ) ? trim( sanitize_text_field( (string) $data['locale'] ) ) : '';
		if ( $remote_id <= 0 || '' === $name ) {
			throw new InvalidArgumentException( esc_html__( 'An API venue requires a positive localeId and a non-empty locale name.', 'cinebot-wp' ) );
		}

		$existing = $this->findByRemoteId( $remote_id );
		if ( null !== $existing && 'manual' === $existing->source ) {
			return (int) $existing->id;
		}

		$locale                 = null !== $existing ? $existing : new Locale();
		$locale->localeIdRemoto = $remote_id;
		$locale->nome           = $name;
		$locale->codice         = $this->api_string( $data, 'localeCodice' );
		$locale->indirizzo      = $this->api_string( $data, 'indirizzo' );
		$locale->cap            = $this->api_string( $data, 'cap' );
		$locale->comune         = $this->api_string( $data, 'comune' );
		$locale->provincia      = $this->api_string( $data, 'provincia' );
		$locale->mappa          = isset( $data['mappa'] ) && null !== $data['mappa'] ? (int) $data['mappa'] : null;
		$locale->source         = 'api';

		return $this->save( $locale );
	}

	/**
	 * Search venues using fixed predicates and ordering.
	 *
	 * @param array<string,mixed> $filters Supported provincia, comune, and search filters.
	 * @return array<int,Locale>
	 */
	public function search( array $filters, int $page, int $perPage ): array {
		$page     = max( 1, $page );
		$perPage  = max( 1, $perPage );
		$offset   = ( $page - 1 ) * $perPage;
		$predicate = $this->filter_predicate( $filters );
		$sql       = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY nome ASC, id ASC LIMIT %d OFFSET %d";
		$values    = array_merge( $predicate['values'], array( $perPage, $offset ) );

		// Table and ordering identifiers are fixed; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );

		return array_map(
			static function ( array $row ): Locale {
				return Locale::fromArray( $row );
			},
			$rows
		);
	}

	/**
	 * Count venues using the same predicates as search.
	 *
	 * @param array<string,mixed> $filters Supported provincia, comune, and search filters.
	 */
	public function count( array $filters = array() ): int {
		$predicate = $this->filter_predicate( $filters );
		$sql       = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";

		if ( array() !== $predicate['values'] ) {
			$sql = $this->db->prepare( $sql, $predicate['values'] );
		}

		// The table identifier and predicates come only from internal fixed fragments.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		return (int) $this->db->get_var( $sql );
	}

	/**
	 * Build the shared fixed filter predicate and prepared values.
	 *
	 * @param array<string,mixed> $filters Raw boundary filters.
	 * @return array{sql:string,values:array<int,string>}
	 */
	private function filter_predicate( array $filters ): array {
		$clauses = array();
		$values  = array();

		$province = isset( $filters['provincia'] ) ? trim( sanitize_text_field( (string) $filters['provincia'] ) ) : '';
		if ( '' !== $province ) {
			$clauses[] = 'provincia = %s';
			$values[]  = $province;
		}

		$city = isset( $filters['comune'] ) ? trim( sanitize_text_field( (string) $filters['comune'] ) ) : '';
		if ( '' !== $city ) {
			$clauses[] = 'comune = %s';
			$values[]  = $city;
		}

		$search = isset( $filters['search'] ) ? trim( sanitize_text_field( (string) $filters['search'] ) ) : '';
		if ( '' !== $search ) {
			$clauses[] = '(LOWER(nome) LIKE LOWER(%s) OR LOWER(codice) LIKE LOWER(%s) OR LOWER(comune) LIKE LOWER(%s))';
			$like      = '%' . $this->db->esc_like( $search ) . '%';
			$values[]  = $like;
			$values[]  = $like;
			$values[]  = $like;
		}

		return array(
			'sql'    => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ),
			'values' => $values,
		);
	}

	/**
	 * Return a nullable sanitized string from one API field.
	 *
	 * @param array<string,mixed> $data API venue data.
	 */
	private function api_string( array $data, string $key ): ?string {
		if ( ! isset( $data[ $key ] ) || null === $data[ $key ] ) {
			return null;
		}

		return sanitize_text_field( (string) $data[ $key ] );
	}

	/**
	 * Build a safe venue persistence exception.
	 */
	private function save_exception(): RuntimeException {
		return new RuntimeException(
			esc_html__( 'Cinebot WP could not save the venue. Verify its identifiers and try again.', 'cinebot-wp' )
		);
	}
}
