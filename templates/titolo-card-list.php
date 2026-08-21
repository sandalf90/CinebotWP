<?php
/**
 * Template: Card list for AJAX filter/load-more responses.
 *
 * @package CinebotWp
 */

use CinebotWp\ReadModels\ProgrammazioneCard;

/** @var array<ProgrammazioneCard> $cards */
/** @var bool $show_desc */
/** @var string $detail_url */
?>
<?php if ( empty( $cards ) ) : ?>
	<p class="cinebot-no-results"><?php esc_html_e( 'Nessuna programmazione trovata.', 'cinebot-wp' ); ?></p>
<?php else : ?>
	<?php foreach ( $cards as $card ) : ?>
		<?php
		$card_link = '';
		if ( ! empty( $detail_url ) ) {
			$sep       = false !== strpos( $detail_url, '?' ) ? '&' : '?';
			$card_link = esc_url( $detail_url . $sep . 'titolo_id=' . $card->titoloId );
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
						<p class="cinebot-card-prezzo">
							<?php
							if ( $card->prezzoDa === $card->prezzoA ) {
								echo '€' . esc_html( $card->prezzoDa );
							} else {
								echo '€' . esc_html( $card->prezzoDa ) . ' - €' . esc_html( $card->prezzoA );
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
	<?php endforeach; ?>
<?php endif; ?>
