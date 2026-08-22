<?php
/**
 * Template: Single title detail page.
 *
 * @package CinebotWp
 */

use CinebotWp\ReadModels\TitoloDetail;

/** @var TitoloDetail $detail */

$giorni_it = array( 'Domenica', 'Lunedì', 'Martedì', 'Mercoledì', 'Giovedì', 'Venerdì', 'Sabato' );

/**
 * Format a Y-m-d date as Italian "GiornoNome gg/mm".
 */
$format_giorno = static function ( string $date ) use ( $giorni_it ): string {
	$ts   = strtotime( $date );
	$nome = $giorni_it[ (int) gmdate( 'w', $ts ) ];
	return $nome . ' ' . gmdate( 'd/m', $ts );
};

/**
 * Format a decimal string as "20.00" (two decimals, dot separator).
 */
$format_prezzo = static function ( ?string $value ): string {
	return number_format( (float) $value, 2, '.', ',' );
};

/**
 * Subtract the prevendita fee from the prezzo to get the face value.
 */
$effective_prezzo = static function ( ?string $prezzo, ?string $prevendita ): ?float {
	if ( null === $prezzo ) {
		return null;
	}
	$val = (float) $prezzo;
	if ( null !== $prevendita ) {
		$val -= (float) $prevendita;
	}
	return $val;
};

$title = $detail->title;

// --- Giorno (replicates [cinebot_titolo_giorno]) ---
$giorno_out = '';
if ( null !== $detail->primoGiorno ) {
	if ( $detail->giorniCount <= 1 ) {
		$giorno_out = $format_giorno( $detail->primoGiorno );
	} elseif ( null !== $detail->ultimoGiorno ) {
		$giorno_out = 'Da ' . $format_giorno( $detail->primoGiorno ) . ' a ' . $format_giorno( $detail->ultimoGiorno );
	} else {
		$giorno_out = $format_giorno( $detail->primoGiorno );
	}
}

// --- Durata (replicates [cinebot_titolo_durata]) ---
$durata_out = '';
if ( $detail->eventiCount <= 1 && ! empty( $detail->eventi ) ) {
	$riga     = $detail->eventi[0];
	$start_ts = strtotime( $riga->inizio );
	$start    = gmdate( 'H:i', $start_ts );
	$durata   = $title->durata ?? 0;
	$end      = gmdate( 'H:i', $start_ts + ( $durata * 60 ) );
	$durata_out = 'dalle ' . $start . ' alle ' . $end;
} elseif ( $detail->eventiCount > 1 && $title->durata ) {
	$durata_out = (string) $title->durata . ' minuti';
}

// --- Prezzo (replicates [cinebot_titolo_prezzo]) ---
$prezzo_out = '';
$min = $effective_prezzo( $detail->prezzoDa, $detail->prevenditaDa );
$max = $effective_prezzo( $detail->prezzoA, $detail->prevenditaA );
if ( null !== $min || null !== $max ) {
	if ( null === $max || $min === $max ) {
		$price     = null !== $min ? $min : $max;
		$prezzo_out = '€ ' . $format_prezzo( (string) $price ) . ' + d.d.p.';
	} else {
		$prezzo_out = 'Da € ' . $format_prezzo( (string) $min ) . ' a € ' . $format_prezzo( (string) $max ) . ' +d.d.p.';
	}
}

// --- Locale (replicates [cinebot_titolo_locale]) ---
$locale_out = $detail->localeNomi ?? '';
?>
<div class="cinebot-dettaglio">
	<?php if ( $title->locandinaUrl ) : ?>
	<div class="cinebot-dettaglio-col-sx">
		<img src="<?php echo esc_url( $title->locandinaUrl ); ?>" alt="<?php echo esc_attr( $title->titolo ); ?>" class="cinebot-dettaglio-locandina" />
	</div>
	<?php endif; ?>

	<div class="cinebot-dettaglio-col-dx">
		<h1 class="cinebot-dettaglio-titolo"><?php echo esc_html( $title->titolo ); ?></h1>

		<?php if ( $title->autore ) : ?>
		<p class="cinebot-dettaglio-autore"><?php echo esc_html( $title->autore ); ?></p>
		<?php endif; ?>

		<ul class="cinebot-dettaglio-meta">
			<?php if ( '' !== $giorno_out ) : ?>
			<li>
				<svg class="cinebot-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2" /><line x1="3" y1="10" x2="21" y2="10" /><line x1="8" y1="2" x2="8" y2="6" /><line x1="16" y1="2" x2="16" y2="6" /></svg>
				<span><?php echo esc_html( $giorno_out ); ?></span>
			</li>
			<?php endif; ?>
			<?php if ( '' !== $durata_out ) : ?>
			<li>
				<svg class="cinebot-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="12" cy="12" r="10" /><polyline points="12 6 12 12 16 14" /></svg>
				<span><?php echo esc_html( $durata_out ); ?></span>
			</li>
			<?php endif; ?>
			<?php if ( '' !== $prezzo_out ) : ?>
			<li>
				<svg class="cinebot-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M3 8h18l-2 8H5z" /><path d="M3 8V6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2" /></svg>
				<span><?php echo esc_html( $prezzo_out ); ?></span>
			</li>
			<?php endif; ?>
			<?php if ( '' !== $locale_out ) : ?>
			<li>
				<svg class="cinebot-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z" /><circle cx="12" cy="9" r="2.5" /></svg>
				<span><?php echo esc_html( $locale_out ); ?></span>
			</li>
			<?php endif; ?>
		</ul>

		<?php if ( $title->descrizione ) : ?>
		<div class="cinebot-dettaglio-desc"><?php echo wp_kses_post( $title->descrizione ); ?></div>
		<?php endif; ?>

		<?php if ( ! empty( $detail->eventi ) ) : ?>
		<table class="cinebot-eventi-tabella">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Locale', 'cinebot-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Data', 'cinebot-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Ora', 'cinebot-wp' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Acquista', 'cinebot-wp' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $detail->eventi as $riga ) : ?>
					<?php
					$ev_ts  = strtotime( $riga->inizio );
					$ev_day = substr( $riga->inizio, 0, 10 );
					?>
					<tr>
						<td><?php echo esc_html( $riga->localeNome ); ?></td>
						<td><?php echo esc_html( $format_giorno( $ev_day ) ); ?></td>
						<td><?php echo esc_html( gmdate( 'H:i', $ev_ts ) ); ?></td>
						<td>
							<?php if ( $riga->urlAcquisto ) : ?>
								<a href="<?php echo esc_url( $riga->urlAcquisto ); ?>" target="_blank" rel="noopener noreferrer" class="cinebot-biglietto-link"><?php esc_html_e( 'Acquista', 'cinebot-wp' ); ?></a>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
</div>
