## Commit List
988d392 feat: add vedi altro link to cinebot schedule shortcode

## Diff Stat
 includes/Frontend/ShortcodeHandler.php     |  6 +++++-
 templates/programmazione-cards.php         |  6 +++++-
 tests/Integration/ShortcodeHandlerTest.php | 30 ++++++++++++++++++++++++++++++
 3 files changed, 40 insertions(+), 2 deletions(-)

## Full Diff
diff --git a/includes/Frontend/ShortcodeHandler.php b/includes/Frontend/ShortcodeHandler.php
index 9a5e620..49181e3 100644
--- a/includes/Frontend/ShortcodeHandler.php
+++ b/includes/Frontend/ShortcodeHandler.php
@@ -145,51 +145,55 @@ final class ShortcodeHandler {
 			'events'  => null !== $this->events ? $this->events->findByTitoloId( $id ) : array(),
 		) );
 	}
 
 	/**
 	 * Normalize and sanitize shortcode attributes.
 	 */
 	private function normalizeAttributes( array $attributes ): array {
 		$defaults = array(
 			'tipo'         => '',
-			'exclude_tipo'  => '',
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
 
 		$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
+		$atts['more_url']     = '' !== trim( $atts['more_url'] ) ? esc_url_raw( $atts['more_url'] ) : '';
+		$atts['more_label']   = sanitize_text_field( $atts['more_label'] );
 
 		return $atts;
 	}
 
 	/** Enqueue frontend CSS/JS with localized AJAX data. */
 	private function enqueueFrontendAssets(): void {
 		wp_enqueue_style(
 			'cinebot-frontend',
 			plugins_url( 'assets/css/cinebot-frontend.css', CINEBOT_WP_FILE ),
 			array(),
diff --git a/templates/programmazione-cards.php b/templates/programmazione-cards.php
index 359c485..cbe5066 100644
--- a/templates/programmazione-cards.php
+++ b/templates/programmazione-cards.php
@@ -39,15 +39,19 @@ use CinebotWp\ReadModels\ProgrammazioneCard;
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
+		<?php elseif ( empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
 			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
 		<?php endif; ?>
 	<?php endif; ?>
 </div>
diff --git a/tests/Integration/ShortcodeHandlerTest.php b/tests/Integration/ShortcodeHandlerTest.php
index 5d83faf..b57e1ab 100644
--- a/tests/Integration/ShortcodeHandlerTest.php
+++ b/tests/Integration/ShortcodeHandlerTest.php
@@ -220,20 +220,50 @@ final class ShortcodeHandlerTest extends WP_UnitTestCase {
 
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
 	/**
 	 * Seed an active event with a title, venue, and event.
 	 *
 	 * @return int Title ID.
 	 */
 	private function seed_active_event( string $type_code = '45', string $title_name = 'Test Show Title' ): int {
 		global $wpdb;
 
 		// Ensure venue exists.
 		$wpdb->insert(
