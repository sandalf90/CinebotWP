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

$giorni_it = array( 'Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato' );
?>
<?php if ( empty( $cards ) ) : ?>
	<p class="cinebot-no-results"><?php esc_html_e( 'Nessuna programmazione trovata.', 'cinebot-wp' ); ?></p>
<?php else : ?>
	<?php foreach ( $cards as $card ) : ?>
		<?php
		$card_link = '';
		if ( ! empty( $detail_url ) ) {
			$settings = get_option( 'cinebot_wp_settings', array() );
			$slug     = isset( $settings['detail_slug'] ) && is_string( $settings['detail_slug'] ) ? trim( $settings['detail_slug'] ) : '';

			if ( '' !== $slug ) {
				$path      = '/' . $slug . '/' . $card->titoloId . '/' . sanitize_title( $card->titolo ) . '/';
				$structure = (string) get_option( 'permalink_structure', '' );
				if ( 0 === strpos( $structure, '/index.php/' ) ) {
					$path = '/index.php' . $path;
				}
				$card_link = esc_url( home_url( $path ) );
			} else {
				$sep       = false !== strpos( $detail_url, '?' ) ? '&' : '?';
				$card_link = esc_url( $detail_url . $sep . 'titolo_id=' . $card->titoloId );
			}
		}

		$ts_inizio  = strtotime( $card->inizio );
		$data_fmt   = $giorni_it[ (int) gmdate( 'w', $ts_inizio ) ] . ' ' . gmdate( 'd/m', $ts_inizio ) . ' ' . gmdate( 'H:i', $ts_inizio );

		$eff_da = null !== $card->prezzoDa ? (float) $card->prezzoDa - (float) ( $card->prevenditaDa ?? 0 ) : null;
		$eff_a  = null !== $card->prezzoA ? (float) $card->prezzoA - (float) ( $card->prevenditaA ?? 0 ) : null;
		$prezzo_out = '';
		if ( null !== $eff_da || null !== $eff_a ) {
			if ( null === $eff_da || null === $eff_a || $eff_da === $eff_a ) {
				$single     = null !== $eff_da ? $eff_da : $eff_a;
				$prezzo_out = '€ ' . esc_html( number_format( (float) $single, 2, '.', ',' ) ) . ' + d.d.p.';
			} else {
				$prezzo_out = 'Da € ' . esc_html( number_format( (float) $eff_da, 2, '.', ',' ) ) . ' a € ' . esc_html( number_format( (float) $eff_a, 2, '.', ',' ) ) . ' +d.d.p.';
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
						<span class="cinebot-card-data"><?php echo esc_html( $data_fmt ); ?></span>
						<span class="cinebot-card-locale"><?php echo esc_html( $card->localeNome ); ?> — <?php echo esc_html( $card->comune ?? '' ); ?></span>
						<?php if ( $card->tipoDescrizione ) : ?>
							<span class="cinebot-card-tipo"><?php echo esc_html( $card->tipoDescrizione ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( '' !== $prezzo_out ) : ?>
						<p class="cinebot-card-prezzo"><?php echo $prezzo_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped above. ?></p>
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
