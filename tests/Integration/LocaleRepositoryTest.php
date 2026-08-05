<?php
/**
 * Venue repository integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Test setup uses a trusted, fixed plugin table identifier.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Models\Locale;
use CinebotWp\Repositories\LocaleRepository;
use InvalidArgumentException;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies venue persistence and filtering behavior.
 */
final class LocaleRepositoryTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var LocaleRepository */
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
	 * Install the schema and clear venues before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		( new SchemaInstaller( self::$db ) )->install();
		self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_locali' );
		$this->repository = new LocaleRepository( self::$db );
	}

	/**
	 * Clear venues after each test.
	 */
	public function tear_down(): void {
		self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_locali' );

		parent::tear_down();
	}

	/**
	 * Manual venues can be inserted, found, and updated without replacing creation time.
	 */
	public function test_save_insert_find_and_update_preserve_manual_source_and_created_timestamp(): void {
		$locale               = $this->manual_locale( 'Cinema Centro', 'Roma', 'RM' );
		$locale->codice       = 'CC';
		$locale->indirizzo    = 'Via Uno 1';
		$locale->cap          = '00100';
		$locale->mappa        = 42;
		$id                   = $this->repository->save( $locale );
		$stored               = $this->repository->find( $id );

		self::assertGreaterThan( 0, $id );
		self::assertInstanceOf( Locale::class, $stored );
		self::assertSame( 'manual', $stored->source );
		self::assertSame( 'CC', $stored->codice );
		self::assertSame( 42, $stored->mappa );
		self::assertNotNull( $stored->createdAt );
		self::assertSame( $stored->createdAt, $stored->updatedAt );
		self::assertNull( $this->repository->find( PHP_INT_MAX ) );

		$old_updated_at = '2000-01-01 00:00:00';
		self::$db->update(
			self::$db->prefix . 'cinebot_locali',
			array( 'updated_at' => $old_updated_at ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
		$stored = $this->repository->find( $id );
		self::assertInstanceOf( Locale::class, $stored );
		self::assertSame( $old_updated_at, $stored->updatedAt );

		$created_at       = $stored->createdAt;
		$stored->nome     = 'Cinema Centro Nuovo';
		$stored->source   = 'manual';
		self::assertSame( $id, $this->repository->save( $stored ) );
		$updated = $this->repository->find( $id );
		self::assertInstanceOf( Locale::class, $updated );
		self::assertSame( 'Cinema Centro Nuovo', $updated->nome );
		self::assertSame( $created_at, $updated->createdAt );
		self::assertNotSame( $old_updated_at, $updated->updatedAt );
	}

	/**
	 * API upsert creates and updates API-owned venues.
	 */
	public function test_upsert_api_creates_and_updates_api_owned_venue(): void {
		$id = $this->repository->upsertApi(
			array(
				'localeId'     => 901,
				'locale'       => 'Arena API',
				'localeCodice' => 'API-1',
				'indirizzo'    => 'Via API 1',
				'cap'          => '20100',
				'comune'       => 'Milano',
				'provincia'    => 'MI',
				'mappa'        => 8,
			)
		);
		$created = $this->repository->findByRemoteId( 901 );

		self::assertInstanceOf( Locale::class, $created );
		self::assertSame( $id, $created->id );
		self::assertSame( 'api', $created->source );
		self::assertSame( 'Arena API', $created->nome );
		$created_at = $created->createdAt;

		self::assertSame(
			$id,
			$this->repository->upsertApi(
				array(
					'localeId'     => 901,
					'locale'       => 'Arena API Updated',
					'localeCodice' => 'API-2',
					'indirizzo'    => null,
					'cap'          => null,
					'comune'       => 'Monza',
					'provincia'    => 'MB',
					'mappa'        => 9,
				)
			)
		);
		$updated = $this->repository->findByRemoteId( 901 );
		self::assertInstanceOf( Locale::class, $updated );
		self::assertSame( 'Arena API Updated', $updated->nome );
		self::assertSame( 'API-2', $updated->codice );
		self::assertSame( 'api', $updated->source );
		self::assertSame( $created_at, $updated->createdAt );
	}

	/**
	 * API synchronization never overwrites a manually owned remote identity.
	 */
	public function test_upsert_api_returns_manual_match_unchanged(): void {
		$manual                 = $this->manual_locale( 'Manual owner', 'Torino', 'TO' );
		$manual->localeIdRemoto = 777;
		$manual->codice         = 'MANUAL-777';
		$manual->indirizzo      = 'Via Manuale 7';
		$manual->cap            = '10100';
		$manual->mappa          = 77;
		$id                     = $this->repository->save( $manual );
		$before                 = $this->repository->findByRemoteId( 777 );
		self::assertInstanceOf( Locale::class, $before );
		$before_state = $before->toArray();

		self::assertSame(
			$id,
			$this->repository->upsertApi(
				array(
					'localeId'  => 777,
					'locale'    => 'API replacement',
					'comune'    => 'Elsewhere',
					'provincia' => 'XX',
				)
			)
		);
		$stored = $this->repository->findByRemoteId( 777 );
		self::assertInstanceOf( Locale::class, $stored );
		self::assertSame( $before_state, $stored->toArray() );
	}

	/**
	 * API upsert accepts an all-digit positive string identity without coercing malformed values.
	 */
	public function test_upsert_api_accepts_positive_all_digit_string_id(): void {
		$id = $this->repository->upsertApi(
			array(
				'localeId' => '00902',
				'locale'   => 'String identity venue',
			)
		);

		$stored = $this->repository->findByRemoteId( 902 );
		self::assertInstanceOf( Locale::class, $stored );
		self::assertSame( $id, $stored->id );
	}

	/**
	 * API payloads require a positive remote ID and non-empty venue name.
	 *
	 * @dataProvider invalid_api_payload_provider
	 * @param array<string,mixed> $payload Invalid API payload.
	 */
	public function test_upsert_api_rejects_invalid_payloads( array $payload ): void {
		$this->expectException( InvalidArgumentException::class );

		$this->repository->upsertApi( $payload );
	}

	/**
	 * Provide malformed API payloads.
	 *
	 * @return array<string,array{array<string,mixed>}>
	 */
	public function invalid_api_payload_provider(): array {
		return array(
			'missing remote ID' => array( array( 'locale' => 'Venue' ) ),
			'zero remote ID'    => array( array( 'localeId' => 0, 'locale' => 'Venue' ) ),
			'negative ID'       => array( array( 'localeId' => -1, 'locale' => 'Venue' ) ),
			'float ID'          => array( array( 'localeId' => 1.9, 'locale' => 'Venue' ) ),
			'true ID'           => array( array( 'localeId' => true, 'locale' => 'Venue' ) ),
			'false ID'          => array( array( 'localeId' => false, 'locale' => 'Venue' ) ),
			'signed string ID'  => array( array( 'localeId' => '+1', 'locale' => 'Venue' ) ),
			'minus string ID'   => array( array( 'localeId' => '-1', 'locale' => 'Venue' ) ),
			'decimal string ID' => array( array( 'localeId' => '1.0', 'locale' => 'Venue' ) ),
			'alphanumeric ID'   => array( array( 'localeId' => '1junk', 'locale' => 'Venue' ) ),
			'whitespace ID'     => array( array( 'localeId' => ' 1 ', 'locale' => 'Venue' ) ),
			'zero string ID'    => array( array( 'localeId' => '000', 'locale' => 'Venue' ) ),
			'overflowing ID'    => array( array( 'localeId' => '999999999999999999999999', 'locale' => 'Venue' ) ),
			'missing name'      => array( array( 'localeId' => 1 ) ),
			'blank name'        => array( array( 'localeId' => 1, 'locale' => '   ' ) ),
		);
	}

	/**
	 * Combined filters and free text share identical search and count predicates.
	 */
	public function test_search_combines_filters_and_count_matches(): void {
		$this->repository->save( $this->manual_locale( 'Cinema Alfa', 'Roma', 'RM' ) );
		$this->repository->save( $this->manual_locale( 'Teatro Beta', 'Roma', 'RM' ) );
		$this->repository->save( $this->manual_locale( 'Cinema Gamma', 'Roma', 'VT' ) );
		$this->repository->save( $this->manual_locale( 'Cinema Delta', 'Milano', 'MI' ) );

		$filters = array(
			'provincia' => ' RM ',
			'comune'    => ' Roma ',
			'search'    => 'cinema',
		);
		$results = $this->repository->search( $filters, 1, 20 );

		self::assertCount( 1, $results );
		self::assertContainsOnlyInstancesOf( Locale::class, $results );
		self::assertSame( 'Cinema Alfa', $results[0]->nome );
		self::assertSame( count( $results ), $this->repository->count( $filters ) );
	}

	/**
	 * Search checks name, code, and comune without broadening injection-shaped values.
	 */
	public function test_text_search_fields_and_injection_shaped_filters_are_safe(): void {
		$coded         = $this->manual_locale( 'Auditorium', 'Firenze', 'FI' );
		$coded->codice = 'SPECIAL-CODE';
		$this->repository->save( $coded );
		$this->repository->save( $this->manual_locale( 'Other venue', 'Roma', 'RM' ) );

		self::assertCount( 1, $this->repository->search( array( 'search' => 'special-code' ), 1, 10 ) );
		self::assertCount( 1, $this->repository->search( array( 'search' => 'fIrEnZe' ), 1, 10 ) );
		self::assertSame( 0, $this->repository->count( array( 'provincia' => "RM' OR 1=1 --" ) ) );
		self::assertSame( 0, $this->repository->count( array( 'comune' => "Roma' OR 1=1 --" ) ) );
		self::assertSame( 0, $this->repository->count( array( 'search' => "%' OR 1=1 --" ) ) );
	}

	/**
	 * Pagination clamps positive bounds and orders equal names by local ID.
	 */
	public function test_search_paginates_deterministically_and_clamps_bounds(): void {
		$first_same  = $this->repository->save( $this->manual_locale( 'Same Name', 'Roma', 'RM' ) );
		$alpha       = $this->repository->save( $this->manual_locale( 'Alpha', 'Roma', 'RM' ) );
		$second_same = $this->repository->save( $this->manual_locale( 'Same Name', 'Roma', 'RM' ) );
		$omega       = $this->repository->save( $this->manual_locale( 'Omega', 'Roma', 'RM' ) );

		$page_one = $this->repository->search( array(), 1, 2 );
		$page_two = $this->repository->search( array(), 2, 2 );
		$clamped  = $this->repository->search( array(), 0, 0 );

		self::assertSame( array( $alpha, $omega ), array_column( array_map( array( $this, 'locale_to_array' ), $page_one ), 'id' ) );
		self::assertSame( array( $first_same, $second_same ), array_column( array_map( array( $this, 'locale_to_array' ), $page_two ), 'id' ) );
		self::assertCount( 1, $clamped );
		self::assertSame( $alpha, $clamped[0]->id );
		self::assertSame( array(), $this->repository->search( array(), 99, 2 ) );
	}

	/**
	 * Create a manual venue fixture.
	 */
	private function manual_locale( string $name, string $city, string $province ): Locale {
		$locale            = new Locale();
		$locale->nome      = $name;
		$locale->comune    = $city;
		$locale->provincia = $province;
		$locale->source    = 'manual';

		return $locale;
	}

	/**
	 * Convert a DTO for concise ordered-ID assertions.
	 *
	 * @return array<string,mixed>
	 */
	public function locale_to_array( Locale $locale ): array {
		return $locale->toArray();
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
