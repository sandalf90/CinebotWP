<?php
/**
 * Shortcode handler for public schedule display.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Frontend;

use CinebotWp\ReadModels\TitoloDetail;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SettingsService;

/**
 * Registers and renders the [cinebot_programmazione] and [cinebot_titolo] shortcodes.
 */
final class ShortcodeHandler {
	/** @var TitoloRepository */
	private $titles;

	/** @var SettingsService|null */
	private $settings;

	/** @var LocaleRepository|null */
	private $locales;

	/** @var TipologiaRepository|null */
	private $tipologie;

	/** @var TemplateRenderer */
	private $renderer;

	/** @var int */
	private static $instance_id = 0;

	/** @var array<int,TitoloDetail|null> Per-request memoization cache keyed by titolo ID. */
	private $detail_cache = array();

	/**
	 * Store repository and renderer collaborators.
	 */
	public function __construct( TitoloRepository $titles, TemplateRenderer $renderer, ?SettingsService $settings = null, ?LocaleRepository $locales = null, ?TipologiaRepository $tipologie = null ) {
		$this->titles    = $titles;
		$this->renderer  = $renderer;
		$this->settings  = $settings;
		$this->locales   = $locales;
		$this->tipologie = $tipologie;
	}

	/** Register shortcodes and AJAX actions. */
	public function register(): void {
		add_shortcode( 'cinebot_programmazione', array( $this, 'renderProgrammazione' ) );
		add_shortcode( 'cinebot_titolo', array( $this, 'renderTitolo' ) );

		add_shortcode( 'cinebot_titolo_titolo', array( $this, 'renderDetailTitolo' ) );
		add_shortcode( 'cinebot_titolo_autore', array( $this, 'renderDetailAutore' ) );
		add_shortcode( 'cinebot_titolo_esecutore', array( $this, 'renderDetailEsecutore' ) );
		add_shortcode( 'cinebot_titolo_giorno', array( $this, 'renderDetailGiorno' ) );
		add_shortcode( 'cinebot_titolo_durata', array( $this, 'renderDetailDurata' ) );
		add_shortcode( 'cinebot_titolo_prezzo', array( $this, 'renderDetailPrezzo' ) );
		add_shortcode( 'cinebot_titolo_locale', array( $this, 'renderDetailLocale' ) );
		add_shortcode( 'cinebot_titolo_descrizione', array( $this, 'renderDetailDescrizione' ) );
		add_shortcode( 'cinebot_titolo_immagine', array( $this, 'renderDetailImmagine' ) );
		add_shortcode( 'cinebot_titolo_eventi', array( $this, 'renderDetailEventi' ) );

		add_action( 'wp_ajax_cinebot_wp_filter', array( $this, 'ajaxFilter' ) );
		add_action( 'wp_ajax_nopriv_cinebot_wp_filter', array( $this, 'ajaxFilter' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybeEnqueueAssets' ) );

		add_action( 'init', array( $this, 'registerRewriteRules' ) );
		add_filter( 'query_vars', array( $this, 'registerQueryVars' ) );
	}

	/** Register custom rewrite rules if a detail slug is configured. */
	public function registerRewriteRules(): void {
		if ( null === $this->settings ) {
			return;
		}

		$slug = $this->settings->detailSlug();
		if ( '' !== $slug ) {
			add_rewrite_rule(
				'^' . preg_quote( $slug, '/' ) . '/([0-9]+)/([^/]+)/?$',
				'index.php?pagename=' . $slug . '&titolo_id=$matches[1]',
				'top'
			);
		}
	}

	/** Add titolo_id to allowed query variables. */
	public function registerQueryVars( array $vars ): array {
		$vars[] = 'titolo_id';
		return $vars;
	}

	/**
	 * Enqueue frontend assets only when a shortcode is present on the page.
	 */
	public function maybeEnqueueAssets(): void {
		// Assets are enqueued from renderProgrammazione to ensure they load only when needed.
	}

	/**
	 * AJAX handler for filtering and load-more.
	 */
	public function ajaxFilter(): void {
		if ( ! check_ajax_referer( 'cinebot_frontend', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'cinebot-wp' ) ), 403 );
			return; // @phpstan-ignore deadCode.unreachable
		}

		$atts = $this->normalizeAttributes( array(
			'from'         => isset( $_POST['from'] ) ? sanitize_text_field( wp_unslash( $_POST['from'] ) ) : '',
			'to'           => isset( $_POST['to'] ) ? sanitize_text_field( wp_unslash( $_POST['to'] ) ) : '',
			'tipo'         => isset( $_POST['tipo'] ) ? sanitize_text_field( wp_unslash( $_POST['tipo'] ) ) : '',
			'exclude_tipo' => isset( $_POST['exclude_tipo'] ) ? sanitize_text_field( wp_unslash( $_POST['exclude_tipo'] ) ) : '',
			'locale'       => isset( $_POST['locale'] ) ? absint( $_POST['locale'] ) : 0,
			'limit'        => isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50,
			'offset'       => isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0,
			'order'        => isset( $_POST['order'] ) ? sanitize_text_field( wp_unslash( $_POST['order'] ) ) : 'ASC',
			'orderby'      => isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : 'inizio',
		) );

		$cards = $this->titles->findPublicSchedule( $atts );
		$total = $this->titles->countPublicSchedule( $atts );

		$html = $this->renderer->render( 'titolo-card-list', array(
			'cards'      => $cards,
			'show_desc'  => $atts['show_desc'],
			'detail_url' => isset( $_POST['detail_url'] ) ? esc_url_raw( wp_unslash( $_POST['detail_url'] ) ) : '',
		) );

		wp_send_json_success( array(
			'html'     => $html,
			'total'    => $total,
			'has_more' => ( $atts['offset'] + count( $cards ) ) < $total,
		) );
		return; // @phpstan-ignore deadCode.unreachable
	}

	/**
	 * Render the programmazione shortcode.
	 *
	 * @param array|string $attributes Shortcode attributes (empty string when no attrs).
	 * @return string HTML output.
	 */
	public function renderProgrammazione( $attributes = array() ): string {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, no mutation.
		$url_overrides = array();
		if ( isset( $_GET['from'] ) ) {
			$url_overrides['from'] = sanitize_text_field( wp_unslash( $_GET['from'] ) );
		}
		if ( isset( $_GET['to'] ) ) {
			$url_overrides['to'] = sanitize_text_field( wp_unslash( $_GET['to'] ) );
		}
		if ( isset( $_GET['locale'] ) ) {
			$url_overrides['locale'] = absint( $_GET['locale'] );
		}

		$atts = $this->normalizeAttributes( array_merge( $attributes, $url_overrides ) );

		$current_page = 1;
		$total_pages  = 0;
		$base_url     = '';

		if ( 'numbered' === $atts['pagination'] ) {
			$atts['limit'] = $atts['per_page'];
			if ( empty( $atts['more_url'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no mutation.
				$current_page = isset( $_GET['cinebot_page'] ) ? max( 1, absint( wp_unslash( $_GET['cinebot_page'] ) ) ) : 1;
				$atts['offset']       = ( $current_page - 1 ) * $atts['per_page'];
				$atts['current_page'] = $current_page;
			}
		}

		$resolved_detail_url = $this->resolveDetailUrl( $atts );

		$cache_key = 'cinebot_prog_' . md5( wp_json_encode( $atts ) . $resolved_detail_url . $this->templateVersion() );
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			$this->enqueueFrontendAssets();
			return $cached;
		}

		$cards = $this->titles->findPublicSchedule( $atts );
		$total = $this->titles->countPublicSchedule( $atts );

		if ( 'numbered' === $atts['pagination'] && empty( $atts['more_url'] ) ) {
			$total_pages = max( 1, (int) ceil( $total / $atts['per_page'] ) );
			$base_url    = esc_url_raw( remove_query_arg( 'cinebot_page' ) );
		}

		$locali = null !== $this->locales ? $this->locales->findAll() : array();
		$tipologie_list = null !== $this->tipologie ? $this->tipologie->findAll( true ) : array();

		$html = $this->renderer->render( 'programmazione-cards', array(
			'cards'        => $cards,
			'total'        => $total,
			'atts'         => $atts,
			'detail_url'   => $resolved_detail_url,
			'instance'     => ++self::$instance_id,
			'current_page' => $current_page,
			'total_pages'  => $total_pages,
			'base_url'     => $base_url,
			'locali'       => $locali,
			'tipologie'    => $tipologie_list,
		) );

		$this->enqueueFrontendAssets();

		$ttl = (int) apply_filters( 'cinebot_wp_cache_ttl', 900 );
		set_transient( $cache_key, $html, $ttl );

		return $html;
	}

	/**
	 * Render the single title detail shortcode.
	 *
	 * @param array|string $attributes Shortcode attributes (empty string when no attrs).
	 * @return string HTML output.
	 */
	public function renderTitolo( $attributes = array() ): string {
		$id = $this->resolveDetailId( $attributes );
		if ( $id <= 0 ) {
			return '';
		}

		$detail = $this->getDetail( $id );
		if ( null === $detail || null === $detail->title ) {
			return '';
		}

		$this->enqueueFrontendAssets();

		return $this->renderer->render( 'dettaglio-titolo', array(
			'detail' => $detail,
		) );
	}

	/**
	 * Resolve a detail shortcode's titolo ID from its attributes or the URL.
	 *
	 * Shortcode attributes take precedence; when absent, the titolo_id query
	 * parameter is read so a single WP page can serve every detail.
	 *
	 * @param array|string $attributes Shortcode attributes.
	 */
	private function resolveDetailId( $attributes ): int {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}
		$id = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
		if ( $id <= 0 ) {
			$id = absint( get_query_var( 'titolo_id' ) );
		}
		if ( $id <= 0 ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only ID, no mutation.
			$id = isset( $_GET['titolo_id'] ) ? absint( wp_unslash( $_GET['titolo_id'] ) ) : 0;
		}
		return $id;
	}

	/**
	 * Load and memoize the TitoloDetail for the current request.
	 *
	 * Multiple detail shortcodes on the same page share one projection.
	 */
	private function getDetail( int $id ): ?TitoloDetail {
		if ( isset( $this->detail_cache[ $id ] ) ) {
			return $this->detail_cache[ $id ];
		}
		$detail = $this->titles->findDetail( $id );
		$this->detail_cache[ $id ] = $detail;
		return $detail;
	}

	/** Format a Y-m-d date as Italian "GiornoNome gg/mm". */
	private function formatGiorno( string $date ): string {
		$ts      = strtotime( $date );
		$giorni  = array( 'Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato' );
		$nome    = $giorni[ (int) gmdate( 'w', $ts ) ];
		return $nome . ' ' . gmdate( 'd/m', $ts );
	}

	/** Format a decimal string as "20.00" (two decimals, dot separator). */
	private function formatPrezzo( string $value ): string {
		return number_format( (float) $value, 2, '.', ',' );
	}

	/** [cinebot_titolo_titolo] — render the title text. */
	public function renderDetailTitolo( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title ) {
			return '';
		}
		return esc_html( $detail->title->titolo );
	}

	/** [cinebot_titolo_autore] — render the author. */
	public function renderDetailAutore( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title || null === $detail->title->autore ) {
			return '';
		}
		return esc_html( $detail->title->autore );
	}

	/** [cinebot_titolo_esecutore] — render the performer. */
	public function renderDetailEsecutore( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title || null === $detail->title->esecutore ) {
			return '';
		}
		return esc_html( $detail->title->esecutore );
	}

	/**
	 * [cinebot_titolo_giorno] — smart day display.
	 *
	 * Single day → "Giovedì 18/10".
	 * Multiple days → "Da Giovedì 18/10 a Domenica 20/10".
	 */
	public function renderDetailGiorno( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title || null === $detail->primoGiorno ) {
			return '';
		}
		if ( $detail->giorniCount <= 1 ) {
			return esc_html( $this->formatGiorno( $detail->primoGiorno ) );
		}
		if ( null === $detail->ultimoGiorno ) {
			return esc_html( $this->formatGiorno( $detail->primoGiorno ) );
		}
		return 'Da ' . esc_html( $this->formatGiorno( $detail->primoGiorno ) ) . ' a ' . esc_html( $this->formatGiorno( $detail->ultimoGiorno ) );
	}

	/**
	 * [cinebot_titolo_durata] — smart time display.
	 *
	 * Single event → "dalle 21:00 alle 23:00" (start → start + durata).
	 * Multiple events → "120 minuti" (the title's durata value).
	 */
	public function renderDetailDurata( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title ) {
			return '';
		}
		if ( $detail->eventiCount <= 1 && ! empty( $detail->eventi ) ) {
			$riga     = $detail->eventi[0];
			$start_ts = strtotime( $riga->inizio );
			$start    = gmdate( 'H:i', $start_ts );
			$durata   = $detail->title->durata ?? 0;
			$end      = gmdate( 'H:i', $start_ts + ( $durata * 60 ) );
			return 'dalle ' . esc_html( $start ) . ' alle ' . esc_html( $end );
		}
		if ( $detail->eventiCount > 1 && $detail->title->durata ) {
			return esc_html( (string) $detail->title->durata ) . ' minuti';
		}
		return '';
	}

	/**
	 * [cinebot_titolo_prezzo] — smart price display.
	 *
	 * The prevendita fee is subtracted from the prezzo so that the face
	 * value is shown, with "+ d.d.p." indicating presale rights apply.
	 *
	 * Single price → "€ 20.00 + d.d.p."
	 * Range → "Da € 25.00 a € 35.00 +d.d.p."
	 */
	public function renderDetailPrezzo( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail ) {
			return '';
		}
		$min = $this->effectivePrezzo( $detail->prezzoDa, $detail->prevenditaDa );
		$max = $this->effectivePrezzo( $detail->prezzoA, $detail->prevenditaA );
		if ( null === $min && null === $max ) {
			return '';
		}
		if ( null === $max || $min === $max ) {
			$price = $min;
			return '€ ' . esc_html( $this->formatPrezzo( (string) $price ) ) . ' + d.d.p.';
		}
		return 'Da € ' . esc_html( $this->formatPrezzo( (string) $min ) ) . ' a € ' . esc_html( $this->formatPrezzo( (string) $max ) ) . ' +d.d.p.';
	}

	/**
	 * Subtract the prevendita fee from the prezzo to get the face value.
	 *
	 * @param string|null $prezzo     Full price (importo).
	 * @param string|null $prevendita Presale fee included in the prezzo.
	 * @return float|null Null when the prezzo itself is null.
	 */
	private function effectivePrezzo( ?string $prezzo, ?string $prevendita ): ?float {
		if ( null === $prezzo ) {
			return null;
		}
		$val = (float) $prezzo;
		if ( null !== $prevendita ) {
			$val -= (float) $prevendita;
		}
		return $val;
	}

	/** [cinebot_titolo_locale] — render the venue name(s). */
	public function renderDetailLocale( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->localeNomi ) {
			return '';
		}
		return esc_html( $detail->localeNomi );
	}

	/** [cinebot_titolo_descrizione] — render the description (rich text). */
	public function renderDetailDescrizione( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title || null === $detail->title->descrizione ) {
			return '';
		}
		return wp_kses_post( $detail->title->descrizione );
	}

	/**
	 * [cinebot_titolo_immagine] — render the poster image.
	 *
	 * Optional attributes: class (CSS class), alt (alt text).
	 */
	public function renderDetailImmagine( $attributes = array() ): string {
		if ( ! is_array( $attributes ) ) {
			$attributes = array();
		}
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail || null === $detail->title || null === $detail->title->locandinaUrl ) {
			return '';
		}
		$class = isset( $attributes['class'] ) ? sanitize_text_field( $attributes['class'] ) : 'cinebot-immagine';
		$alt   = isset( $attributes['alt'] ) ? sanitize_text_field( $attributes['alt'] ) : $detail->title->titolo;
		return '<img src="' . esc_url( $detail->title->locandinaUrl ) . '" alt="' . esc_attr( $alt ) . '" class="' . esc_attr( $class ) . '" />';
	}

	/**
	 * [cinebot_titolo_eventi] — render the events table.
	 *
	 * Four columns: Giorno, Ora, Locale, Link di acquisto (target=_blank).
	 */
	public function renderDetailEventi( $attributes = array() ): string {
		$detail = $this->getDetail( $this->resolveDetailId( $attributes ) );
		if ( null === $detail ) {
			return '';
		}

		$righe = array();
		foreach ( $detail->eventi as $riga ) {
			$righe[] = array(
				'giorno' => $this->formatGiorno( substr( $riga->inizio, 0, 10 ) ),
				'ora'    => gmdate( 'H:i', strtotime( $riga->inizio ) ),
				'locale' => $riga->localeNome,
				'url'    => $riga->urlAcquisto,
			);
		}

		$this->enqueueFrontendAssets();

		return $this->renderer->render( 'dettaglio-eventi', array(
			'righe' => $righe,
		) );
	}

	/**
	 * Normalize and sanitize shortcode attributes.
	 */
	private function normalizeAttributes( array $attributes ): array {
		$defaults = array(
			'tipo'         => '',
			'exclude_tipo' => '',
			'locale'       => 0,
			'from'         => current_time( 'Y-m-d', true ),
			'to'           => '',
			'limit'        => 50,
			'orderby'      => 'inizio',
			'order'        => 'ASC',
			'show_filters' => true,
			'show_desc'    => false,
			'layout'       => 'cards',
			'offset'        => 0,
			'more_url'      => '',
			'detail_url'    => '',
			'detail_page_id' => 0,
			'more_label'    => __( 'Vedi altro', 'cinebot-wp' ),
			'pagination'   => 'ajax',
			'per_page'     => 0,
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

		$atts['exclude_tipo']  = sanitize_text_field( $atts['exclude_tipo'] );
		$atts['more_url']      = '' !== trim( $atts['more_url'] ) ? sanitize_text_field( trim( $atts['more_url'] ) ) : '';
		$atts['detail_url']    = '' !== trim( $atts['detail_url'] ) ? sanitize_text_field( $atts['detail_url'] ) : '';
		$atts['detail_page_id'] = absint( $atts['detail_page_id'] );
		$atts['more_label']    = sanitize_text_field( $atts['more_label'] );

		if ( ! in_array( $atts['pagination'], array( 'ajax', 'numbered' ), true ) ) {
			$atts['pagination'] = 'ajax';
		}
		$atts['per_page'] = (int) $atts['per_page'];
		if ( $atts['per_page'] <= 0 ) {
			$atts['per_page'] = $atts['limit'];
		}
		$atts['per_page'] = max( 1, min( 100, $atts['per_page'] ) );

		return $atts;
	}

	/**
	 * Resolve a relative URL through home_url(), honoring index.php permalink structures.
	 *
	 * Absolute URLs (http://, https://) are used as-is.
	 *
	 * @param string $url Raw URL from shortcode attributes.
	 * @return string Resolved URL.
	 */
	private function resolveRelativeUrl( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Absolute URLs are used as-is.
		if ( 0 === strpos( $url, 'http' ) ) {
			return esc_url_raw( $url );
		}

		// Relative path: resolve through home_url() and honor index.php permalink structures.
		$path      = '/' . ltrim( $url, '/' );
		$structure = (string) get_option( 'permalink_structure', '' );
		if ( 0 === strpos( $structure, '/index.php/' ) ) {
			$path = '/index.php' . $path;
		}

		return home_url( $path );
	}

	/**
	 * Resolve the final detail URL from attributes.
	 *
	 * When detail_page_id is set, get_permalink() produces the correct URL
	 * respecting the WordPress permalink structure (including index.php).
	 * For relative detail_url paths, home_url() is used so the WP subdirectory
	 * is included; when the permalink structure contains /index.php/, it is
	 * prepended so "almost pretty" permalinks work correctly.
	 * Absolute URLs (http://, https://) are used as-is.
	 *
	 * @param array $atts Normalized shortcode attributes.
	 * @return string Resolved URL, or empty string when neither is set.
	 */
	private function resolveDetailUrl( array $atts ): string {
		if ( ! empty( $atts['detail_page_id'] ) ) {
			$permalink = get_permalink( $atts['detail_page_id'] );
			return is_string( $permalink ) && '' !== $permalink ? $permalink : '';
		}

		return '' !== $atts['detail_url'] ? $this->resolveRelativeUrl( $atts['detail_url'] ) : '';
	}

	/** Enqueue frontend CSS/JS with localized AJAX data. */
	private function enqueueFrontendAssets(): void {
		$css_file = CINEBOT_WP_PATH . 'assets/css/cinebot-frontend.css';
		$js_file  = CINEBOT_WP_PATH . 'assets/js/cinebot-frontend.js';
		$css_ver  = file_exists( $css_file ) ? (string) filemtime( $css_file ) : CINEBOT_WP_VERSION;
		$js_ver   = file_exists( $js_file ) ? (string) filemtime( $js_file ) : CINEBOT_WP_VERSION;

		wp_enqueue_style(
			'cinebot-frontend',
			plugins_url( 'assets/css/cinebot-frontend.css', CINEBOT_WP_FILE ),
			array(),
			$css_ver
		);

		wp_enqueue_script(
			'cinebot-frontend',
			plugins_url( 'assets/js/cinebot-frontend.js', CINEBOT_WP_FILE ),
			array(),
			$js_ver,
			true
		);

		wp_localize_script(
			'cinebot-frontend',
			'cinebotWpFrontend',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cinebot_frontend' ),
			)
		);
	}

	/**
	 * Return a version string based on template file modification times.
	 *
	 * When templates change, the transient cache key changes too,
	 * so stale cached HTML is never served after a template edit.
	 */
	private function templateVersion(): string {
		$files = array(
			CINEBOT_WP_PATH . 'templates/programmazione-cards.php',
			CINEBOT_WP_PATH . 'templates/titolo-card.php',
			CINEBOT_WP_PATH . 'templates/titolo-card-list.php',
			CINEBOT_WP_PATH . 'templates/dettaglio-titolo.php',
		);
		$ver = '';
		foreach ( $files as $f ) {
			if ( file_exists( $f ) ) {
				$ver .= (string) filemtime( $f );
			}
		}
		return $ver;
	}
}
