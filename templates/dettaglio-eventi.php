<?php
/**
 * Template: Events table for the detail page.
 *
 * @package CinebotWp
 */

/** @var array<int,array{giorno:string,ora:string,locale:string,url:?string}> $righe */
?>
<?php if ( empty( $righe ) ) : ?>
	<p class="cinebot-no-results"><?php esc_html_e( 'Nessun evento disponibile.', 'cinebot-wp' ); ?></p>
<?php else : ?>
	<table class="cinebot-eventi-tabella">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Giorno', 'cinebot-wp' ); ?></th>
				<th><?php esc_html_e( 'Ora', 'cinebot-wp' ); ?></th>
				<th><?php esc_html_e( 'Locale', 'cinebot-wp' ); ?></th>
				<th><?php esc_html_e( 'Biglietti', 'cinebot-wp' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $righe as $riga ) : ?>
				<tr>
					<td><?php echo esc_html( $riga['giorno'] ); ?></td>
					<td><?php echo esc_html( $riga['ora'] ); ?></td>
					<td><?php echo esc_html( $riga['locale'] ); ?></td>
					<td>
						<?php if ( ! empty( $riga['url'] ) ) : ?>
							<a href="<?php echo esc_url( $riga['url'] ); ?>" target="_blank" rel="noopener noreferrer" class="cinebot-biglietto-link">
								<?php esc_html_e( 'Acquista', 'cinebot-wp' ); ?>
							</a>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
<?php endif; ?>
