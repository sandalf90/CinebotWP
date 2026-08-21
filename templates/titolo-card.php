<?php
/**
 * Template: Single title card.
 *
 * @package CinebotWp
 */

use CinebotWp\ReadModels\ProgrammazioneCard;

/** @var ProgrammazioneCard $card */
/** @var bool $show_desc */
/** @var string $detail_url */
?>
<?php
$card_link = '';
if ( ! empty( $detail_url ) ) {
	$settings = get_option( 'cinebot_wp_settings', array() );
	$slug     = isset( $settings['detail_slug'] ) && is_string( $settings['detail_slug'] ) ? trim( $settings['detail_slug'] ) : '';

	if ( '' !== $slug ) {
		$card_link = esc_url( home_url( '/' . $slug . '/' . $card->titoloId . '/' . sanitize_title( $card->titolo ) . '/' ) );
	} else {
		$sep       = false !== strpos( $detail_url, '?' ) ? '&' : '?';
		$card_link = esc_url( $detail_url . $sep . 'titolo_id=' . $card->titoloId );
	}
}
?>
<article class="cinebot-card" data-event-id="<?php echo esc_attr( (string) $card->eventoId ); ?>">
	<?php if ( $card_link ) : ?>
		<a href="<?php echo $card_link; ?>" class="cinebot-card-link">
	<?php endif; ?>
		<?php if ( $card->locandinaUrl ) : ?>
			<div class="cinebot-card-locandina">
				<img src="<?php echo esc_url( $card->locandinaUrl ); ?>" alt="<?php echo esc_attr( $card->titolo ); ?>" loading="lazy" />
			</div>
		<?php endif; ?>
		<div class="cinebot-card-body">
			<h3 class="cinebot-card-title"><?php echo esc_html( $card->titolo ); ?></h3>
			<p class="cinebot-card-meta">
				<span class="cinebot-card-data"><?php echo esc_html( $card->inizio ); ?></span>
				<span class="cinebot-card-locale"><?php echo esc_html( $card->localeNome ); ?> — <?php echo esc_html( $card->comune ?? '' ); ?></span>
				<?php if ( $card->tipoDescrizione ) : ?>
					<span class="cinebot-card-tipo"><?php echo esc_html( $card->tipoDescrizione ); ?></span>
				<?php endif; ?>
			</p>
		<?php if ( $card->prezzoDa || $card->prezzoA ) : ?>
			<?php
			$eff_da = null !== $card->prezzoDa ? (float) $card->prezzoDa - (float) ( $card->prevenditaDa ?? 0 ) : null;
			$eff_a  = null !== $card->prezzoA ? (float) $card->prezzoA - (float) ( $card->prevenditaA ?? 0 ) : null;
			?>
			<p class="cinebot-card-prezzo">
				<?php
				if ( $eff_da === $eff_a ) {
					echo '€' . esc_html( number_format( (float) ( $eff_da ?? $eff_a ), 2, '.', ',' ) );
				} else {
					echo '€' . esc_html( number_format( (float) $eff_da, 2, '.', ',' ) ) . ' - €' . esc_html( number_format( (float) $eff_a, 2, '.', ',' ) );
				}
				?>
			</p>
		<?php endif; ?>
			<?php if ( $show_desc && $card->descrizione ) : ?>
				<p class="cinebot-card-desc"><?php echo esc_html( wp_trim_words( $card->descrizione, 30 ) ); ?></p>
			<?php endif; ?>
		</div>
	<?php if ( $card_link ) : ?>
		</a>
	<?php endif; ?>
</article>
