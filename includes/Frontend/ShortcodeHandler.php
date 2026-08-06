<?php
/**
 * Shortcode handler for public schedule display.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Frontend;

use CinebotWp\Repositories\TitoloRepository;

/**
 * Registers and renders the [cinebot_programmazione] and [cinebot_titolo] shortcodes.
 */
final class ShortcodeHandler {
	/** @var TitoloRepository */
	private $titles;

	/** @var TemplateRenderer */
	private $renderer;

	/** @var int */
	private static $instance_id = 0;

	/** @param TitoloRepository $titles */
	public function __construct( TitoloRepository $titles, TemplateRenderer $renderer ) {
		$this->titles   = $titles;
		$this->renderer  = $renderer;
	}

	/** Register shortcodes and AJAX actions. */
	public function register(): void {
		add_shortcode( 'cinebot_programmazione', array( $this, 'renderProgrammazione' ) );
		add_shortcode( 'cinebot_titolo', array( $this, 'renderTitolo' ) );
	}

	/**
	 * Render the programmazione shortcode.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string HTML output.
	 */
	public function renderProgrammazione( array $attributes = array() ): string {
		$atts = $this->normalizeAttributes( $attributes );

		$cache_key = 'cinebot_prog_' . md5( wp_json_encode( $atts ) );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$cards = $this->titles->findPublicSchedule( $atts );
		$total = $this->titles->countPublicSchedule( $atts );

		$html = $this->renderer->render( 'programmazione-cards', array(
			'cards'     => $cards,
			'total'     => $total,
			'atts'      => $atts,
			'instance'  => ++self::$instance_id,
		) );

		$ttl = (int) apply_filters( 'cinebot_wp_cache_ttl', 900 );
		set_transient( $cache_key, $html, $ttl );

		return $html;
	}

	/**
	 * Render the single title detail shortcode.
	 *
	 * @param array $attributes Shortcode attributes.
	 * @return string HTML output.
	 */
	public function renderTitolo( array $attributes = array() ): string {
		$id = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
		if ( $id <= 0 ) {
			return '';
		}

		$title = $this->titles->find( $id );
		if ( null === $title ) {
			return '';
		}

		return $this->renderer->render( 'dettaglio-titolo', array(
			'title'   => $title,
			'events'  => array(), // Events loaded via EventoRepository in full implementation.
		) );
	}

	/**
	 * Normalize and sanitize shortcode attributes.
	 */
	private function normalizeAttributes( array $attributes ): array {
		$defaults = array(
			'tipo'         => '',
			'locale'       => 0,
			'comune'       => '',
			'from'         => current_time( 'Y-m-d', true ),
			'to'           => '',
			'limit'        => 50,
			'orderby'      => 'inizio',
			'order'        => 'ASC',
			'show_filters' => true,
			'show_desc'    => false,
			'layout'       => 'cards',
			'offset'       => 0,
		);

		$atts = shortcode_atts( $defaults, $attributes, 'cinebot_programmazione' );

		$atts['limit']  = max( 1, min( 100, (int) $atts['limit'] ) );
		$atts['offset'] = max( 0, (int) $atts['offset'] );
		$atts['locale'] = absint( $atts['locale'] );

		if ( ! in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true ) ) {
			$atts['order'] = 'ASC';
		}
		if ( ! in_array( $atts['orderby'], array( 'inizio', 'titolo' ), true ) ) {
			$atts['orderby'] = 'inizio';
		}

		$atts['show_filters'] = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
		$atts['show_desc']    = filter_var( $atts['show_desc'], FILTER_VALIDATE_BOOLEAN );

		return $atts;
	}
}
