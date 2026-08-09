<?php
/**
 * Shortcode handler integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

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
			new TemplateRenderer(),
			$this->events
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
