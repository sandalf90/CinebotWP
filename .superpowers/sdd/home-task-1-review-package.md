## Commit List
ddeea2b feat: filter cinebot schedules by excluded event type
aae509c fix: coerce shortcode attrs and extract template context
0bbf0a7 fix: install schema in ShortcodeHandlerTest setup

## Diff Stat
 includes/Frontend/ShortcodeHandler.php     | 17 +++++++++++++----
 includes/Frontend/TemplateRenderer.php     |  3 +++
 includes/Repositories/TitoloRepository.php |  3 +++
 tests/Integration/ShortcodeHandlerTest.php | 26 ++++++++++++++++++++++++++
 4 files changed, 45 insertions(+), 4 deletions(-)

## Full Diff
diff --git a/includes/Frontend/ShortcodeHandler.php b/includes/Frontend/ShortcodeHandler.php
index 7110d8d..9a5e620 100644
--- a/includes/Frontend/ShortcodeHandler.php
+++ b/includes/Frontend/ShortcodeHandler.php
@@ -80,24 +80,27 @@ final class ShortcodeHandler {
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
 
 		$cache_key = 'cinebot_prog_' . md5( wp_json_encode( $atts ) );
 		$cached = get_transient( $cache_key );
 		if ( false !== $cached ) {
 			return $cached;
 		}
 
 		$cards = $this->titles->findPublicSchedule( $atts );
 		$total = $this->titles->countPublicSchedule( $atts );
@@ -113,24 +116,27 @@ final class ShortcodeHandler {
 
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
 
@@ -139,20 +145,21 @@ final class ShortcodeHandler {
 			'events'  => null !== $this->events ? $this->events->findByTitoloId( $id ) : array(),
 		) );
 	}
 
 	/**
 	 * Normalize and sanitize shortcode attributes.
 	 */
 	private function normalizeAttributes( array $attributes ): array {
 		$defaults = array(
 			'tipo'         => '',
+			'exclude_tipo'  => '',
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
@@ -168,20 +175,22 @@ final class ShortcodeHandler {
 		if ( ! in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true ) ) {
 			$atts['order'] = 'ASC';
 		}
 		if ( ! in_array( $atts['orderby'], array( 'inizio', 'titolo' ), true ) ) {
 			$atts['orderby'] = 'inizio';
 		}
 
 		$atts['show_filters'] = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
 		$atts['show_desc']    = filter_var( $atts['show_desc'], FILTER_VALIDATE_BOOLEAN );
 
+		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
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
index 03608c3..3bacad7 100644
--- a/includes/Frontend/TemplateRenderer.php
+++ b/includes/Frontend/TemplateRenderer.php
@@ -22,20 +22,23 @@ final class TemplateRenderer {
 	 */
 	public function render( string $template, array $context ): string {
 		$path = $this->resolve( $template );
 		if ( null === $path ) {
 			return '';
 		}
 
 		ob_start();
 		try {
 			// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted plugin template.
+			if ( ! empty( $context ) ) {
+				extract( $context, EXTR_SKIP );
+			}
 			require $path;
 			return (string) ob_get_clean();
 		} catch ( \Throwable $e ) {
 			ob_end_clean();
 			throw $e;
 		}
 	}
 
 	/**
 	 * Resolve template path: theme override first, then plugin.
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
diff --git a/tests/Integration/ShortcodeHandlerTest.php b/tests/Integration/ShortcodeHandlerTest.php
index 236c030..5d83faf 100644
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
