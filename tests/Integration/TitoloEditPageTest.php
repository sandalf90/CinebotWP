<?php
/**
 * Nested title/event editor integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Fixtures use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Admin\AdminMenu;
use CinebotWp\Admin\Pages\ApiPage;
use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Admin\Pages\TitoloEditPage;
use CinebotWp\Admin\Pages\TitoliListPage;
use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Models\Evento;
use CinebotWp\Models\Locale;
use CinebotWp\Models\Prezzo;
use CinebotWp\Models\Settore;
use CinebotWp\Models\Titolo;
use CinebotWp\Plugin;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies the nested title/event/sector/price editor.
 */
final class TitoloEditPageTest extends WP_UnitTestCase {
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

	/** @var TipologiaRepository */
	private $types;

	/** @var LocaleRepository */
	private $venues;

	/** @var TitoloEditPage */
	private $page;

	/** @var TitoliListPage */
	private $list_page;

	/** Store the WordPress database connection and load admin list-table helpers. */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();
		global $wpdb;
		self::$db = $wpdb;

		if ( ! function_exists( 'set_current_screen' ) ) {
			require_once ABSPATH . 'wp-admin/includes/admin.php';
		}
	}

	/** Install the schema, clear tables, set the screen, and construct the page. */
	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 1 );
		set_current_screen( 'toplevel_page_cinebot-wp-programmazione-edit' );

		( new SchemaInstaller( self::$db ) )->install();
		$this->clear_tables();

		$this->titles  = new TitoloRepository( self::$db );
		$this->events  = new EventoRepository( self::$db );
		$this->sectors = new SettoreRepository( self::$db );
		$this->prices  = new PrezzoRepository( self::$db );
		$this->types   = new TipologiaRepository( self::$db );
		$this->venues  = new LocaleRepository( self::$db );

		$this->page = new TitoloEditPage(
			$this->titles,
			$this->events,
			$this->sectors,
			$this->prices,
			$this->types,
			$this->venues
		);

		$this->list_page = new TitoliListPage(
			$this->titles,
			$this->events,
			$this->sectors,
			$this->prices,
			$this->types
		);

		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/** Clear hierarchy fixtures after each test. */
	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
		$this->clear_tables();
		$_POST  = array();
		$_GET   = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * Intercept wp_safe_redirect() inside the test suite.
	 *
	 * The WordPress test bootstrap echoes output before tests run, so any
	 * header() call from wp_redirect() triggers "Cannot modify header
	 * information". Throwing WPDieException lets the existing try/catch
	 * blocks around save() intercept the redirect as designed.
	 *
	 * @param string|mixed $location Redirect location.
	 * @return string|mixed Never reached; exception short-circuits.
	 * @throws WPDieException Always.
	 */
	public function intercept_redirect( $location ) {
		throw new \WPDieException( is_string( $location ) ? $location : '' );
	}

	/** The new form renders with a nonce and all declared title fields. */
	public function test_new_form_renders_with_nonce_and_all_fields(): void {
		$output = $this->capture_render( null );

		self::assertStringContainsString( 'cinebot_wp_titolo_nonce', $output );
		self::assertStringContainsString( 'cinebot_wp_save_titolo', $output );
		self::assertStringContainsString( 'name="titolo"', $output );
		self::assertStringContainsString( 'name="autore"', $output );
		self::assertStringContainsString( 'name="esecutore"', $output );
		self::assertStringContainsString( 'name="durata"', $output );
		self::assertStringContainsString( 'name="tipoevento_codice"', $output );
		self::assertStringContainsString( 'name="descrizione"', $output );
		self::assertStringContainsString( 'name="locandina_url"', $output );
		self::assertStringContainsString( 'name="cinetel"', $output );
		self::assertStringContainsString( 'name="tmdb"', $output );
		self::assertStringContainsString( 'name="trailer"', $output );
		self::assertStringContainsString( 'name="cast"', $output );
		self::assertStringContainsString( 'name="tag"', $output );
		self::assertStringContainsString( '<template', $output );
		self::assertStringContainsString( '__INDEX__', $output );
	}

	/** The edit form loads and displays the full hierarchy. */
	public function test_edit_form_renders_loaded_hierarchy(): void {
		$title_id  = $this->seed_full_hierarchy();
		$output    = $this->capture_render( $title_id );

		self::assertStringContainsString( 'value="Edited Title"', $output );
		self::assertStringContainsString( 'value="Author"', $output );
		self::assertStringContainsString( 'value="2024-03-15T20:00"', $output );
		self::assertStringContainsString( 'value="Platea"', $output );
		self::assertStringContainsString( 'value="Intero"', $output );
		self::assertStringContainsString( 'value="10.50"', $output );
	}

	/** A new title save creates a row with source=manual and null remote ID. */
	public function test_new_title_save_creates_with_source_manual(): void {
		$this->submit_valid_new_title();

		$this->assert_redirected();

		$all = $this->titles->search( array(), 1, 50 );
		self::assertCount( 1, $all );
		$saved = $all[0];
		self::assertSame( 'manual', $saved->source );
		self::assertNull( $saved->idtitolo );
		self::assertNull( $saved->frontendId );
		self::assertSame( 'New Title', $saved->titolo );
	}

	/** Editing an existing API title keeps source=api and the remote ID. */
	public function test_edit_api_title_keeps_source_api(): void {
		$title_id = $this->titles->save( $this->title( 200, 'API Title', 'api' ) );

		$this->submit_edit_for_existing_api_title( $title_id );

		$this->assert_redirected();

		$saved = $this->titles->find( $title_id );
		self::assertSame( 'api', $saved->source );
		self::assertSame( 200, $saved->idtitolo );
		self::assertSame( 'Edited API Title', $saved->titolo );
	}

	/** Required titolo field validation prevents save. */
	public function test_required_titolo_validation_prevents_save(): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => '',
			)
		);

		$this->assert_redirected();

		self::assertCount( 0, $this->titles->search( array(), 1, 50 ) );
	}

	/** An invalid child within the transaction triggers rollback. */
	public function test_atomic_save_rollback_on_invalid_child(): void {
		$title_id = $this->titles->save( $this->title( null, 'Original', 'manual' ) );

		$other_title = $this->titles->save( $this->title( null, 'Other', 'manual' ) );
		$other_venue = $this->venue();
		$other_event_id = $this->events->save( $this->event( null, $other_title, $other_venue, 'manual' ) );

		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'Changed',
				'events'                  => array(
					(string) $other_event_id => array(
						'id'        => (string) $other_event_id,
						'inizio'    => '2024-06-01 20:00',
						'locale_id' => (string) $other_venue,
						'sectors'   => array(),
					),
				),
			)
		);

		$this->assert_redirected();

		$unchanged = $this->titles->find( $title_id );
		self::assertSame( 'Original', $unchanged->titolo, 'Title must remain unchanged after rollback.' );
	}

	/** Removed events are deleted with cascade. */
	public function test_removed_events_deleted(): void {
		$title_id  = $this->seed_full_hierarchy();
		$events    = $this->events->findByTitoloId( $title_id );
		self::assertCount( 1, $events );

		$this->submit_edit_removing_all_events( $title_id );

		$this->assert_redirected();

		self::assertCount( 0, $this->events->findByTitoloId( $title_id ) );
	}

	/** Removed sectors are deleted only for the correct event. */
	public function test_removed_sectors_deleted_under_correct_event(): void {
		$title_id = $this->titles->save( $this->title( null, 'Parent', 'manual' ) );
		$venue    = $this->venue();

		$event_a  = $this->events->save( $this->event( null, $title_id, $venue, 'manual' ) );
		$event_b  = $this->events->save( $this->event( null, $title_id, $venue, 'manual' ) );

		$sector_a1 = $this->sectors->save( $this->sector( null, $event_a, 'manual' ) );
		$sector_b1 = $this->sectors->save( $this->sector( null, $event_b, 'manual' ) );

		$this->prices->save( $this->price( null, $sector_a1, '5.00', 1, 'manual' ) );
		$this->prices->save( $this->price( null, $sector_b1, '6.00', 1, 'manual' ) );

		$this->submit_edit_removing_sector_a1( $title_id, $event_a, $event_b, $sector_b1, $venue );

		$this->assert_redirected();

		$remaining_a = $this->sectors->findByEventoId( $event_a );
		$remaining_b = $this->sectors->findByEventoId( $event_b );
		self::assertCount( 0, $remaining_a );
		self::assertCount( 1, $remaining_b, 'Sector under event B must survive.' );
	}

	/** Removed prices are deleted only for the correct sector. */
	public function test_removed_prices_deleted_under_correct_sector(): void {
		$title_id = $this->titles->save( $this->title( null, 'Parent', 'manual' ) );
		$venue    = $this->venue();
		$event_id = $this->events->save( $this->event( null, $title_id, $venue, 'manual' ) );

		$sector_a = $this->sectors->save( $this->sector( null, $event_id, 'manual' ) );
		$sector_b = $this->sectors->save( $this->sector( null, $event_id, 'manual' ) );

		$price_a1 = $this->prices->save( $this->price( null, $sector_a, '5.00', 1, 'manual' ) );
		$price_b1 = $this->prices->save( $this->price( null, $sector_b, '6.00', 1, 'manual' ) );

		$this->submit_edit_removing_price_a1( $title_id, $event_id, $venue, $sector_a, $sector_b, $price_b1 );

		$this->assert_redirected();

		self::assertCount( 0, $this->prices->findBySettoreId( $sector_a ) );
		self::assertCount( 1, $this->prices->findBySettoreId( $sector_b ), 'Price under sector B must survive.' );
	}

	/** The descrizione field is sanitized with wp_kses_post. */
	public function test_descrizione_sanitized_with_wp_kses_post(): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'Sanitize Test',
				'descrizione'             => '<p>Safe text</p><script>alert(1)</script><iframe src="evil"></iframe>',
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all  = $this->titles->search( array(), 1, 50 );
		$saved = $all[0] ?? null;
		self::assertNotNull( $saved );
		self::assertStringContainsString( '<p>Safe text</p>', $saved->descrizione );
		self::assertStringNotContainsString( '<script>', $saved->descrizione );
		self::assertStringNotContainsString( '<iframe', $saved->descrizione );
	}

	/** URLs are stored with esc_url_raw. */
	public function test_url_escaping(): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'URL Test',
				'locandina_url'           => 'https://example.test/poster.jpg?x=1&y=2',
				'trailer'                 => 'https://example.test/trailer.mp4',
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all  = $this->titles->search( array(), 1, 50 );
		$saved = $all[0] ?? null;
		self::assertNotNull( $saved );
		self::assertSame( 'https://example.test/poster.jpg?x=1&y=2', $saved->locandinaUrl );
		self::assertSame( 'https://example.test/trailer.mp4', $saved->trailer );
	}

	/** Tags become a unique JSON array. */
	public function test_tags_become_unique_json_array(): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'Tag Test',
				'tag'                     => 'drama, action, drama, comedy, action',
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all  = $this->titles->search( array(), 1, 50 );
		$saved = $all[0] ?? null;
		self::assertNotNull( $saved );
		self::assertSame( array( 'drama', 'action', 'comedy' ), $saved->tag );
	}

	/** New event rows get source=manual and null idevento. */
	public function test_new_event_gets_source_manual(): void {
		$venue = $this->venue();

		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'Event Source Test',
				'events'                  => array(
					'new1' => array(
						'id'        => '0',
						'inizio'    => '2024-05-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(),
					),
				),
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all     = $this->titles->search( array(), 1, 50 );
		$events  = $this->events->findByTitoloId( (int) $all[0]->id );
		self::assertCount( 1, $events );
		self::assertSame( 'manual', $events[0]->source );
		self::assertNull( $events[0]->idevento );
		self::assertNull( $events[0]->urlAcquisto );
	}

	/** Editing an existing API event keeps source=api, idevento, and the purchase URL. */
	public function test_edit_api_event_keeps_source_identity_and_purchase_url(): void {
		$title_id = $this->titles->save( $this->title( 300, 'API Title', 'api' ) );
		$venue    = $this->venue();
		$event = $this->event( 999, $title_id, $venue, 'api' );
		$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/999/acquista';
		$event_id = $this->events->save( $event );

		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'API Title Edited',
				'events'                  => array(
					(string) $event_id => array(
						'id'        => (string) $event_id,
						'inizio'    => '2024-07-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(),
					),
				),
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$events = $this->events->findByTitoloId( $title_id );
		self::assertCount( 1, $events );
		self::assertSame( 'api', $events[0]->source );
		self::assertSame( 999, $events[0]->idevento );
		self::assertSame(
			'https://ticket.cinebot.it/martinovich/evento/999/acquista',
			$events[0]->urlAcquisto
		);
	}

	/** New sector rows get source=manual and null idsettore. */
	public function test_new_sector_gets_source_manual(): void {
		$venue = $this->venue();

		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'Sector Source Test',
				'events'                  => array(
					'new1' => array(
						'id'        => '0',
						'inizio'    => '2024-05-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(
							'new1' => array(
								'id'     => '0',
								'nome'   => 'New Sector',
								'prices' => array(),
							),
						),
					),
				),
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all     = $this->titles->search( array(), 1, 50 );
		$events  = $this->events->findByTitoloId( (int) $all[0]->id );
		$sectors = $this->sectors->findByEventoId( (int) $events[0]->id );
		self::assertCount( 1, $sectors );
		self::assertSame( 'manual', $sectors[0]->source );
		self::assertNull( $sectors[0]->idsettore );
	}

	/** New price rows get source=manual and null idprezzo. */
	public function test_new_price_gets_source_manual(): void {
		$venue = $this->venue();

		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'Price Source Test',
				'events'                  => array(
					'new1' => array(
						'id'        => '0',
						'inizio'    => '2024-05-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(
							'new1' => array(
								'id'     => '0',
								'nome'   => 'Sector',
								'prices' => array(
									'new1' => array(
										'id'        => '0',
										'nome'      => 'Intero',
										'tipo'      => 'I',
										'importo'   => '12.00',
										'prevendita'=> '1.00',
										'stato'     => '1',
									),
								),
							),
						),
					),
				),
			)
		);

		try {
			$this->page->save();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		$all     = $this->titles->search( array(), 1, 50 );
		$events  = $this->events->findByTitoloId( (int) $all[0]->id );
		$sectors = $this->sectors->findByEventoId( (int) $events[0]->id );
		$prices  = $this->prices->findBySettoreId( (int) $sectors[0]->id );
		self::assertCount( 1, $prices );
		self::assertSame( 'manual', $prices[0]->source );
		self::assertNull( $prices[0]->idprezzo );
		self::assertSame( '12.00', $prices[0]->importo );
	}

	/** Non-admin users are denied save. */
	public function test_save_denies_non_admin(): void {
		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		$this->page->save();
	}

	/** Missing nonce rejection prevents save. */
	public function test_save_rejects_missing_nonce(): void {
		$this->set_post_request(
			array(
				'action'    => 'cinebot_wp_save_titolo',
				'titolo_id' => '0',
				'titolo'    => 'No Nonce',
			)
		);

		$this->expectException( \WPDieException::class );
		$this->page->save();
	}

	/** The admin menu registers the save handler and edit submenu. */
	public function test_admin_menu_routes_edit_page(): void {
		$settings  = new SettingsService();
		$sync      = new SyncService( self::$db );
		$scheduler = new \CinebotWp\Services\CronScheduler( $settings, $sync );
		$log_repo  = new \CinebotWp\Repositories\SyncLogRepository( self::$db );
		$dashboard = new DashboardPage( $settings, $this->titles, $log_repo );
		$api_page  = new ApiPage( $settings, $scheduler, $sync );

		$locali_page = new \CinebotWp\Admin\Pages\LocaliListPage( $this->venues, $this->titles, $this->events );
		$locale_edit = new \CinebotWp\Admin\Pages\LocaleEditPage( $this->venues );
		$tipologie_page = new \CinebotWp\Admin\Pages\TipologieListPage( $this->types );
		$tipologia_edit = new \CinebotWp\Admin\Pages\TipologiaEditPage( $this->types );
		$log_page   = new \CinebotWp\Admin\Pages\SyncLogPage( $log_repo );
		$menu = new AdminMenu( $dashboard, $api_page, $this->list_page, $this->page, $locali_page, $locale_edit, $tipologie_page, $tipologia_edit, $log_page );
		$menu->register();

		self::assertHasAction( 'admin_post_cinebot_wp_save_titolo' );
	}

	/** The TitoliListPage renders a Nuovo titolo button and Modifica row action. */
	public function test_list_page_has_new_and_edit_actions(): void {
		$this->titles->save( $this->title( null, 'List Test', 'manual' ) );

		ob_start();
		$this->list_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'cinebot-wp-programmazione-edit', $output );
		self::assertStringContainsStringIgnoringCase( 'Nuovo titolo', $output );
		self::assertStringContainsStringIgnoringCase( 'Modifica', $output );
	}

	/** The plugin composes TitoloEditPage into the admin menu. */
	public function test_plugin_composes_titolo_edit_page(): void {
		$method = new ReflectionMethod( Plugin::class, 'admin_menu' );
		$method->setAccessible( true );
		$menu = $method->invoke( null );

		$property = new ReflectionProperty( AdminMenu::class, 'edit_page' );
		$property->setAccessible( true );

		self::assertInstanceOf( TitoloEditPage::class, $property->getValue( $menu ) );
	}

	/** The list page Modifica link contains the title ID. */
	public function test_list_page_edit_link_contains_id(): void {
		$title_id = $this->titles->save( $this->title( null, 'Link Test', 'manual' ) );

		ob_start();
		$this->list_page->render();
		$output = (string) ob_get_clean();

		self::assertStringContainsString( 'id=' . $title_id, $output );
		self::assertStringContainsString( 'cinebot-wp-programmazione-edit', $output );
	}

	// ------------------------------------------------------------------ helpers.

	/** Clear hierarchy tables in child-first order. */
	private function clear_tables(): void {
		foreach ( array( 'prezzi', 'settori', 'eventi', 'titoli', 'locali' ) as $suffix ) {
			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
	}

	/** Set POST and REQUEST parameters. */
	private function set_post_request( array $params ): void {
		foreach ( $params as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/** Capture rendered page output. */
	private function capture_render( ?int $id ): string {
		ob_start();
		$this->page->render( $id );
		return (string) ob_get_clean();
	}

	/** Assert that the save redirected (WPDieException intercepted). */
	private function assert_redirected(): void {
		try {
			$this->page->save();
			self::fail( 'Expected redirect (WPDieException) was not thrown.' );
		} catch ( \WPDieException $e ) {
			// Expected.
		}
	}

	/** Assert that an action hook is registered. */
	private static function assertHasAction( string $hook ): void {
		self::assertTrue(
			has_action( $hook ) !== false,
			"Action {$hook} is not registered."
		);
	}

	/** Create a title fixture. */
	private function title( ?int $remote_id, string $name, string $source ): Titolo {
		$title                       = new Titolo();
		$title->idtitolo             = $remote_id;
		$title->frontendId           = 1;
		$title->titolo               = $name;
		$title->descrizione          = 'Description';
		$title->tipoeventoCodice     = '01';
		$title->source               = $source;
		$title->syncHash             = 'hash';
		$title->lastSeenSync          = 'token';
		return $title;
	}

	/** Create an event fixture. */
	private function event( ?int $remote_id, int $title_id, int $venue_id, string $source ): Evento {
		$event               = new Evento();
		$event->idevento     = $remote_id;
		$event->titoloId     = $title_id;
		$event->inizio       = '2024-03-15 20:00:00';
		$event->localeId     = $venue_id;
		$event->stato        = 3;
		$event->source       = $source;
		$event->lastSeenSync = 'token';
		return $event;
	}

	/** Create a sector fixture. */
	private function sector( ?int $remote_id, int $event_id, string $source ): Settore {
		$sector               = new Settore();
		$sector->idsettore    = $remote_id;
		$sector->eventoId     = $event_id;
		$sector->nome         = 'Platea';
		$sector->source       = $source;
		$sector->lastSeenSync = 'token';
		return $sector;
	}

	/** Create a price fixture. */
	private function price( ?int $remote_id, int $sector_id, string $amount, int $state, string $source ): Prezzo {
		$price               = new Prezzo();
		$price->idprezzo    = $remote_id;
		$price->settoreId   = $sector_id;
		$price->nome        = 'Intero';
		$price->tipo        = 'I';
		$price->importo     = $amount;
		$price->prevendita  = '1.00';
		$price->stato        = $state;
		$price->source      = $source;
		$price->lastSeenSync = 'token';
		return $price;
	}

	/** Persist and return a manual venue fixture. */
	private function venue(): int {
		$venue         = new Locale();
		$venue->nome   = 'Teatro Test';
		$venue->comune = 'Roma';
		$venue->source = 'manual';
		return $this->venues->save( $venue );
	}

	/** Seed a full hierarchy and return the title ID. */
	private function seed_full_hierarchy(): int {
		$title_id = $this->titles->save( $this->title( null, 'Edited Title', 'manual' ) );
		$title    = $this->titles->find( $title_id );
		$title->autore = 'Author';
		$this->titles->save( $title );

		$venue_id = $this->venue();
		$event    = $this->event( null, $title_id, $venue_id, 'manual' );
		$event->inizio = '2024-03-15 20:00:00';
		$event_id = $this->events->save( $event );

		$sector = $this->sector( null, $event_id, 'manual' );
		$sector->nome = 'Platea';
		$sector_id = $this->sectors->save( $sector );

		$price = $this->price( null, $sector_id, '10.50', 1, 'manual' );
		$price->nome = 'Intero';
		$this->prices->save( $price );

		return $title_id;
	}

	/** Submit a valid new title POST. */
	private function submit_valid_new_title(): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => '0',
				'titolo'                  => 'New Title',
				'autore'                  => 'New Author',
			)
		);
	}

	/** Submit an edit for an existing API title. */
	private function submit_edit_for_existing_api_title( int $title_id ): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'Edited API Title',
			)
		);
	}

	/** Submit an edit that removes all events. */
	private function submit_edit_removing_all_events( int $title_id ): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'Updated',
				'events'                  => array(),
			)
		);
	}

	/** Submit an edit removing sector_a1 from event_a. */
	private function submit_edit_removing_sector_a1( int $title_id, int $event_a, int $event_b, int $sector_b1, int $venue ): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'Updated',
				'events'                  => array(
					(string) $event_a => array(
						'id'        => (string) $event_a,
						'inizio'    => '2024-06-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(),
					),
					(string) $event_b => array(
						'id'        => (string) $event_b,
						'inizio'    => '2024-06-02 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(
							(string) $sector_b1 => array(
								'id'     => (string) $sector_b1,
								'nome'   => 'Sector B1',
								'prices' => array(),
							),
						),
					),
				),
			)
		);
	}

	/** Submit an edit removing price_a1 from sector_a. */
	private function submit_edit_removing_price_a1( int $title_id, int $event_id, int $venue, int $sector_a, int $sector_b, int $price_b1 ): void {
		$this->set_post_request(
			array(
				'cinebot_wp_titolo_nonce' => wp_create_nonce( 'cinebot_wp_save_titolo' ),
				'action'                  => 'cinebot_wp_save_titolo',
				'titolo_id'               => (string) $title_id,
				'titolo'                  => 'Updated',
				'events'                  => array(
					(string) $event_id => array(
						'id'        => (string) $event_id,
						'inizio'    => '2024-06-01 20:00',
						'locale_id' => (string) $venue,
						'sectors'   => array(
							(string) $sector_a => array(
								'id'     => (string) $sector_a,
								'nome'   => 'Sector A',
								'prices' => array(),
							),
							(string) $sector_b => array(
								'id'     => (string) $sector_b,
								'nome'   => 'Sector B',
								'prices' => array(
									(string) $price_b1 => array(
										'id'        => (string) $price_b1,
										'nome'      => 'Price B1',
										'tipo'      => 'I',
										'importo'   => '6.00',
										'prevendita'=> '1.00',
										'stato'     => '1',
									),
								),
							),
						),
					),
				),
			)
		);
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
