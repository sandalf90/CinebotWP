<?php
/**
 * Template: Card list for AJAX filter/load-more responses.
 *
 * Delegates each card to the shared titolo-card partial so that AJAX
 * responses use the exact same markup as the initial server render.
 *
 * @package CinebotWp
 */

use CinebotWp\ReadModels\ProgrammazioneCard;

/** @var array<ProgrammazioneCard> $cards */
/** @var bool $show_desc */
/** @var string $detail_url */
/** @var \CinebotWp\Frontend\TemplateRenderer $this */
?>
<?php if ( empty( $cards ) ) : ?>
	<p class="cinebot-no-results"><?php esc_html_e( 'Nessuna programmazione trovata.', 'cinebot-wp' ); ?></p>
<?php else : ?>
	<?php foreach ( $cards as $card ) : ?>
		<?php
		// phpcs:ignore WordPress.Security.EscapeOutput -- template output is escaped inside.
		echo $this->render( 'titolo-card', array(
			'card'       => $card,
			'show_desc'  => $show_desc,
			'detail_url' => $detail_url,
		) );
		?>
	<?php endforeach; ?>
<?php endif; ?>
