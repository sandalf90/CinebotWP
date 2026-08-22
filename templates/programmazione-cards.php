<?php
/**
 * Template: Programmazione cards layout.
 *
 * @package CinebotWp
 */

use CinebotWp\Models\Locale;
use CinebotWp\ReadModels\ProgrammazioneCard;

/** @var array<ProgrammazioneCard> $cards */
/** @var int $total */
/** @var array $atts */
/** @var string $detail_url */
/** @var int $instance */
/** @var \CinebotWp\Frontend\TemplateRenderer $this */
/** @var int $current_page */
/** @var int $total_pages */
/** @var string $base_url */
/** @var array<int,Locale> $locali */
?>
<div class="cinebot-programmazione" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-detail-url="<?php echo esc_attr( $detail_url ); ?>">
	<?php if ( $atts['show_filters'] ) : ?>
		<form class="cinebot-filters" id="cinebot-filters-<?php echo esc_attr( (string) $instance ); ?>">
			<label>
				<?php esc_html_e( 'Da:', 'cinebot-wp' ); ?>
				<input type="date" name="from" value="<?php echo esc_attr( $atts['from'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'A:', 'cinebot-wp' ); ?>
				<input type="date" name="to" value="<?php echo esc_attr( $atts['to'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Tipo:', 'cinebot-wp' ); ?>
				<input type="text" name="tipo" value="<?php echo esc_attr( $atts['tipo'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Comune:', 'cinebot-wp' ); ?>
				<input type="text" name="comune" value="<?php echo esc_attr( $atts['comune'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Locale:', 'cinebot-wp' ); ?>
				<select name="locale">
					<option value="0"><?php esc_html_e( 'Tutti', 'cinebot-wp' ); ?></option>
					<?php foreach ( $locali as $loc ) : ?>
						<option value="<?php echo esc_attr( (string) $loc->id ); ?>" <?php selected( (int) $atts['locale'], (int) $loc->id ); ?>>
							<?php echo esc_html( $loc->nome ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</label>
			<button type="submit"><?php esc_html_e( 'Filtra', 'cinebot-wp' ); ?></button>
		</form>
	<?php endif; ?>

	<?php if ( empty( $cards ) ) : ?>
		<p class="cinebot-no-results"><?php esc_html_e( 'Nessuna programmazione trovata.', 'cinebot-wp' ); ?></p>
	<?php else : ?>
		<div class="cinebot-cards">
			<?php foreach ( $cards as $card ) : ?>
				<?php
				$card_args = array(
					'card'       => $card,
					'show_desc'  => $atts['show_desc'],
					'detail_url' => $detail_url,
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
		<?php elseif ( 'numbered' === $atts['pagination'] && $total_pages > 1 ) : ?>
			<nav class="cinebot-pagination" aria-label="<?php esc_attr_e( 'Navigazione pagine', 'cinebot-wp' ); ?>">
				<?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
					<?php
					$sep = false !== strpos( $base_url, '?' ) ? '&' : '?';
					$page_url = $base_url . $sep . 'cinebot_page=' . $i;
					$is_current = $i === $current_page;
					?>
					<a href="<?php echo esc_url( $page_url ); ?>" <?php echo $is_current ? 'aria-current="page" class="cinebot-page-current"' : ''; ?>>
						<?php echo esc_html( (string) $i ); ?>
					</a>
				<?php endfor; ?>
			</nav>
		<?php elseif ( 'ajax' === $atts['pagination'] && count( $cards ) < $total ) : ?>
			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
		<?php endif; ?>
	<?php endif; ?>
</div>
