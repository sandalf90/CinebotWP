<?php
/**
 * Schedule hierarchy repository integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Fixtures use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Models\Evento;
use CinebotWp\Models\Locale;
use CinebotWp\Models\Prezzo;
use CinebotWp\Models\Settore;
use CinebotWp\Models\Titolo;
use CinebotWp\ReadModels\ProgrammazioneCard;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\TitoloRepository;
use RuntimeException;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies persistence, reads, public projection, and reconciliation.
 */
final class ScheduleRepositoryTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** @var SettoreRepository */
	private $sectors;

	/** @var PrezzoRepository */
	private $prices;

	/** @var LocaleRepository */
	private $venues;

	/** Store the WordPress database connection. */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		global $wpdb;
		self::$db = $wpdb;
	}

	/** Install and clear the schema before each test. */
	public function set_up(): void {
		parent::set_up();
		( new SchemaInstaller( self::$db ) )->install();
		$this->clear_tables();
		$this->titles = new TitoloRepository( self::$db );
		$this->events = new EventoRepository( self::$db );
		$this->sectors = new SettoreRepository( self::$db );
		$this->prices = new PrezzoRepository( self::$db );
		$this->venues = new LocaleRepository( self::$db );
	}

	/** Clear hierarchy fixtures after each test. */
	public function tear_down(): void {
		$this->clear_tables();
		parent::tear_down();
	}

	/**
	 * Every hierarchy level maps all fields and permits multiple manual null identities.
	 */
	public function test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state(): void {
		$venue_id = $this->venue( 'Venue', 'Roma' );
		$title = $this->title( 501, 'Mapped title', 'api', 42, 'title-token' );
		$title->autore = 'Mapped author';
		$title->esecutore = 'Mapped performer';
		$title->durata = 125;
		$title->scadenza = 1;
		$title->descrizione = 'Mapped description';
		$title->locandinaFlag = 1;
		$title->locandinaUrl = 'https://example.test/poster.jpg';
		$title->cinetel = 'CINETEL-1';
		$title->tmdb = 'TMDB-2';
		$title->trailer = 'https://example.test/trailer';
		$title->cast = 'Mapped cast';
		$title->tag = array( 'family', array( 'key' => 'value' ) );
		$title->syncHash = 'mapped-title-hash';
		$title->syncActive = 0;
		$title_id = $this->titles->save( $title );
		$stored_title = $this->titles->find( $title_id );
		self::assertInstanceOf( Titolo::class, $stored_title );
		$this->assert_complete_dto( $title, $stored_title, $title_id );

		$event = $this->event( 601, $title_id, $venue_id, 'api', '2030-02-03 19:45:00', 'event-token' );
		$event->organizzatoreId = 701;
		$event->organizzatoreCf = 'ORG-CF-01';
		$event->stato = 2;
		$event->otp = 1;
		$event->controlloaccessi = 0;
		$event->mappa = 81;
		$event->syncActive = 0;
		$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/601/acquista';
		$event_id = $this->events->save( $event );
		$stored_event = $this->events->findByRemoteId( 601 );
		self::assertInstanceOf( Evento::class, $stored_event );
		$this->assert_complete_dto( $event, $stored_event, $event_id );

		$sector = $this->sector( 801, $event_id, 'api', 'sector-token' );
		$sector->nome = 'Mapped sector';
		$sector->syncActive = 0;
		$sector_id = $this->sectors->save( $sector );
		$stored_sector = $this->sectors->findByRemoteId( $event_id, 801 );
		self::assertInstanceOf( Settore::class, $stored_sector );
		$this->assert_complete_dto( $sector, $stored_sector, $sector_id );

		$price = $this->price( 901, $sector_id, '12.50', 2, 'api', 'price-token' );
		$price->nome = 'Mapped price';
		$price->tipo = 'RID';
		$price->prevendita = '1.75';
		$price->syncActive = 0;
		$price_id = $this->prices->save( $price );
		$stored_price = $this->prices->findByRemoteId( $sector_id, 901 );
		self::assertInstanceOf( Prezzo::class, $stored_price );
		$this->assert_complete_dto( $price, $stored_price, $price_id );
		self::assertSame( '12.50', $stored_price->importo );
		self::assertSame( '1.75', $stored_price->prevendita );

		$manual_title = $this->title( null, 'Manual title', 'manual' );
		$manual_title->syncActive = 0;
		$manual_title->lastSeenSync = 'must-clear';
		$manual_title_id = $this->titles->save( $manual_title );
		$other_title_id = $this->titles->save( $this->title( null, 'Other manual', 'manual' ) );
		$stored_manual_title = $this->titles->find( $manual_title_id );
		self::assertInstanceOf( Titolo::class, $stored_manual_title );
		self::assertSame( 1, $stored_manual_title->syncActive );
		self::assertNull( $stored_manual_title->lastSeenSync );
		self::assertNotSame( $manual_title_id, $other_title_id );

		$manual_event = $this->event( null, $manual_title_id, $venue_id, 'manual' );
		$manual_event->syncActive = 0;
		$manual_event_id = $this->events->save( $manual_event );
		$other_event_id = $this->events->save( $this->event( null, $manual_title_id, $venue_id, 'manual' ) );
		$manual_sector = $this->sector( null, $manual_event_id, 'manual' );
		$manual_sector->syncActive = 0;
		$manual_sector_id = $this->sectors->save( $manual_sector );
		$other_sector_id = $this->sectors->save( $this->sector( null, $manual_event_id, 'manual' ) );
		$manual_price = $this->price( null, $manual_sector_id, '14.50', 1, 'manual' );
		$manual_price->syncActive = 0;
		$manual_price_id = $this->prices->save( $manual_price );
		$other_price_id = $this->prices->save( $this->price( null, $manual_sector_id, '15.50', 1, 'manual' ) );

		self::assertNotSame( $manual_event_id, $other_event_id );
		self::assertNotSame( $manual_sector_id, $other_sector_id );
		self::assertNotSame( $manual_price_id, $other_price_id );
		self::assertTrue( $this->events->belongsToTitolo( $manual_event_id, $manual_title_id ) );
		self::assertTrue( $this->sectors->belongsToEvento( $manual_sector_id, $manual_event_id ) );
		self::assertTrue( $this->prices->belongsToSettore( $manual_price_id, $manual_sector_id ) );
		self::assertFalse( $this->events->belongsToTitolo( 0, $title_id ) );
		$stored_manual_event = $this->events->findByTitoloId( $manual_title_id )[0];
		$stored_manual_sector = $this->sectors->findByEventoId( $manual_event_id )[0];
		$stored_manual_price = $this->prices->findBySettoreId( $manual_sector_id )[0];
		self::assertSame( 1, $stored_manual_event->syncActive );
		self::assertNull( $stored_manual_event->lastSeenSync );
		self::assertNull( $stored_manual_event->urlAcquisto );
		self::assertSame( 1, $stored_manual_sector->syncActive );
		self::assertNull( $stored_manual_sector->lastSeenSync );
		self::assertSame( 1, $stored_manual_price->syncActive );
		self::assertNull( $stored_manual_price->lastSeenSync );

		$created_at = $stored_title->createdAt;
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'updated_at' => '2000-01-01 00:00:00' ), array( 'id' => $title_id ) );
		$stored_title = $this->titles->find( $title_id );
		$stored_title->titolo = 'Updated title';
		$this->titles->save( $stored_title );
		$updated = $this->titles->find( $title_id );
		self::assertInstanceOf( Titolo::class, $updated );
		self::assertSame( $created_at, $updated->createdAt );
		self::assertNotSame( '2000-01-01 00:00:00', $updated->updatedAt );
	}

	/**
	 * Remote identities are found in their documented global or parent scope.
	 */
	public function test_remote_identity_scope_and_safe_write_failures(): void {
		$title_id = $this->titles->save( $this->title( 100, 'API title', 'api' ) );
		$venue_id = $this->venue( 'Venue', 'Roma' );
		$event_id = $this->events->save( $this->event( 200, $title_id, $venue_id, 'api' ) );
		$sector_id = $this->sectors->save( $this->sector( 300, $event_id, 'api' ) );
		$price_id = $this->prices->save( $this->price( 400, $sector_id, '10.00', 1, 'api' ) );

		self::assertSame( $title_id, $this->titles->findByRemoteId( 100 )->id );
		self::assertSame( $event_id, $this->events->findByRemoteId( 200 )->id );
		self::assertSame( $sector_id, $this->sectors->findByRemoteId( $event_id, 300 )->id );
		self::assertSame( $price_id, $this->prices->findByRemoteId( $sector_id, 400 )->id );
		self::assertNull( $this->sectors->findByRemoteId( PHP_INT_MAX, 300 ) );
		try {
			$this->titles->save( $this->title( 100, 'Duplicate title', 'api' ) );
			self::fail( 'A global API title identity must be unique.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'title', strtolower( $exception->getMessage() ) );
		}
		try {
			$this->events->save( $this->event( 200, $title_id, $venue_id, 'api' ) );
			self::fail( 'A global API event identity must be unique.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'event', strtolower( $exception->getMessage() ) );
		}
		$stored_title = $this->titles->find( $title_id );
		self::assertInstanceOf( Titolo::class, $stored_title );
		$stored_title->source = 'manual';
		try {
			$this->titles->save( $stored_title );
			self::fail( 'A save must not convert API ownership to manual.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'title', strtolower( $exception->getMessage() ) );
		}

		$missing = $this->title( null, 'Missing', 'manual' );
		$missing->id = PHP_INT_MAX;
		$this->expectException( RuntimeException::class );
		$this->titles->save( $missing );
	}

	/**
	 * Invalid title tag JSON is an empty array and failed encoding is safe.
	 */
	public function test_title_tag_json_invalid_fallback_and_encoding_failure(): void {
		$id = $this->titles->save( $this->title( null, 'JSON title', 'manual' ) );
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'tag' => '{invalid' ), array( 'id' => $id ) );
		self::assertSame( array(), $this->titles->find( $id )->tag );
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'tag' => '"scalar"' ), array( 'id' => $id ) );
		self::assertSame( array(), $this->titles->find( $id )->tag );

		$title = $this->title( null, 'Bad JSON', 'manual' );
		$title->tag = array( fopen( 'php://memory', 'r' ) );
		$this->expectException( RuntimeException::class );
		$this->titles->save( $title );
	}

	/**
	 * Admin search/count share exact filters, escaped text, pagination, and ordering.
	 */
	public function test_admin_search_count_statistics_and_type_count(): void {
		$one = $this->title( null, 'Beta', 'manual' );
		$one->autore = 'Needle Author';
		$one->tipoeventoCodice = '01';
		$this->titles->save( $one );
		$two = $this->title( 2, 'Alpha Needle', 'api' );
		$two->tipoeventoCodice = '01';
		$this->titles->save( $two );
		$this->titles->save( $this->title( null, 'Gamma', 'manual' ) );

		$filters = array( 'tipoevento_codice' => '01', 'source' => 'manual', 'search' => 'needle' );
		$rows = $this->titles->search( $filters, 0, 0 );
		self::assertCount( 1, $rows );
		self::assertSame( 'Beta', $rows[0]->titolo );
		self::assertSame( count( $rows ), $this->titles->count( $filters ) );
		self::assertSame( 0, $this->titles->count( array( 'search' => "%_' OR 1=1 --" ) ) );
		self::assertSame(
			array( 'Alpha Needle', 'Beta', 'Gamma' ),
			array_map(
				static function ( Titolo $title ): string {
					return $title->titolo;
				},
				$this->titles->search( array(), 1, 20 )
			)
		);
		self::assertSame( 2, $this->titles->countByTypeCode( '01' ) );
		self::assertSame(
			array( 'titoli_totali', 'titoli_manuali', 'eventi_totali', 'locali_totali', 'tipologie_attive' ),
			array_keys( $this->titles->statistics() )
		);
		self::assertSame( 3, $this->titles->statistics()['titoli_totali'] );
		self::assertSame( 2, $this->titles->statistics()['titoli_manuali'] );
	}

	/**
	 * Public schedule applies visibility, combined filters, pricing, sorting, and parity.
	 */
	public function test_public_schedule_projection_filters_visibility_and_count_parity(): void {
		$rome = $this->venue( 'Rome Hall', 'Roma' );
		$milan = $this->venue( 'Milan Hall', 'Milano' );
		$title_id = $this->titles->save( $this->title( 10, 'Zulu Show', 'api' ) );
		$second_id = $this->titles->save( $this->title( 11, 'Alpha Show', 'api' ) );
		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 10 );
		$later = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 20 );
		$event_id = $this->events->save( $this->event( 20, $title_id, $rome, 'api', $future ) );
		$no_price_event = $this->events->save( $this->event( 21, $second_id, $milan, 'api', $later ) );
		$sector_id = $this->sectors->save( $this->sector( 30, $event_id, 'api' ) );
		$this->prices->save( $this->price( 40, $sector_id, '20.00', 1, 'api' ) );
		$this->prices->save( $this->price( 41, $sector_id, '10.00', 1, 'api' ) );
		$this->prices->save( $this->price( 42, $sector_id, '1.00', 0, 'api' ) );

		$cards = $this->titles->findPublicSchedule( array() );
		self::assertCount( 2, $cards );
		self::assertContainsOnlyInstancesOf( ProgrammazioneCard::class, $cards );
		self::assertSame(
			array(
				'evento_id' => $event_id,
				'inizio' => $future,
				'titolo_id' => $title_id,
				'titolo' => 'Zulu Show',
				'descrizione' => 'Description',
				'locandina_url' => null,
				'tipo_codice' => '01',
				'tipo_descrizione' => 'CINEMA',
				'locale_id' => $rome,
				'locale_nome' => 'Rome Hall',
				'comune' => 'Roma',
				'prezzo_min' => '10.00',
				'prezzo_max' => '20.00',
			),
			$this->card_to_array( $cards[0] )
		);
		self::assertNull( $cards[1]->prezzoMin );
		self::assertSame( 2, $this->titles->countPublicSchedule( array() ) );

		$filters = array(
			'tipo' => '01',
			'locale' => $rome,
			'comune' => 'Roma',
			'from' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
			'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 15 ),
			'orderby' => 'titolo',
			'order' => 'DESC',
			'limit' => 1,
			'offset' => 0,
		);
		self::assertCount( 1, $this->titles->findPublicSchedule( $filters ) );
		self::assertSame( 1, $this->titles->countPublicSchedule( $filters ) );
		$empty_page_filters = array_merge( $filters, array( 'offset' => 1 ) );
		self::assertSame( array(), $this->titles->findPublicSchedule( $empty_page_filters ) );
		self::assertSame( 1, $this->titles->countPublicSchedule( $empty_page_filters ) );
		self::assertSame(
			$event_id,
			$this->titles->findPublicSchedule(
				array( 'orderby' => 'injection', 'order' => 'DROP TABLE', 'limit' => 999 )
			)[0]->eventoId
		);
		self::assertSame(
			$no_price_event,
			$this->titles->findPublicSchedule(
				array( 'orderby' => 'titolo', 'order' => 'ASC', 'limit' => 1, 'offset' => 0 )
			)[0]->eventoId
		);

		self::$db->update( self::$db->prefix . 'cinebot_settori', array( 'sync_active' => 0 ), array( 'id' => $sector_id ) );
		self::assertNull( $this->titles->findPublicSchedule( array() )[0]->prezzoMin );
		self::$db->update( self::$db->prefix . 'cinebot_settori', array( 'sync_active' => 1 ), array( 'id' => $sector_id ) );
		self::$db->update( self::$db->prefix . 'cinebot_prezzi', array( 'sync_active' => 0 ), array( 'idprezzo' => 41 ) );
		self::assertSame( '20.00', $this->titles->findPublicSchedule( array() )[0]->prezzoMin );
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'sync_active' => 0 ), array( 'id' => $second_id ) );
		self::assertSame(
			array( $event_id ),
			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
		);
		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'sync_active' => 1 ), array( 'id' => $second_id ) );
		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'stato' => 2 ), array( 'id' => $no_price_event ) );
		self::assertSame( 1, $this->titles->countPublicSchedule( array() ) );
		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'stato' => 3 ), array( 'id' => $no_price_event ) );
		$past = $this->events->save( $this->event( 22, $title_id, $rome, 'api', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
		self::assertNotContains(
			$past,
			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
		);

		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'sync_active' => 0 ), array( 'id' => $event_id ) );
		self::assertSame(
			array( $no_price_event ),
			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
		);
	}

	/**
	 * Ownership rejects wrong positive parents and direct deletes retain descendants.
	 */
	public function test_counts_deletes_and_delete_by_parent_contracts(): void {
		$title_id = $this->titles->save( $this->title( null, 'Delete one', 'manual' ) );
		$other_title_id = $this->titles->save( $this->title( null, 'Delete two', 'manual' ) );
		$venue_id = $this->venue( 'Delete venue', 'Roma' );
		$event_id = $this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );
		$other_event_id = $this->events->save( $this->event( null, $other_title_id, $venue_id, 'manual' ) );
		$sector_id = $this->sectors->save( $this->sector( null, $event_id, 'manual' ) );
		$other_sector_id = $this->sectors->save( $this->sector( null, $other_event_id, 'manual' ) );
		$price_id = $this->prices->save( $this->price( null, $sector_id, '5.00', 1, 'manual' ) );
		$other_price_id = $this->prices->save( $this->price( null, $other_sector_id, '6.00', 1, 'manual' ) );

		self::assertSame( 1, $this->events->countByTitoloId( $title_id ) );
		self::assertSame( 2, $this->events->countByLocaleId( $venue_id ) );
		self::assertFalse( $this->events->belongsToTitolo( $event_id, $other_title_id ) );
		self::assertFalse( $this->sectors->belongsToEvento( $sector_id, $other_event_id ) );
		self::assertFalse( $this->prices->belongsToSettore( $price_id, $other_sector_id ) );

		self::assertTrue( $this->titles->delete( $title_id ) );
		self::assertCount( 1, $this->events->findByTitoloId( $title_id ) );
		self::assertTrue( $this->events->delete( $event_id ) );
		self::assertCount( 1, $this->sectors->findByEventoId( $event_id ) );
		self::assertTrue( $this->sectors->delete( $sector_id ) );
		self::assertCount( 1, $this->prices->findBySettoreId( $sector_id ) );
		self::assertTrue( $this->prices->delete( $price_id ) );
		self::assertFalse( $this->prices->delete( $price_id ) );
		self::assertFalse( $this->titles->delete( $title_id ) );

		self::assertSame( 1, $this->events->deleteByTitoloId( $other_title_id ) );
		self::assertCount( 1, $this->sectors->findByEventoId( $other_event_id ) );
		self::assertSame( 1, $this->sectors->deleteByEventoId( $other_event_id ) );
		self::assertCount( 1, $this->prices->findBySettoreId( $other_sector_id ) );
		self::assertSame( 1, $this->prices->deleteBySettoreId( $other_sector_id ) );
		self::assertFalse( $this->prices->delete( $other_price_id ) );
	}

	/**
	 * Unseen and cascade reconciliation is scoped, returns IDs, and preserves manual rows.
	 */
	public function test_reconciliation_scopes_and_empty_array_no_ops(): void {
		$title_one = $this->titles->save( $this->title( 101, 'One', 'api', 50, 'old' ) );
		$title_seen = $this->titles->save( $this->title( 102, 'Seen', 'api', 50, 'current' ) );
		$title_other = $this->titles->save( $this->title( 103, 'Other', 'api', 51, 'old' ) );
		$manual_title = $this->titles->save( $this->title( null, 'Manual', 'manual', 50, null ) );
		self::assertSame( array( $title_one ), $this->titles->deactivateUnseenApi( 50, 'current' ) );
		self::assertSame( 1, $this->titles->find( $title_seen )->syncActive );
		self::assertSame( 1, $this->titles->find( $title_other )->syncActive );
		self::assertSame( 1, $this->titles->find( $manual_title )->syncActive );

		$venue_id = $this->venue( 'Venue', 'Roma' );
		$event = $this->events->save( $this->event( 201, $title_seen, $venue_id, 'api', null, 'old' ) );
		$manual_event = $this->events->save( $this->event( null, $title_seen, $venue_id, 'manual' ) );
		self::assertSame( array( $event ), $this->events->deactivateUnseenApi( $title_seen, 'current' ) );
		self::assertSame( 1, $this->events->findByTitoloId( $title_seen )[1]->syncActive );

		$event_two = $this->events->save( $this->event( 202, $title_other, $venue_id, 'api' ) );
		$sector = $this->sectors->save( $this->sector( 301, $event_two, 'api', 'old' ) );
		$manual_sector = $this->sectors->save( $this->sector( null, $event_two, 'manual' ) );
		self::assertSame( array( $sector ), $this->sectors->deactivateUnseenApi( $event_two, 'current' ) );
		self::assertSame( array(), $this->sectors->deactivateByEventoIds( array() ) );

		$active_sector = $this->sectors->save( $this->sector( 302, $event_two, 'api' ) );
		$price = $this->prices->save( $this->price( 401, $active_sector, '10.00', 1, 'api', 'old' ) );
		$manual_price = $this->prices->save( $this->price( null, $active_sector, '11.00', 1, 'manual' ) );
		self::assertSame( array( $price ), $this->prices->deactivateUnseenApi( $active_sector, 'current' ) );
		$cascade_price = $this->prices->save( $this->price( 402, $active_sector, '12.00', 1, 'api' ) );
		self::assertSame( 0, $this->prices->deactivateBySettoreIds( array() ) );
		self::assertSame( 0, $this->prices->deactivateBySettoreIds( array( $manual_sector ) ) );
		self::assertSame( array( $active_sector ), $this->sectors->deactivateByEventoIds( array( $event_two ) ) );
		self::assertSame( 1, $this->prices->deactivateBySettoreIds( array( $active_sector ) ) );
		self::assertSame( array( $event_two ), $this->events->deactivateByTitoloIds( array( $title_other ) ) );
		self::assertSame( array(), $this->events->deactivateByTitoloIds( array() ) );
		self::assertSame( 0, $this->prices->findBySettoreId( $active_sector )[2]->syncActive );
		self::assertSame( $manual_event, $this->events->findByTitoloId( $title_seen )[1]->id );
		self::assertSame( $manual_price, $this->prices->findBySettoreId( $active_sector )[1]->id );
		self::assertSame( $cascade_price, $this->prices->findBySettoreId( $active_sector )[2]->id );
	}

	/**
	 * Reconciliation update failures throw and cannot report selected candidates.
	 */
	public function test_reconciliation_query_failures_throw_for_every_return_contract(): void {
		$title_id = $this->titles->save( $this->title( 1001, 'Failure title', 'api', 90, 'old' ) );
		$venue_id = $this->venue( 'Failure venue', 'Roma' );
		$event_id = $this->events->save( $this->event( 1002, $title_id, $venue_id, 'api', null, 'old' ) );
		$sector_id = $this->sectors->save( $this->sector( 1003, $event_id, 'api', 'old' ) );
		$this->prices->save( $this->price( 1004, $sector_id, '10.00', 1, 'api', 'old' ) );

		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			/** Fail only reconciliation UPDATE statements. */
			public function query( $query ) {
				if ( is_string( $query ) && 1 === preg_match( '/^\s*UPDATE\s+/i', $query ) ) {
					return false;
				}
				return parent::query( $query );
			}
		};
		$failing_db->set_prefix( self::$db->prefix );

		try {
			$this->assert_reconciliation_failure(
				static function () use ( $failing_db ): void {
					( new TitoloRepository( $failing_db ) )->deactivateUnseenApi( 90, 'current' );
				}
			);
			$this->assert_reconciliation_failure(
				static function () use ( $failing_db, $title_id ): void {
					( new EventoRepository( $failing_db ) )->deactivateByTitoloIds( array( $title_id ) );
				}
			);
			$this->assert_reconciliation_failure(
				static function () use ( $failing_db, $event_id ): void {
					( new SettoreRepository( $failing_db ) )->deactivateByEventoIds( array( $event_id ) );
				}
			);
			$this->assert_reconciliation_failure(
				static function () use ( $failing_db, $sector_id ): void {
					( new PrezzoRepository( $failing_db ) )->deactivateBySettoreIds( array( $sector_id ) );
				}
			);
		} finally {
			$failing_db->close();
		}

		self::assertSame( 1, $this->titles->find( $title_id )->syncActive );
		self::assertSame( 1, $this->events->findByTitoloId( $title_id )[0]->syncActive );
		self::assertSame( 1, $this->sectors->findByEventoId( $event_id )[0]->syncActive );
		self::assertSame( 1, $this->prices->findBySettoreId( $sector_id )[0]->syncActive );
	}

	/**
	 * A stale candidate whose conditional update affects zero rows is not returned.
	 */
	public function test_reconciliation_returns_only_ids_reported_as_updated(): void {
		$title_id = $this->titles->save( $this->title( 1101, 'Stale title', 'api', 91, 'old' ) );
		$zero_update_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
			/** Simulate a candidate becoming stale before its conditional update. */
			public function query( $query ) {
				if ( is_string( $query ) && 1 === preg_match( '/^\s*UPDATE\s+/i', $query ) ) {
					return 0;
				}
				return parent::query( $query );
			}
		};
		$zero_update_db->set_prefix( self::$db->prefix );

		try {
			$repository = new TitoloRepository( $zero_update_db );
			self::assertSame( array(), $repository->deactivateUnseenApi( 91, 'current' ) );
		} finally {
			$zero_update_db->close();
		}

		self::assertSame( 1, $this->titles->find( $title_id )->syncActive );
	}

	/** Clear hierarchy tables in child-first order. */
	private function clear_tables(): void {
		foreach ( array( 'prezzi', 'settori', 'eventi', 'titoli', 'locali' ) as $suffix ) {
			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
	}

	/**
	 * Assert every DTO field plus generated timestamps.
	 *
	 * @param Titolo|Evento|Settore|Prezzo $expected Input DTO.
	 * @param Titolo|Evento|Settore|Prezzo $actual Persisted DTO.
	 */
	private function assert_complete_dto( $expected, $actual, int $id ): void {
		$expected_data = $expected->toArray();
		$actual_data = $actual->toArray();
		$expected_data['id'] = $id;
		$expected_data['created_at'] = $actual_data['created_at'];
		$expected_data['updated_at'] = $actual_data['updated_at'];
		self::assertNotNull( $actual_data['created_at'] );
		self::assertSame( $actual_data['created_at'], $actual_data['updated_at'] );
		self::assertSame( $expected_data, $actual_data );
	}

	/** Assert a reconciliation write failure is safe and actionable. */
	private function assert_reconciliation_failure( callable $operation ): void {
		try {
			$operation();
			self::fail( 'A failed reconciliation update must throw.' );
		} catch ( RuntimeException $exception ) {
			self::assertStringContainsString( 'deactivate', strtolower( $exception->getMessage() ) );
			self::assertDoesNotMatchRegularExpression(
				'/\b(?:select|update|delete|insert)\b/i',
				$exception->getMessage()
			);
		}
	}

	/**
	 * Convert one public card to its documented projection shape.
	 *
	 * @return array<string,mixed>
	 */
	private function card_to_array( ProgrammazioneCard $card ): array {
		return array(
			'evento_id' => $card->eventoId,
			'inizio' => $card->inizio,
			'titolo_id' => $card->titoloId,
			'titolo' => $card->titolo,
			'descrizione' => $card->descrizione,
			'locandina_url' => $card->locandinaUrl,
			'tipo_codice' => $card->tipoCodice,
			'tipo_descrizione' => $card->tipoDescrizione,
			'locale_id' => $card->localeId,
			'locale_nome' => $card->localeNome,
			'comune' => $card->comune,
			'prezzo_min' => $card->prezzoMin,
			'prezzo_max' => $card->prezzoMax,
		);
	}

	/**
	 * Return event IDs from public cards.
	 *
	 * @param array<int,ProgrammazioneCard> $cards Public cards.
	 * @return array<int,int>
	 */
	private function card_ids( array $cards ): array {
		return array_map(
			static function ( ProgrammazioneCard $card ): int {
				return $card->eventoId;
			},
			$cards
		);
	}

	/** Create a title fixture. */
	private function title( ?int $remote_id, string $name, string $source, ?int $frontend_id = 1, ?string $token = 'token' ): Titolo {
		$title = new Titolo();
		$title->idtitolo = $remote_id;
		$title->frontendId = $frontend_id;
		$title->titolo = $name;
		$title->descrizione = 'Description';
		$title->tipoeventoCodice = '01';
		$title->source = $source;
		$title->syncHash = 'hash';
		$title->lastSeenSync = $token;
		return $title;
	}

	/** Create an event fixture. */
	private function event( ?int $remote_id, int $title_id, int $venue_id, string $source, ?string $start = null, ?string $token = 'token' ): Evento {
		$event = new Evento();
		$event->idevento = $remote_id;
		$event->titoloId = $title_id;
		$event->inizio = $start ?? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 5 );
		$event->localeId = $venue_id;
		$event->stato = 3;
		$event->source = $source;
		$event->lastSeenSync = $token;
		return $event;
	}

	/** Create a sector fixture. */
	private function sector( ?int $remote_id, int $event_id, string $source, ?string $token = 'token' ): Settore {
		$sector = new Settore();
		$sector->idsettore = $remote_id;
		$sector->eventoId = $event_id;
		$sector->nome = 'Sector';
		$sector->source = $source;
		$sector->lastSeenSync = $token;
		return $sector;
	}

	/** Create a price fixture. */
	private function price( ?int $remote_id, int $sector_id, string $amount, int $state, string $source, ?string $token = 'token' ): Prezzo {
		$price = new Prezzo();
		$price->idprezzo = $remote_id;
		$price->settoreId = $sector_id;
		$price->nome = 'Price';
		$price->tipo = 'INT';
		$price->importo = $amount;
		$price->prevendita = '1.00';
		$price->stato = $state;
		$price->source = $source;
		$price->lastSeenSync = $token;
		return $price;
	}

	/** Persist and return a manual venue fixture. */
	private function venue( string $name, string $city ): int {
		$venue = new Locale();
		$venue->nome = $name;
		$venue->comune = $city;
		$venue->source = 'manual';
		return $this->venues->save( $venue );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
