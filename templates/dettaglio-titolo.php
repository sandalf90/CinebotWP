<?php
/**
 * Template: Single title detail page.
 *
 * @package CinebotWp
 */

use CinebotWp\Models\Titolo;

/** @var Titolo $title */
/** @var array $events */
?>
<div class="cinebot-titolo-dettaglio">
	<?php if ( $title->locandinaUrl ) : ?>
		<div class="cinebot-dettaglio-locandina">
			<img src="<?php echo esc_url( $title->locandinaUrl ); ?>" alt="<?php echo esc_attr( $title->titolo ); ?>" />
		</div>
	<?php endif; ?>
	<h1><?php echo esc_html( $title->titolo ); ?></h1>
	<?php if ( $title->autore ) : ?>
		<p><strong><?php esc_html_e( 'Autore:', 'cinebot-wp' ); ?></strong> <?php echo esc_html( $title->autore ); ?></p>
	<?php endif; ?>
	<?php if ( $title->esecutore ) : ?>
		<p><strong><?php esc_html_e( 'Esecutore:', 'cinebot-wp' ); ?></strong> <?php echo esc_html( $title->esecutore ); ?></p>
	<?php endif; ?>
	<?php if ( $title->durata ) : ?>
		<p><strong><?php esc_html_e( 'Durata:', 'cinebot-wp' ); ?></strong> <?php echo esc_html( (string) $title->durata ); ?> min</p>
	<?php endif; ?>
	<?php if ( $title->descrizione ) : ?>
		<div class="cinebot-dettaglio-desc"><?php echo wp_kses_post( $title->descrizione ); ?></div>
	<?php endif; ?>
</div>
