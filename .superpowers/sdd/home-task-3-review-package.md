## Commit List
5f720fd feat: add numbered pagination to cinebot schedule shortcode

## Diff Stat
 includes/Frontend/ShortcodeHandler.php     | 41 ++++++++++++++++++++++---
 templates/programmazione-cards.php         | 18 ++++++++++-
 tests/Integration/ShortcodeHandlerTest.php | 49 ++++++++++++++++++++++++++++++
 3 files changed, 103 insertions(+), 5 deletions(-)

## Full Diff
diff --git a/includes/Frontend/ShortcodeHandler.php b/includes/Frontend/ShortcodeHandler.php
index 49181e3..0b3ecc1 100644
--- a/includes/Frontend/ShortcodeHandler.php
+++ b/includes/Frontend/ShortcodeHandler.php
@@ -89,34 +89,56 @@ final class ShortcodeHandler {
 	 *
 	 * @param array|string $attributes Shortcode attributes (empty string when no attrs).
 	 * @return string HTML output.
 	 */
 	public function renderProgrammazione( $attributes = array() ): string {
 		if ( ! is_array( $attributes ) ) {
 			$attributes = array();
 		}
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
 
@@ -159,20 +181,22 @@ final class ShortcodeHandler {
 			'to'           => '',
 			'limit'        => 50,
 			'orderby'      => 'inizio',
 			'order'        => 'ASC',
 			'show_filters' => true,
 			'show_desc'    => false,
 			'layout'       => 'cards',
 			'offset'       => 0,
 			'more_url'     => '',
 			'more_label'   => __( 'Vedi altro', 'cinebot-wp' ),
+			'pagination'   => 'ajax',
+			'per_page'     => 0,
 		);
 
 		$atts = shortcode_atts( $defaults, $attributes, 'cinebot_programmazione' );
 
 		$atts['limit']  = max( 1, min( 100, (int) $atts['limit'] ) );
 		$atts['offset'] = max( 0, (int) $atts['offset'] );
 		$atts['locale'] = absint( $atts['locale'] );
 
 		if ( ! in_array( strtoupper( $atts['order'] ), array( 'ASC', 'DESC' ), true ) ) {
 			$atts['order'] = 'ASC';
@@ -181,20 +205,29 @@ final class ShortcodeHandler {
 			$atts['orderby'] = 'inizio';
 		}
 
 		$atts['show_filters'] = filter_var( $atts['show_filters'], FILTER_VALIDATE_BOOLEAN );
 		$atts['show_desc']    = filter_var( $atts['show_desc'], FILTER_VALIDATE_BOOLEAN );
 
 		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
 		$atts['more_url']     = '' !== trim( $atts['more_url'] ) ? esc_url_raw( $atts['more_url'] ) : '';
 		$atts['more_label']   = sanitize_text_field( $atts['more_label'] );
 
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
diff --git a/templates/programmazione-cards.php b/templates/programmazione-cards.php
index cbe5066..3a7b85b 100644
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
@@ -43,15 +46,28 @@ use CinebotWp\ReadModels\ProgrammazioneCard;
 				);
 				// phpcs:ignore WordPress.Security.EscapeOutput -- template output is escaped inside.
 				echo $this->render( 'titolo-card', $card_args );
 				?>
 			<?php endforeach; ?>
 		</div>
 		<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) >= (int) $atts['limit'] ) : ?>
 			<a class="cinebot-vedi-altro" href="<?php echo esc_url( $atts['more_url'] ); ?>">
 				<?php echo esc_html( $atts['more_label'] ); ?>
 			</a>
-		<?php elseif ( empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
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
index b57e1ab..da4eef5 100644
--- a/tests/Integration/ShortcodeHandlerTest.php
+++ b/tests/Integration/ShortcodeHandlerTest.php
@@ -250,20 +250,69 @@ final class ShortcodeHandlerTest extends WP_UnitTestCase {
 
 	/** more_label overrides the default button text. */
 	public function test_more_label_overrides_default(): void {
 		$this->seed_active_event( '01', 'Cinema Show' );
 
 		$html = do_shortcode( '[cinebot_programmazione tipo="01" limit="1" more_url="/x" more_label="Tutti i film"]' );
 
 		self::assertStringContainsString( 'Tutti i film', $html );
 	}
 
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
