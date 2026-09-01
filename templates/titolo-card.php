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
?>
<?php
$giorni_it = array( 'Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato' );
$formatGiorno = function ( string $date ) use ( $giorni_it ): string {
	$ts = strtotime( $date );
	return $giorni_it[ (int) gmdate( 'w', $ts ) ] . ' ' . gmdate( 'd/m', $ts );
};

$ts_inizio = strtotime( $card->inizio );
$data_fmt = '';
if ( $card->giorniCount <= 1 ) {
	$data_fmt = $formatGiorno( substr( $card->inizio, 0, 10 ) );
} else {
	$data_fmt = 'Da ' . $formatGiorno( substr( $card->inizio, 0, 10 ) ) . ' a ' . $formatGiorno( substr( (string) $card->ultimoGiorno, 0, 10 ) );
}

?>
<article class="cinebot-card" data-event-id="<?php echo esc_attr( (string) $card->eventoId ); ?>">
	<?php if ( $card_link ) : ?>
		<a href="<?php echo esc_url( $card_link ); ?>" class="cinebot-card-link">
	<?php endif; ?>
		<?php if ( $card->locandinaUrl ) : ?>
			<div class="cinebot-card-locandina">
				<img src="<?php echo esc_url( $card->locandinaUrl ); ?>" alt="<?php echo esc_attr( $card->titolo ); ?>" loading="lazy" />
				<div class="cinebot-card-badge">
					<?php if ( $card->giorniCount <= 1 ) : ?>
						<span class="cinebot-badge-day"><?php echo esc_html( date_i18n( 'd', $ts_inizio ) ); ?></span>
						<span class="cinebot-badge-month"><?php echo esc_html( date_i18n( 'M', $ts_inizio ) ); ?></span>
					<?php else : ?>
						<span class="cinebot-badge-range">
							<?php echo esc_html( date_i18n( 'd M', $ts_inizio ) . ' - ' . date_i18n( 'd M', strtotime( (string) $card->ultimoGiorno ) ) ); ?>
						</span>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>
		<div class="cinebot-card-body">
			<h3 class="cinebot-card-title"><?php echo esc_html( $card->titolo ); ?></h3>
			<div class="cinebot-card-meta">
				<div class="cinebot-meta-row">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
					<span class="cinebot-card-data"><?php echo esc_html( $data_fmt ); ?></span>
				</div>
				<div class="cinebot-meta-row">
					<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path><circle cx="12" cy="10" r="3"></circle></svg>
					<span class="cinebot-card-locale"><?php echo esc_html( $card->localeNome ); ?></span>
				</div>
				<?php if ( $card->tipoDescrizione ) : ?>
					<div class="cinebot-meta-row">
						<span class="cinebot-card-tipo"><?php echo esc_html( $card->tipoDescrizione ); ?></span>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $show_desc && $card->descrizione ) : ?>
				<p class="cinebot-card-desc"><?php echo esc_html( wp_trim_words( $card->descrizione, 30 ) ); ?></p>
			<?php endif; ?>
		</div>
	<?php if ( $card_link ) : ?>
		</a>
	<?php endif; ?>
</article>
