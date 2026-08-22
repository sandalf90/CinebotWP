<?php
/**
 * Shortcode handler integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

// Fixtures use trusted, fixed plugin table identifiers.
// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Frontend\ShortcodeHandler;
use CinebotWp\Frontend\TemplateRenderer;
use CinebotWp\Models\Titolo;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\TitoloRepository;
use WP_UnitTestCase;

/**
 * Tests shortcode rendering, attribute normalization, caching, and template override.
 */
final class ShortcodeHandlerTest extends WP_UnitTestCase {
	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** @var ShortcodeHandler */
	private $handler;

	/** Set up repositories and handler with clean state. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		( new SchemaInstaller( $wpdb ) )->install();
		$this->titles = new TitoloRepository( $wpdb );
		$this->events = new EventoRepository( $wpdb );
		$this->handler = new ShortcodeHandler(
			$this->titles,
			new TemplateRenderer()
		);
		$this->handler->register();
		delete_transient( 'cinebot_prog_' . md5( wp_json_encode( array() ) ) );
	}

	/** Clean up transients. */
	public function tear_down(): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cinebot_prog_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cinebot_prog_' ) . '%'
			)
		);
		parent::tear_down();
	}

	/** Shortcode renders a container with no results when no data. */
	public function test_renders_container_with_no_results(): void {
		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringContainsString( 'cinebot-programmazione', $html );
		self::assertStringContainsString( 'Nessuna programmazione trovata', $html );
	}

	/** Shortcode renders filters by default. */
	public function test_renders_filters_by_default(): void {
		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringContainsString( 'cinebot-filters', $html );
		self::assertStringContainsString( 'name="tipo"', $html );
		self::assertStringContainsString( 'name="from"', $html );
		self::assertStringContainsString( 'name="comune"', $html );
	}

	/** Shortcode hides filters when show_filters=false. */
	public function test_hides_filters_when_disabled(): void {
		$html = do_shortcode( '[cinebot_programmazione show_filters="false"]' );

		self::assertStringNotContainsString( 'cinebot-filters', $html );
	}

	/** Shortcode renders cards with data. */
	public function test_renders_cards_with_data(): void {
		$this->seed_active_event();

		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringContainsString( 'cinebot-card', $html );
		self::assertStringContainsString( 'Test Show Title', $html );
	}

	/** Shortcode filters by tipo. */
	public function test_filters_by_tipo(): void {
		$this->seed_active_event( '45', 'Teatro Prosa Show' );
		$this->seed_active_event( '53', 'Concert Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="45"]' );

		self::assertStringContainsString( 'Teatro Prosa Show', $html );
		self::assertStringNotContainsString( 'Concert Show', $html );
	}

	/** Shortcode filters by exclude_tipo (all types except the given code). */
	public function test_filters_by_exclude_tipo(): void {
		$this->seed_active_event( '01', 'Cinema Show' );
		$this->seed_active_event( '45', 'Teatro Prosa Show' );

		$html = do_shortcode( '[cinebot_programmazione exclude_tipo="01"]' );

		self::assertStringNotContainsString( 'Cinema Show', $html );
		self::assertStringContainsString( 'Teatro Prosa Show', $html );
	}

	/** tipo takes precedence over exclude_tipo when both are set. */
	public function test_tipo_takes_precedence_over_exclude_tipo(): void {
		$this->seed_active_event( '01', 'Cinema Show' );
		$this->seed_active_event( '45', 'Teatro Prosa Show' );
		$this->seed_active_event( '53', 'Concert Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" exclude_tipo="45"]' );

		self::assertStringContainsString( 'Cinema Show', $html );
		self::assertStringNotContainsString( 'Teatro Prosa Show', $html );
		self::assertStringNotContainsString( 'Concert Show', $html );
	}

	/** Shortcode excludes inactive events. */
	public function test_excludes_inactive_events(): void {
		global $wpdb;

		$title_id = $this->seed_active_event( '45', 'Active Show' );

		// Deactivate the event.
		$wpdb->update(
			$wpdb->prefix . 'cinebot_eventi',
			array( 'sync_active' => 0 ),
			array( 'titolo_id' => $title_id ),
			array( '%d' ),
			array( '%d' )
		);

		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringNotContainsString( 'Active Show', $html );
	}

	/** Shortcode excludes events with stato != 3. */
	public function test_excludes_non_state_3_events(): void {
		global $wpdb;

		$title_id = $this->seed_active_event( '45', 'State 2 Show' );

		// Change event stato to 2.
		$wpdb->update(
			$wpdb->prefix . 'cinebot_eventi',
			array( 'stato' => 2 ),
			array( 'titolo_id' => $title_id ),
			array( '%d' ),
			array( '%d' )
		);

		$html = do_shortcode( '[cinebot_programmazione]' );

		self::assertStringNotContainsString( 'State 2 Show', $html );
	}

	/** Shortcode clamps limit to 1-100. */
	public function test_clamps_limit(): void {
		$html = do_shortcode( '[cinebot_programmazione limit="0"]' );
		self::assertStringContainsString( 'cinebot-programmazione', $html );

		$html = do_shortcode( '[cinebot_programmazione limit="999"]' );
		self::assertStringContainsString( 'cinebot-programmazione', $html );
	}

	/** Invalid orderby falls back to inizio. */
	public function test_invalid_orderby_falls_back(): void {
		$html = do_shortcode( '[cinebot_programmazione orderby="invalid_field"]' );
		self::assertStringContainsString( 'cinebot-programmazione', $html );
	}

	/** Invalid order falls back to ASC. */
	public function test_invalid_order_falls_back(): void {
		$html = do_shortcode( '[cinebot_programmazione order="sideways"]' );
		self::assertStringContainsString( 'cinebot-programmazione', $html );
	}

	/** [cinebot_titolo] renders detail page for existing title. */
	public function test_titolo_shortcode_renders_detail(): void {
		$title_id = $this->seed_active_event( '45', 'Detail Test Show' );

		$html = do_shortcode( "[cinebot_titolo id=\"{$title_id}\"]" );

		self::assertStringContainsString( 'Detail Test Show', $html );
		self::assertStringContainsString( 'cinebot-titolo-dettaglio', $html );
	}

	/** [cinebot_titolo] returns empty for non-existent ID. */
	public function test_titolo_shortcode_empty_for_missing(): void {
		$html = do_shortcode( '[cinebot_titolo id="99999"]' );
		self::assertSame( '', $html );
	}

	/** [cinebot_titolo] returns empty for invalid ID. */
	public function test_titolo_shortcode_empty_for_invalid_id(): void {
		$html = do_shortcode( '[cinebot_titolo id="0"]' );
		self::assertSame( '', $html );
	}

	/** Shortcode uses transient cache. */
	public function test_uses_transient_cache(): void {
		$html1 = do_shortcode( '[cinebot_programmazione tipo="45"]' );

		// Second call should return cached result.
		$html2 = do_shortcode( '[cinebot_programmazione tipo="45"]' );

		self::assertSame( $html1, $html2 );
	}

	/** Shortcode registers both shortcodes. */
	public function test_registers_both_shortcodes(): void {
		self::assertTrue( shortcode_exists( 'cinebot_programmazione' ) );
		self::assertTrue( shortcode_exists( 'cinebot_titolo' ) );
	}

	/** more_url renders "Vedi altro" link when there are more results. */
	public function test_renders_vedi_altro_when_more_url_set(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/programmazione-cinema"]' );

		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
		self::assertStringContainsString( 'href="/programmazione-cinema"', $html );
		self::assertStringContainsString( 'Vedi altro', $html );
		self::assertStringNotContainsString( 'cinebot-load-more', $html );
	}

	/** more_url does not render "Vedi altro" when all results are shown. */
	public function test_no_vedi_altro_when_all_results_shown(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="100" more_url="/x"]' );

		self::assertStringNotContainsString( 'cinebot-vedi-altro', $html );
	}

	/** more_label overrides the default button text. */
	public function test_more_label_overrides_default(): void {
		$this->seed_active_event( '01', 'Cinema Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/x" more_label="Tutti i film"]' );

		self::assertStringContainsString( 'Tutti i film', $html );
	}

	/** Numbered pagination renders page links when total > per_page. */
	public function test_numbered_pagination_renders_links(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );

		self::assertStringContainsString( 'cinebot-pagination', $html );
		self::assertStringContainsString( 'cinebot_page=1', $html );
		self::assertStringContainsString( 'cinebot_page=2', $html );
		self::assertStringContainsString( 'cinebot_page=3', $html );
		self::assertStringNotContainsString( 'cinebot-load-more', $html );
	}

	/** Numbered pagination returns correct page when cinebot_page is set. */
	public function test_numbered_pagination_page_2_offset(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$_GET['cinebot_page'] = '2';
		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );
		unset( $_GET['cinebot_page'] );

		self::assertStringContainsString( 'cinebot-page-current', $html );
		self::assertStringContainsString( 'Cinema Show 2', $html );
		self::assertStringContainsString( 'Cinema Show 3', $html );
		self::assertStringNotContainsString( 'Cinema Show 0', $html );
		self::assertStringNotContainsString( 'Cinema Show 1', $html );
	}

	/** Numbered pagination does not render nav when only one page. */
	public function test_numbered_pagination_no_nav_single_page(): void {
		$this->seed_active_event( '01', 'Single Show' );

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="20"]' );

		self::assertStringNotContainsString( 'cinebot-pagination', $html );
	}

	/** more_url takes precedence over numbered pagination. */
	public function test_more_url_takes_precedence_over_numbered(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_active_event( '01', 'Cinema Show ' . $i );
		}

		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2" more_url="/x"]' );

		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
		self::assertStringNotContainsString( 'cinebot-pagination', $html );
	}

	/**
	 * Seed an active event with a title, venue, and event.
	 *
	 * @return int Title ID.
	 */
	private function seed_active_event( string $type_code = '45', string $title_name = 'Test Show Title' ): int {
		global $wpdb;

		// Ensure venue exists.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_locali',
			array(
				'nome'       => 'Test Venue',
				'comune'     => 'Bassano del Grappa',
				'provincia'  => 'VI',
				'source'     => 'manual',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$venue_id = (int) $wpdb->insert_id;

		// Insert title.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'             => $title_name,
				'tipoevento_codice'  => $type_code,
				'source'             => 'manual',
				'sync_active'        => 1,
				'locandina_url'      => 'https://example.com/poster.jpg',
				'created_at'         => current_time( 'mysql', true ),
				'updated_at'         => current_time( 'mysql', true ),
			)
		);
		$title_id = (int) $wpdb->insert_id;

		// Insert event with future date and stato=3.
		$wpdb->insert(
			$wpdb->prefix . 'cinebot_eventi',
			array(
				'titolo_id'   => $title_id,
				'inizio'      => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
				'locale_id'   => $venue_id,
				'stato'       => 3,
				'source'      => 'manual',
				'sync_active' => 1,
				'created_at'  => current_time( 'mysql', true ),
				'updated_at'  => current_time( 'mysql', true ),
			)
		);

		return $title_id;
	}
}

// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
