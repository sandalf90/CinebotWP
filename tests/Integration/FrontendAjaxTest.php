<?php
/**
 * Frontend AJAX filter integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Frontend\ShortcodeHandler;
use CinebotWp\Frontend\TemplateRenderer;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\TitoloRepository;
use WP_UnitTestCase;

/**
 * Tests the public AJAX filter endpoint for schedule cards.
 */
final class FrontendAjaxTest extends WP_UnitTestCase {
	/** @var TitoloRepository */
	private $titles;

	/** @var ShortcodeHandler */
	private $handler;

	/** Set up handler with repositories. */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		foreach ( array( 'eventi', 'titoli', 'locali' ) as $suffix ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'cinebot_' . $suffix );
		}
		$this->titles = new TitoloRepository( $wpdb );
		$this->handler = new ShortcodeHandler(
			$this->titles,
			new TemplateRenderer(),
			new EventoRepository( $wpdb )
		);
		$this->handler->register();
	}

	/** Clean transients. */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( 'eventi', 'titoli', 'locali' ) as $suffix ) {
			$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'cinebot_' . $suffix );
		}
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_cinebot_prog_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_cinebot_prog_' ) . '%'
			)
		);
		$_POST    = array();
		$_GET     = array();
		$_REQUEST = array();
		parent::tear_down();
	}

	/** AJAX filter rejects missing nonce. */
	public function test_rejects_missing_nonce(): void {
		$_POST = array( 'action' => 'cinebot_wp_filter' );

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertFalse( $json['success'] );
	}

	/** AJAX filter rejects invalid nonce. */
	public function test_rejects_invalid_nonce(): void {
		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => 'invalid',
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertFalse( $json['success'] );
	}

	/** AJAX filter returns HTML for valid request with no data. */
	public function test_returns_html_for_valid_request_no_data(): void {
		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => wp_create_nonce( 'cinebot_frontend' ),
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertTrue( $json['success'] );
		self::assertStringContainsString( 'Nessuna programmazione trovata', $json['data']['html'] );
		self::assertSame( 0, $json['data']['total'] );
		self::assertFalse( $json['data']['has_more'] );
	}

	/** AJAX filter returns cards with seeded data. */
	public function test_returns_cards_with_data(): void {
		$this->seed_active_event( '45', 'AJAX Test Show' );

		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => wp_create_nonce( 'cinebot_frontend' ),
			'tipo'   => '45',
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertIsArray( $json );
		self::assertTrue( $json['success'] );
		self::assertStringContainsString( 'AJAX Test Show', $json['data']['html'] );
		self::assertStringContainsString( 'cinebot-card', $json['data']['html'] );
		self::assertSame( 1, $json['data']['total'] );
	}

	/** AJAX filter respects tipo filter. */
	public function test_respects_tipo_filter(): void {
		$this->seed_active_event( '45', 'Teatro Show' );
		$this->seed_active_event( '53', 'Concert Show' );

		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => wp_create_nonce( 'cinebot_frontend' ),
			'tipo'   => '53',
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertTrue( $json['success'] );
		self::assertStringContainsString( 'Concert Show', $json['data']['html'] );
		self::assertStringNotContainsString( 'Teatro Show', $json['data']['html'] );
	}

	/** AJAX filter has_more is true when more results exist. */
	public function test_has_more_when_results_exceed_limit(): void {
		$this->seed_active_event( '45', 'Show A' );
		$this->seed_active_event( '45', 'Show B' );
		$this->seed_active_event( '45', 'Show C' );

		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => wp_create_nonce( 'cinebot_frontend' ),
			'limit'  => '2',
			'offset' => '0',
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertTrue( $json['success'] );
		self::assertTrue( $json['data']['has_more'] );
		self::assertSame( 3, $json['data']['total'] );
	}

	/** AJAX filter has_more is false when all results fit. */
	public function test_no_has_more_when_all_fit(): void {
		$this->seed_active_event( '45', 'Single Show' );

		$_POST = array(
			'action' => 'cinebot_wp_filter',
			'nonce'  => wp_create_nonce( 'cinebot_frontend' ),
			'limit'  => '50',
		);

		$_REQUEST = $_POST;

		ob_start();
		$this->handler->ajaxFilter();
		$output = (string) ob_get_clean();

		$json = json_decode( $output, true );
		self::assertTrue( $json['success'] );
		self::assertFalse( $json['data']['has_more'] );
	}

	/** AJAX filter endpoint is registered for public access. */
	public function test_nopriv_action_registered(): void {
		self::assertTrue( has_action( 'wp_ajax_nopriv_cinebot_wp_filter' ) !== false );
	}

	/** AJAX filter endpoint is registered for logged-in users. */
	public function test_priv_action_registered(): void {
		self::assertTrue( has_action( 'wp_ajax_cinebot_wp_filter' ) !== false );
	}

	/**
	 * Seed an active event.
	 *
	 * @return int Title ID.
	 */
	private function seed_active_event( string $type_code = '45', string $title_name = 'Test Show' ): int {
		global $wpdb;

		$wpdb->insert(
			$wpdb->prefix . 'cinebot_locali',
			array(
				'nome'       => 'Test Venue',
				'comune'     => 'Test City',
				'provincia'  => 'VI',
				'source'     => 'manual',
				'created_at' => current_time( 'mysql', true ),
				'updated_at' => current_time( 'mysql', true ),
			)
		);
		$venue_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$wpdb->prefix . 'cinebot_titoli',
			array(
				'titolo'            => $title_name,
				'tipoevento_codice' => $type_code,
				'source'            => 'manual',
				'sync_active'       => 1,
				'created_at'        => current_time( 'mysql', true ),
				'updated_at'        => current_time( 'mysql', true ),
			)
		);
		$title_id = (int) $wpdb->insert_id;

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
