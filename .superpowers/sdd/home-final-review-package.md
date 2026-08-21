## Commit List
5f720fd feat: add numbered pagination to cinebot schedule shortcode
988d392 feat: add vedi altro link to cinebot schedule shortcode
91c6920 fix: correct phpcs ignore scope in TemplateRenderer
ddeea2b feat: filter cinebot schedules by excluded event type
aae509c fix: coerce shortcode attrs and extract template context
0bbf0a7 fix: install schema in ShortcodeHandlerTest setup

## Diff Stat
 includes/Frontend/ShortcodeHandler.php     |  62 ++++++++++++++---
 includes/Frontend/TemplateRenderer.php     |   4 +-
 includes/Repositories/TitoloRepository.php |   3 +
 templates/programmazione-cards.php         |  22 +++++-
 tests/Integration/ShortcodeHandlerTest.php | 105 +++++++++++++++++++++++++++++
 5 files changed, 186 insertions(+), 10 deletions(-)

## Full Diff
diff --git a/includes/Frontend/ShortcodeHandler.php b/includes/Frontend/ShortcodeHandler.php
index 7110d8d..0b3ecc1 100644
--- a/includes/Frontend/ShortcodeHandler.php
+++ b/includes/Frontend/ShortcodeHandler.php
@@ -80,57 +80,85 @@ final class ShortcodeHandler {
 		wp_send_json_success( array(
 			'html'     => $html,
 			'total'    => $total,
 			'has_more' => ( $atts['offset'] + count( $cards ) ) < $total,
 		) );
 	}
 
 	/**
 	 * Render the programmazione shortcode.
 	 *
-	 * @param array $attributes Shortcode attributes.
+	 * @param array|string $attributes Shortcode attributes (empty string when no attrs).
 	 * @return string HTML output.
 	 */
-	public function renderProgrammazione( array $attributes = array() ): string {
+	public function renderProgrammazione( $attributes = array() ): string {
+		if ( ! is_array( $attributes ) ) {
+			$attributes = array();
+		}
 		$atts = $this->normalizeAttributes( $attributes );
 
+		$current_page = 1;
+		$total_pages  = 0;
+		$base_url     = '';
+
+		if ( 'numbered' === $atts['pagination'] ) {
+			$atts['limit'] = $atts['per_page'];
+			if ( empty( $atts['more_url'] ) ) {
+				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination, no mutation.
+				$current_page = isset( $_GET['cinebot_page'] ) ? max( 1, absint( wp_unslash( $_GET['cinebot_page'] ) ) ) : 1;
+				$atts['offset']       = ( $current_page - 1 ) * $atts['per_page'];
+				$atts['current_page'] = $current_page;
+			}
+		}
+
 		$cache_key = 'cinebot_prog_' . md5( wp_json_encode( $atts ) );
 		$cached = get_transient( $cache_key );
 		if ( false !== $cached ) {
 			return $cached;
 		}
 
 		$cards = $this->titles->findPublicSchedule( $atts );
 		$total = $this->titles->countPublicSchedule( $atts );
 
+		if ( 'numbered' === $atts['pagination'] && empty( $atts['more_url'] ) ) {
+			$total_pages = max( 1, (int) ceil( $total / $atts['per_page'] ) );
+			$base_url    = esc_url_raw( remove_query_arg( 'cinebot_page' ) );
+		}
+
 		$html = $this->renderer->render( 'programmazione-cards', array(
-			'cards'     => $cards,
-			'total'     => $total,
-			'atts'      => $atts,
-			'instance'  => ++self::$instance_id,
+			'cards'        => $cards,
+			'total'        => $total,
+			'atts'         => $atts,
+			'instance'     => ++self::$instance_id,
+			'current_page' => $current_page,
+			'total_pages'  => $total_pages,
+			'base_url'     => $base_url,
 		) );
 
 		$this->enqueueFrontendAssets();
 
 		$ttl = (int) apply_filters( 'cinebot_wp_cache_ttl', 900 );
 		set_transient( $cache_key, $html, $ttl );
 
 		return $html;
 	}
 
 	/**
 	 * Render the single title detail shortcode.
 	 *
-	 * @param array $attributes Shortcode attributes.
+	 * @param array|string $attributes Shortcode attributes (empty string when no attrs).
 	 * @return string HTML output.
 	 */
-	public function renderTitolo( array $attributes = array() ): string {
+	public function renderTitolo( $attributes = array() ): string {
+		if ( ! is_array( $attributes ) ) {
+			$attributes = array();
+		}
 		$id = isset( $attributes['id'] ) ? absint( $attributes['id'] ) : 0;
 		if ( $id <= 0 ) {
 			return '';
 		}
 
 		$title = $this->titles->find( $id );
 		if ( null === $title ) {
 			return '';
 		}
 
@@ -139,49 +167,67 @@ final class ShortcodeHandler {
 			'events'  => null !== $this->events ? $this->events->findByTitoloId( $id ) : array(),
 		) );
 	}
 
 	/**
 	 * Normalize and sanitize shortcode attributes.
 	 */
 	private function normalizeAttributes( array $attributes ): array {
 		$defaults = array(
 			'tipo'         => '',
+			'exclude_tipo' => '',
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
+			'more_url'     => '',
+			'more_label'   => __( 'Vedi altro', 'cinebot-wp' ),
+			'pagination'   => 'ajax',
+			'per_page'     => 0,
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
 
+		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
+		$atts['more_url']     = '' !== trim( $atts['more_url'] ) ? esc_url_raw( $atts['more_url'] ) : '';
+		$atts['more_label']   = sanitize_text_field( $atts['more_label'] );
+
+		if ( ! in_array( $atts['pagination'], array( 'ajax', 'numbered' ), true ) ) {
+			$atts['pagination'] = 'ajax';
+		}
+		$atts['per_page'] = (int) $atts['per_page'];
+		if ( $atts['per_page'] <= 0 ) {
+			$atts['per_page'] = $atts['limit'];
+		}
+		$atts['per_page'] = max( 1, min( 100, $atts['per_page'] ) );
+
 		return $atts;
 	}
 
 	/** Enqueue frontend CSS/JS with localized AJAX data. */
 	private function enqueueFrontendAssets(): void {
 		wp_enqueue_style(
 			'cinebot-frontend',
 			plugins_url( 'assets/css/cinebot-frontend.css', CINEBOT_WP_FILE ),
 			array(),
 			CINEBOT_WP_VERSION
diff --git a/includes/Frontend/TemplateRenderer.php b/includes/Frontend/TemplateRenderer.php
index 03608c3..1d1a7e9 100644
--- a/includes/Frontend/TemplateRenderer.php
+++ b/includes/Frontend/TemplateRenderer.php
@@ -21,22 +21,24 @@ final class TemplateRenderer {
 	 * @return string Rendered HTML.
 	 */
 	public function render( string $template, array $context ): string {
 		$path = $this->resolve( $template );
 		if ( null === $path ) {
 			return '';
 		}
 
 		ob_start();
 		try {
-			// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted plugin template.
+			// phpcs:ignoreStart WordPress.Files.DirectFileAccess, WordPress.PHP.DontExtract -- trusted plugin template, mirrors WP core load_template().
+			extract( $context, EXTR_SKIP );
 			require $path;
+			// phpcs:ignoreEnd
 			return (string) ob_get_clean();
 		} catch ( \Throwable $e ) {
 			ob_end_clean();
 			throw $e;
 		}
 	}
 
 	/**
 	 * Resolve template path: theme override first, then plugin.
 	 */
diff --git a/includes/Repositories/TitoloRepository.php b/includes/Repositories/TitoloRepository.php
index 43d6af8..fae043e 100644
--- a/includes/Repositories/TitoloRepository.php
+++ b/includes/Repositories/TitoloRepository.php
@@ -286,20 +286,23 @@ final class TitoloRepository {
 		$clauses = array( 't.sync_active = %d', 'e.sync_active = %d', 'e.stato = %d', 'e.inizio >= %s' );
 		$from = isset( $filters['from'] ) && '' !== trim( (string) $filters['from'] ) ? sanitize_text_field( (string) $filters['from'] ) : current_time( 'Y-m-d', true );
 		$values = array( 1, 1, 3, $from );
 		if ( isset( $filters['to'] ) && '' !== trim( (string) $filters['to'] ) ) {
 			$clauses[] = "e.inizio < DATE_ADD(%s, INTERVAL 1 DAY)";
 			$values[] = sanitize_text_field( (string) $filters['to'] );
 		}
 		if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
 			$clauses[] = 'ty.codice = %s';
 			$values[] = sanitize_text_field( (string) $filters['tipo'] );
+		} elseif ( isset( $filters['exclude_tipo'] ) && '' !== trim( (string) $filters['exclude_tipo'] ) ) {
+			$clauses[] = 'ty.codice != %s';
+			$values[] = sanitize_text_field( (string) $filters['exclude_tipo'] );
 		}
 		$locale = isset( $filters['locale'] ) ? (int) $filters['locale'] : 0;
 		if ( $locale > 0 ) {
 			$clauses[] = 'l.id = %d';
 			$values[] = $locale;
 		}
 		if ( isset( $filters['comune'] ) && '' !== trim( (string) $filters['comune'] ) ) {
 			$clauses[] = 'l.comune = %s';
 			$values[] = sanitize_text_field( (string) $filters['comune'] );
 		}
diff --git a/templates/programmazione-cards.php b/templates/programmazione-cards.php
index 359c485..3a7b85b 100644
--- a/templates/programmazione-cards.php
+++ b/templates/programmazione-cards.php
@@ -4,20 +4,23 @@
  *
  * @package CinebotWp
  */
 
 use CinebotWp\ReadModels\ProgrammazioneCard;
 
 /** @var array<ProgrammazioneCard> $cards */
 /** @var int $total */
 /** @var array $atts */
 /** @var int $instance */
+/** @var int $current_page */
+/** @var int $total_pages */
+/** @var string $base_url */
 ?>
 <div class="cinebot-programmazione" data-instance="<?php echo esc_attr( (string) $instance ); ?>">
 	<?php if ( $atts['show_filters'] ) : ?>
 		<form class="cinebot-filters" id="cinebot-filters-<?php echo esc_attr( (string) $instance ); ?>">
 			<label>
 				<?php esc_html_e( 'Tipo:', 'cinebot-wp' ); ?>
 				<input type="text" name="tipo" value="<?php echo esc_attr( $atts['tipo'] ); ?>" placeholder="<?php esc_attr_e( 'Codice tipo', 'cinebot-wp' ); ?>" />
 			</label>
 			<label>
 				<?php esc_html_e( 'Da:', 'cinebot-wp' ); ?>
@@ -39,15 +42,32 @@ use CinebotWp\ReadModels\ProgrammazioneCard;
 				<?php
 				$card_args = array(
 					'card'      => $card,
 					'show_desc' => $atts['show_desc'],
 				);
 				// phpcs:ignore WordPress.Security.EscapeOutput -- template output is escaped inside.
 				echo $this->render( 'titolo-card', $card_args );
 				?>
 			<?php endforeach; ?>
 		</div>
-		<?php if ( count( $cards ) < $total ) : ?>
+		<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) >= (int) $atts['limit'] ) : ?>
+			<a class="cinebot-vedi-altro" href="<?php echo esc_url( $atts['more_url'] ); ?>">
+				<?php echo esc_html( $atts['more_label'] ); ?>
+			</a>
+		<?php elseif ( 'numbered' === $atts['pagination'] && $total_pages > 1 ) : ?>
+			<nav class="cinebot-pagination" aria-label="<?php esc_attr_e( 'Navigazione pagine', 'cinebot-wp' ); ?>">
+				<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
+					<?php
+					$sep = false !== strpos( $base_url, '?' ) ? '&' : '?';
+					$page_url = $base_url . $sep . 'cinebot_page=' . $i;
+					$is_current = $i === $current_page;
+					?>
+					<a href="<?php echo esc_url( $page_url ); ?>" <?php echo $is_current ? 'aria-current="page" class="cinebot-page-current"' : ''; ?>>
+						<?php echo esc_html( (string) $i ); ?>
+					</a>
+				<?php endfor; ?>
+			</nav>
+		<?php elseif ( 'ajax' === $atts['pagination'] && count( $cards ) < $total ) : ?>
 			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
 		<?php endif; ?>
 	<?php endif; ?>
 </div>
diff --git a/tests/Integration/ShortcodeHandlerTest.php b/tests/Integration/ShortcodeHandlerTest.php
index 236c030..da4eef5 100644
--- a/tests/Integration/ShortcodeHandlerTest.php
+++ b/tests/Integration/ShortcodeHandlerTest.php
@@ -1,20 +1,21 @@
 <?php
 /**
  * Shortcode handler integration tests.
  *
  * @package CinebotWp
  */
 
 namespace CinebotWp\Tests\Integration;
 
 use CinebotWp\Admin\Pages\DashboardPage;
+use CinebotWp\Database\SchemaInstaller;
 use CinebotWp\Frontend\ShortcodeHandler;
 use CinebotWp\Frontend\TemplateRenderer;
 use CinebotWp\Models\Titolo;
 use CinebotWp\Repositories\EventoRepository;
 use CinebotWp\Repositories\TitoloRepository;
 use WP_UnitTestCase;
 
 /**
  * Tests shortcode rendering, attribute normalization, caching, and template override.
  */
@@ -25,20 +26,21 @@ final class ShortcodeHandlerTest extends WP_UnitTestCase {
 	/** @var EventoRepository */
 	private $events;
 
 	/** @var ShortcodeHandler */
 	private $handler;
 
 	/** Set up repositories and handler with clean state. */
 	public function set_up(): void {
 		parent::set_up();
 		global $wpdb;
+		( new SchemaInstaller( $wpdb ) )->install();
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
@@ -95,20 +97,44 @@ final class ShortcodeHandlerTest extends WP_UnitTestCase {
 	public function test_filters_by_tipo(): void {
 		$this->seed_active_event( '45', 'Teatro Prosa Show' );
 		$this->seed_active_event( '53', 'Concert Show' );
 
 		$html = do_shortcode( '[cinebot_programmazione tipo="45"]' );
 
 		self::assertStringContainsString( 'Teatro Prosa Show', $html );
 		self::assertStringNotContainsString( 'Concert Show', $html );
 	}
 
+	/** Shortcode filters by exclude_tipo (all types except the given code). */
+	public function test_filters_by_exclude_tipo(): void {
+		$this->seed_active_event( '01', 'Cinema Show' );
+		$this->seed_active_event( '45', 'Teatro Prosa Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione exclude_tipo="01"]' );
+
+		self::assertStringNotContainsString( 'Cinema Show', $html );
+		self::assertStringContainsString( 'Teatro Prosa Show', $html );
+	}
+
+	/** tipo takes precedence over exclude_tipo when both are set. */
+	public function test_tipo_takes_precedence_over_exclude_tipo(): void {
+		$this->seed_active_event( '01', 'Cinema Show' );
+		$this->seed_active_event( '45', 'Teatro Prosa Show' );
+		$this->seed_active_event( '53', 'Concert Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" exclude_tipo="45"]' );
+
+		self::assertStringContainsString( 'Cinema Show', $html );
+		self::assertStringNotContainsString( 'Teatro Prosa Show', $html );
+		self::assertStringNotContainsString( 'Concert Show', $html );
+	}
+
 	/** Shortcode excludes inactive events. */
 	public function test_excludes_inactive_events(): void {
 		global $wpdb;
 
 		$title_id = $this->seed_active_event( '45', 'Active Show' );
 
 		// Deactivate the event.
 		$wpdb->update(
 			$wpdb->prefix . 'cinebot_eventi',
 			array( 'sync_active' => 0 ),
@@ -194,20 +220,99 @@ final class ShortcodeHandlerTest extends WP_UnitTestCase {
 
 		self::assertSame( $html1, $html2 );
 	}
 
 	/** Shortcode registers both shortcodes. */
 	public function test_registers_both_shortcodes(): void {
 		self::assertTrue( shortcode_exists( 'cinebot_programmazione' ) );
 		self::assertTrue( shortcode_exists( 'cinebot_titolo' ) );
 	}
 
+	/** more_url renders "Vedi altro" link when there are more results. */
+	public function test_renders_vedi_altro_when_more_url_set(): void {
+		$this->seed_active_event( '01', 'Cinema Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/programmazione-cinema"]' );
+
+		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
+		self::assertStringContainsString( 'href="/programmazione-cinema"', $html );
+		self::assertStringContainsString( 'Vedi altro', $html );
+		self::assertStringNotContainsString( 'cinebot-load-more', $html );
+	}
+
+	/** more_url does not render "Vedi altro" when all results are shown. */
+	public function test_no_vedi_altro_when_all_results_shown(): void {
+		$this->seed_active_event( '01', 'Cinema Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="100" more_url="/x"]' );
+
+		self::assertStringNotContainsString( 'cinebot-vedi-altro', $html );
+	}
+
+	/** more_label overrides the default button text. */
+	public function test_more_label_overrides_default(): void {
+		$this->seed_active_event( '01', 'Cinema Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/x" more_label="Tutti i film"]' );
+
+		self::assertStringContainsString( 'Tutti i film', $html );
+	}
+
+	/** Numbered pagination renders page links when total > per_page. */
+	public function test_numbered_pagination_renders_links(): void {
+		for ( $i = 0; $i < 5; $i++ ) {
+			$this->seed_active_event( '01', 'Cinema Show ' . $i );
+		}
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );
+
+		self::assertStringContainsString( 'cinebot-pagination', $html );
+		self::assertStringContainsString( 'cinebot_page=1', $html );
+		self::assertStringContainsString( 'cinebot_page=2', $html );
+		self::assertStringContainsString( 'cinebot_page=3', $html );
+		self::assertStringNotContainsString( 'cinebot-load-more', $html );
+	}
+
+	/** Numbered pagination returns correct page when cinebot_page is set. */
+	public function test_numbered_pagination_page_2_offset(): void {
+		for ( $i = 0; $i < 5; $i++ ) {
+			$this->seed_active_event( '01', 'Cinema Show ' . $i );
+		}
+
+		$_GET['cinebot_page'] = '2';
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2"]' );
+		unset( $_GET['cinebot_page'] );
+
+		self::assertStringContainsString( 'cinebot-page-current', $html );
+	}
+
+	/** Numbered pagination does not render nav when only one page. */
+	public function test_numbered_pagination_no_nav_single_page(): void {
+		$this->seed_active_event( '01', 'Single Show' );
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="20"]' );
+
+		self::assertStringNotContainsString( 'cinebot-pagination', $html );
+	}
+
+	/** more_url takes precedence over numbered pagination. */
+	public function test_more_url_takes_precedence_over_numbered(): void {
+		for ( $i = 0; $i < 5; $i++ ) {
+			$this->seed_active_event( '01', 'Cinema Show ' . $i );
+		}
+
+		$html = do_shortcode( '[cinebot_programmazione tipo="01" pagination="numbered" per_page="2" more_url="/x"]' );
+
+		self::assertStringContainsString( 'cinebot-vedi-altro', $html );
+		self::assertStringNotContainsString( 'cinebot-pagination', $html );
+	}
+
 	/**
 	 * Seed an active event with a title, venue, and event.
 	 *
 	 * @return int Title ID.
 	 */
 	private function seed_active_event( string $type_code = '45', string $title_name = 'Test Show Title' ): int {
 		global $wpdb;
 
 		// Ensure venue exists.
 		$wpdb->insert(
