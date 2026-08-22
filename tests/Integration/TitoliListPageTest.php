<?php
/**
 * Programs list page integration tests.
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
use CinebotWp\Models\Titolo;
use CinebotWp\Plugin;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\CronScheduler;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use ReflectionMethod;
use ReflectionProperty;
use WP_UnitTestCase;
use wpdb;

/**
 * Verifies the programs list page, admin submenu, and plugin composition.
 */
final class TitoliListPageTest extends WP_UnitTestCase {
	/** @var wpdb */
	private static $db;

	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** @var TipologiaRepository */
	private $types;

	/** @var LocaleRepository */
	private $venues;

	/** @var TitoliListPage */
	private $page;

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
		set_current_screen( 'toplevel_page_cinebot-wp-programmazioni' );

		( new SchemaInstaller( self::$db ) )->install();
		$this->clear_tables();

		$this->titles  = new TitoloRepository( self::$db );
		$this->events  = new EventoRepository( self::$db );
		$this->types   = new TipologiaRepository( self::$db );
		$this->venues  = new LocaleRepository( self::$db );

		$this->page = new TitoliListPage(
			$this->titles,
			$this->events,
			$this->types
		);
		add_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
	}

	/** Clear hierarchy fixtures after each test. */
	public function tear_down(): void {
		remove_filter( 'wp_redirect', array( $this, 'intercept_redirect' ) );
		$this->clear_tables();
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/** Stop redirects before WordPress attempts to send test headers. */
	public function intercept_redirect( $location ) {
		throw new \WPDieException( is_string( $location ) ? $location : '' );
	}

	/** The declared columns match the brief. */
	public function test_get_columns_returns_expected_columns(): void {
		$columns = $this->page->get_columns();

		self::assertSame(
			array( 'cb', 'titolo', 'autore', 'tipoevento_codice', 'locandina_url', 'eventi_count', 'source', 'updated_at' ),
			array_keys( $columns )
		);
	}

	/** Pagination is clamped to 50 rows per page. */
	public function test_pagination_limits_to_50_per_page(): void {
		for ( $i = 0; $i < 60; ++$i ) {
			$this->titles->save( $this->title( null, sprintf( 'Title %02d', $i ), 'manual' ) );
		}

		$this->page->prepare_items();

		self::assertCount( 50, $this->page->items );
		self::assertSame( 50, (int) $this->page->get_pagination_arg( 'per_page' ) );
		self::assertSame( 60, (int) $this->page->get_pagination_arg( 'total_items' ) );

		$this->set_request( array( 'paged' => '2' ) );
		$this->page->prepare_items();

		self::assertCount( 10, $this->page->items );
	}

	/** Search matches title text. */
	public function test_search_by_title(): void {
		$this->titles->save( $this->title( null, 'Alpha', 'manual' ) );
		$this->titles->save( $this->title( null, 'Beta Needle', 'manual' ) );
		$this->titles->save( $this->title( null, 'Gamma', 'manual' ) );

		$this->set_request( array( 's' => 'needle' ) );
		$this->page->prepare_items();

		self::assertCount( 1, $this->page->items );
		self::assertSame( 'Beta Needle', $this->page->items[0]->titolo );
	}

	/** Search matches author text. */
	public function test_search_by_author(): void {
		$one = $this->title( null, 'Alpha', 'manual' );
		$one->autore = 'Needle Author';
		$this->titles->save( $one );
		$this->titles->save( $this->title( null, 'Beta', 'manual' ) );

		$this->set_request( array( 's' => 'needle' ) );
		$this->page->prepare_items();

		self::assertCount( 1, $this->page->items );
		self::assertSame( 'Alpha', $this->page->items[0]->titolo );
	}

	/** The tipoevento_codice filter restricts the result set. */
	public function test_filter_by_tipoevento_codice(): void {
		$one = $this->title( null, 'Cinema', 'manual' );
		$one->tipoeventoCodice = '01';
		$this->titles->save( $one );
		$two = $this->title( null, 'Theatre', 'manual' );
		$two->tipoeventoCodice = '45';
		$this->titles->save( $two );

		$this->set_request( array( 'tipoevento_codice' => '01' ) );
		$this->page->prepare_items();

		self::assertCount( 1, $this->page->items );
		self::assertSame( 'Cinema', $this->page->items[0]->titolo );
	}

	/** The source filter restricts the result set to api or manual. */
	public function test_filter_by_source(): void {
		$this->titles->save( $this->title( 10, 'API Title', 'api' ) );
		$this->titles->save( $this->title( null, 'Manual Title', 'manual' ) );

		$this->set_request( array( 'source' => 'manual' ) );
		$this->page->prepare_items();

		self::assertCount( 1, $this->page->items );
		self::assertSame( 'Manual Title', $this->page->items[0]->titolo );
	}

	/** The poster thumbnail is rendered with an escaped URL. */
	public function test_poster_thumbnail_escaped_in_render(): void {
		$title = $this->title( null, 'Poster Title', 'manual' );
		$title->locandinaUrl = 'https://example.test/poster.jpg';
		$this->titles->save( $title );

		$output = $this->capture_render();

		self::assertStringContainsString( 'src="https://example.test/poster.jpg"', $output );
		self::assertStringContainsString( 'loading="lazy"', $output );
	}

	/** A title without a poster shows a dash, not a broken image. */
	public function test_missing_poster_shows_dash(): void {
		$this->titles->save( $this->title( null, 'No Poster', 'manual' ) );

		$output = $this->capture_render();

		self::assertStringNotContainsString( '<img', $output );
		self::assertStringContainsString( '&mdash;', $output );
	}

	/** The event count column reflects the repository count. */
	public function test_event_count_displayed_in_render(): void {
		$title_id = $this->titles->save( $this->title( null, 'Counted', 'manual' ) );
		$venue_id = $this->venue( 'Venue', 'Roma' );
		$this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );
		$this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );
		$this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );

		$output = $this->capture_render();

		self::assertStringContainsString( '>3<', $output );
	}

	/** A single delete without a nonce is rejected. */
	public function test_single_delete_requires_nonce(): void {
		$title_id = $this->titles->save( $this->title( null, 'Protected', 'manual' ) );
		$this->set_request( array( 'action' => 'delete', 'titolo' => (string) $title_id ) );

		$this->expectException( \WPDieException::class );
		$this->page->render();
	}

	/** A single delete with a valid nonce removes its events and title. */
	public function test_single_delete_with_valid_nonce_cascades(): void {
		$title_id = $this->titles->save( $this->title( null, 'Cascade', 'manual' ) );
		$venue_id = $this->venue( 'Cascade Venue', 'Roma' );
		$this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );

		$this->set_request(
			array(
				'action'   => 'delete',
				'titolo'   => (string) $title_id,
				'_wpnonce' => wp_create_nonce( 'cinebot-wp-delete-titolo_' . $title_id ),
			)
		);

		try {
			$this->page->render();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		self::assertNull( $this->titles->find( $title_id ) );
		self::assertCount( 0, $this->events->findByTitoloId( $title_id ) );
	}

	/** Bulk delete with a valid nonce removes selected titles and events. */
	public function test_bulk_delete_cascades(): void {
		$first_title  = $this->titles->save( $this->title( null, 'Bulk One', 'manual' ) );
		$second_title = $this->titles->save( $this->title( null, 'Bulk Two', 'manual' ) );
		$venue_id    = $this->venue( 'Bulk Venue', 'Roma' );
		$this->events->save( $this->event( null, $first_title, $venue_id, 'manual' ) );
		$this->events->save( $this->event( null, $second_title, $venue_id, 'manual' ) );

		$this->set_post_request(
			array(
				'action'    => 'delete',
				'titolo'    => array( (string) $first_title, (string) $second_title ),
				'_wpnonce'  => wp_create_nonce( 'bulk-titoli' ),
			)
		);

		try {
			$this->page->render();
		} catch ( \WPDieException $e ) {
			// Redirect intercepted.
		}

		self::assertNull( $this->titles->find( $first_title ) );
		self::assertNull( $this->titles->find( $second_title ) );
		self::assertCount( 0, $this->events->findByTitoloId( $first_title ) );
		self::assertCount( 0, $this->events->findByTitoloId( $second_title ) );
	}

	/** The rendered page exposes edit and new-title actions. */
	public function test_edit_and_new_actions_in_render(): void {
		$this->titles->save( $this->title( null, 'Rendered', 'manual' ) );

		$output = $this->capture_render();

		self::assertStringContainsString( 'cinebot-wp-programmazione-edit', $output );
		self::assertStringContainsStringIgnoringCase( 'Nuovo titolo', $output );
		self::assertStringContainsStringIgnoringCase( 'Modifica', $output );
		self::assertStringContainsString( 'action=delete', $output );
	}

	/** The admin menu registers the Programmazioni submenu. */
	public function test_admin_menu_registers_programmazioni_submenu(): void {
		$settings    = new SettingsService();
		$sync        = new SyncService( self::$db );
		$scheduler   = new CronScheduler( $settings, $sync );
		$log_repo    = new \CinebotWp\Repositories\SyncLogRepository( self::$db );
		$dashboard   = new DashboardPage( $settings, $this->titles, $log_repo );
		$api_page    = new ApiPage( $settings, $scheduler, $sync );
		$edit_page   = new TitoloEditPage(
			$this->titles,
			$this->events,
			$this->types,
			$this->venues
		);
		$locali_page = new \CinebotWp\Admin\Pages\LocaliListPage( $this->venues, $this->events );
		$locale_edit = new \CinebotWp\Admin\Pages\LocaleEditPage( $this->venues );
		$tipologie_page = new \CinebotWp\Admin\Pages\TipologieListPage( $this->types );
		$tipologia_edit = new \CinebotWp\Admin\Pages\TipologiaEditPage( $this->types );
		$log_page   = new \CinebotWp\Admin\Pages\SyncLogPage( $log_repo );
		$menu       = new AdminMenu( $dashboard, $api_page, $this->page, $edit_page, $locali_page, $locale_edit, $tipologie_page, $tipologia_edit, $log_page );

		$menu->register();
		do_action( 'admin_menu' );

		global $submenu;
		self::assertArrayHasKey( 'cinebot-wp', $submenu );
		$slugs = array();
		foreach ( $submenu['cinebot-wp'] as $entry ) {
			if ( isset( $entry[2] ) ) {
				$slugs[] = $entry[2];
			}
		}
		self::assertContains( 'cinebot-wp-programmazioni', $slugs );
	}

	/** The plugin composes TitoliListPage into the admin menu. */
	public function test_plugin_composes_titoli_list_page(): void {
		$method = new ReflectionMethod( Plugin::class, 'admin_menu' );
		$method->setAccessible( true );
		$menu = $method->invoke( null );

		$property = new ReflectionProperty( AdminMenu::class, 'titoli_page' );
		$property->setAccessible( true );

		self::assertInstanceOf( TitoliListPage::class, $property->getValue( $menu ) );
	}

	/** Clear title and event fixtures in child-first order. */
	private function clear_tables(): void {
		foreach ( array( 'eventi', 'titoli', 'locali' ) as $suffix ) {
			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
		}
	}

	/** Set GET and REQUEST parameters. */
	private function set_request( array $params ): void {
		foreach ( $params as $key => $value ) {
			$_GET[ $key ]     = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/** Set POST and REQUEST parameters. */
	private function set_post_request( array $params ): void {
		foreach ( $params as $key => $value ) {
			$_POST[ $key ]    = $value;
			$_REQUEST[ $key ] = $value;
		}
	}

	/** Capture the rendered page output. */
	private function capture_render(): string {
		ob_start();
		$this->page->render();
		return (string) ob_get_clean();
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
		$event->inizio       = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 5 );
		$event->localeId     = $venue_id;
		$event->stato        = 3;
		$event->source       = $source;
		$event->lastSeenSync = 'token';
		return $event;
	}

	/** Persist and return a manual venue fixture. */
	private function venue( string $name, string $city ): int {
		$venue         = new Locale();
		$venue->nome   = $name;
		$venue->comune = $city;
		$venue->source = 'manual';
		return $this->venues->save( $venue );
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
