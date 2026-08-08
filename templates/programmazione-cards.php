<?php
/**
 * Template: Programmazione cards layout.
 *
 * @package CinebotWp
 */

use CinebotWp\ReadModels\ProgrammazioneCard;

/** @var array<ProgrammazioneCard> $cards */
/** @var int $total */
/** @var array $atts */
/** @var int $instance */
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
				<input type="date" name="from" value="<?php echo esc_attr( $atts['from'] ); ?>" />
			</label>
			<label>
				<?php esc_html_e( 'Comune:', 'cinebot-wp' ); ?>
				<input type="text" name="comune" value="<?php echo esc_attr( $atts['comune'] ); ?>" />
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
					'card'      => $card,
					'show_desc' => $atts['show_desc'],
				);
				// phpcs:ignore WordPress.Security.EscapeOutput -- template output is escaped inside.
				echo $this->render( 'titolo-card', $card_args );
				?>
			<?php endforeach; ?>
		</div>
		<?php if ( count( $cards ) < $total ) : ?>
			<button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>"><?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?></button>
		<?php endif; ?>
	<?php endif; ?>
</div>
