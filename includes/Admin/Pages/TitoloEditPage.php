<?php
/**
 * Nested title/event/sector/price editor admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Models\Evento;
use CinebotWp\Models\Prezzo;
use CinebotWp\Models\Settore;
use CinebotWp\Models\Titolo;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use RuntimeException;
use Throwable;

/**
 * Renders the nested title editor and handles atomic hierarchy saves.
 */
final class TitoloEditPage {
	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/** @var SettoreRepository */
	private $sectors;

	/** @var PrezzoRepository */
	private $prices;

	/** @var TipologiaRepository */
	private $types;

	/** @var LocaleRepository */
	private $venues;

	/**
	 * Store the repository collaborators.
	 *
	 * @param TitoloRepository    $titles  Title persistence.
	 * @param EventoRepository    $events  Event persistence.
	 * @param SettoreRepository   $sectors Sector persistence.
	 * @param PrezzoRepository    $prices  Price persistence.
	 * @param TipologiaRepository $types   Event-type catalog.
	 * @param LocaleRepository    $venues  Venue catalog.
	 */
	public function __construct(
		TitoloRepository $titles,
		EventoRepository $events,
		SettoreRepository $sectors,
		PrezzoRepository $prices,
		TipologiaRepository $types,
		LocaleRepository $venues
	) {
		$this->titles  = $titles;
		$this->events  = $events;
		$this->sectors = $sectors;
		$this->prices  = $prices;
		$this->types   = $types;
		$this->venues  = $venues;
	}

	/**
	 * Render the editor form.
	 *
	 * @param int|null $id Title ID for editing, null for a new form.
	 */
	public function render( ?int $id = null ): void {
		if ( null === $id ) {
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		}

		$title   = new Titolo();
		$events  = array();

		if ( $id > 0 ) {
			$loaded = $this->titles->find( $id );
			if ( null === $loaded ) {
				echo '<div class="wrap"><p>' . esc_html__( 'Title not found.', 'cinebot-wp' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=cinebot-wp-programmazioni' ) ) . '">' . esc_html__( 'Back to list', 'cinebot-wp' ) . '</a></p></div>';
				return;
			}
			$title  = $loaded;
			$loaded_events = $this->events->findByTitoloId( $id );
			foreach ( $loaded_events as $event ) {
				$loaded_sectors = $this->sectors->findByEventoId( (int) $event->id );
				$sector_data    = array();
				foreach ( $loaded_sectors as $sector ) {
					$loaded_prices         = $this->prices->findBySettoreId( (int) $sector->id );
					$sector_data[] = array(
						'sector' => $sector,
						'prices' => $loaded_prices,
					);
				}
				$events[] = array(
					'event'   => $event,
					'sectors' => $sector_data,
				);
			}
		}

		$types   = $this->types->findAll( true );
		$venues  = $this->venues->search( array(), 1, 500 );
		$max_eid = $this->max_event_key( $events );
		?>
		<div class="wrap cinebot-edit-page">
			<h1><?php echo $title->id ? esc_html__( 'Edit Title', 'cinebot-wp' ) : esc_html__( 'New Title', 'cinebot-wp' ); ?></h1>
			<?php $this->render_notices(); ?>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=cinebot-wp-programmazioni' ) ); ?>">&larr; <?php esc_html_e( 'Back to Programmazioni', 'cinebot-wp' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="cinebot-titolo-edit-form">
				<?php wp_nonce_field( 'cinebot_wp_save_titolo', 'cinebot_wp_titolo_nonce' ); ?>
				<input type="hidden" name="action" value="cinebot_wp_save_titolo" />
				<input type="hidden" name="titolo_id" value="<?php echo esc_attr( (string) ( $title->id ?? 0 ) ); ?>" />

				<?php $this->render_title_fieldset( $title, $types ); ?>

				<h2><?php esc_html_e( 'Events', 'cinebot-wp' ); ?></h2>
				<div class="cinebot-events" id="cinebot-events" data-next-index="<?php echo esc_attr( (string) $max_eid ); ?>">
					<?php foreach ( $events as $entry ) : ?>
						<?php $this->render_event_fieldset( $entry['event'], $entry['sectors'], $venues ); ?>
					<?php endforeach; ?>
				</div>
				<p>
					<button type="button" class="button cinebot-add-event"><?php esc_html_e( 'Add event', 'cinebot-wp' ); ?></button>
				</p>

				<?php $this->render_templates( $types, $venues ); ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'cinebot-wp' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}

	/** Save the submitted title and its nested hierarchy atomically. */
	public function save(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}

		check_admin_referer( 'cinebot_wp_save_titolo', 'cinebot_wp_titolo_nonce' );

		$post      = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$title_id  = isset( $post['titolo_id'] ) ? absint( $post['titolo_id'] ) : 0;

		try {
			$title       = $this->build_title( $post, $title_id );
			$events_data = $this->build_events( $post );
		} catch ( RuntimeException $e ) {
			$this->redirect_error( $title_id );
			return;
		}

		$errors = $this->validate( $title, $events_data );
		if ( array() !== $errors ) {
			$this->redirect_error( $title_id );
			return;
		}

		global $wpdb;

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'START TRANSACTION' );
			$saved_id = $this->persist_hierarchy( $title, $events_data, $title_id );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'COMMIT' );
			$this->redirect_saved( $saved_id );
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'ROLLBACK' );
			$this->redirect_error( $title_id );
		}
	}

	// --------------------------------------------------------------- rendering.

	/** Render admin notices based on query-string flags. */
	private function render_notices(): void {
		if ( isset( $_GET['saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only flag.
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Title saved.', 'cinebot-wp' ) . '</p></div>';
		}
		if ( isset( $_GET['error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only flag.
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Cinebot WP could not save the title. Verify the data and try again.', 'cinebot-wp' ) . '</p></div>';
		}
	}

	/** Render the title-level fieldset. */
	private function render_title_fieldset( Titolo $title, array $types ): void {
		?>
		<fieldset class="cinebot-title-fieldset">
			<legend><?php esc_html_e( 'Title details', 'cinebot-wp' ); ?></legend>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="cinebot-titolo"><?php esc_html_e( 'Titolo', 'cinebot-wp' ); ?> <span class="description">*</span></label></th>
					<td><input type="text" id="cinebot-titolo" name="titolo" value="<?php echo esc_attr( $title->titolo ); ?>" class="regular-text" required /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-autore"><?php esc_html_e( 'Autore', 'cinebot-wp' ); ?></label></th>
					<td><input type="text" id="cinebot-autore" name="autore" value="<?php echo esc_attr( $title->autore ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-esecutore"><?php esc_html_e( 'Esecutore', 'cinebot-wp' ); ?></label></th>
					<td><input type="text" id="cinebot-esecutore" name="esecutore" value="<?php echo esc_attr( $title->esecutore ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-durata"><?php esc_html_e( 'Durata (min)', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" id="cinebot-durata" name="durata" value="<?php echo esc_attr( (string) ( $title->durata ?? '' ) ); ?>" min="0" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-tipoevento"><?php esc_html_e( 'Tipo evento', 'cinebot-wp' ); ?></label></th>
					<td>
						<select id="cinebot-tipoevento" name="tipoevento_codice">
							<option value=""><?php esc_html_e( '-- Select --', 'cinebot-wp' ); ?></option>
							<?php foreach ( $types as $type ) : ?>
								<option value="<?php echo esc_attr( $type->codice ); ?>" <?php selected( $title->tipoeventoCodice, $type->codice ); ?>>
									<?php echo esc_html( $type->codice . ' — ' . $type->descrizione ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-descrizione"><?php esc_html_e( 'Descrizione', 'cinebot-wp' ); ?></label></th>
					<td><textarea id="cinebot-descrizione" name="descrizione" rows="5" class="large-text"><?php echo esc_textarea( $title->descrizione ?? '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-locandina"><?php esc_html_e( 'Locandina URL', 'cinebot-wp' ); ?></label></th>
					<td><input type="url" id="cinebot-locandina" name="locandina_url" value="<?php echo esc_attr( $title->locandinaUrl ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-cinetel"><?php esc_html_e( 'Cinetel', 'cinebot-wp' ); ?></label></th>
					<td><input type="text" id="cinebot-cinetel" name="cinetel" value="<?php echo esc_attr( $title->cinetel ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-tmdb"><?php esc_html_e( 'TMDB', 'cinebot-wp' ); ?></label></th>
					<td><input type="text" id="cinebot-tmdb" name="tmdb" value="<?php echo esc_attr( $title->tmdb ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-trailer"><?php esc_html_e( 'Trailer URL', 'cinebot-wp' ); ?></label></th>
					<td><input type="url" id="cinebot-trailer" name="trailer" value="<?php echo esc_attr( $title->trailer ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-cast"><?php esc_html_e( 'Cast', 'cinebot-wp' ); ?></label></th>
					<td><textarea id="cinebot-cast" name="cast" rows="3" class="large-text"><?php echo esc_textarea( $title->cast ?? '' ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="cinebot-tag"><?php esc_html_e( 'Tag', 'cinebot-wp' ); ?></label></th>
					<td>
						<input type="text" id="cinebot-tag" name="tag" value="<?php echo esc_attr( implode( ', ', is_array( $title->tag ) ? $title->tag : array() ) ); ?>" class="regular-text" />
						<p class="description"><?php esc_html_e( 'Comma-separated tags.', 'cinebot-wp' ); ?></p>
					</td>
				</tr>
				<?php if ( $title->id ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Source', 'cinebot-wp' ); ?></th>
						<td><code><?php echo esc_html( $title->source ); ?></code></td>
					</tr>
				<?php endif; ?>
			</table>
		</fieldset>
		<?php
	}

	/** Render a single event fieldset with its nested sectors and prices. */
	private function render_event_fieldset( Evento $event, array $sectors, array $venues ): void {
		$key      = (string) ( $event->id ?? 0 );
		$max_sid  = $this->max_sector_key( $sectors );
		$inizio_v = $this->datetime_local_value( $event->inizio );
		?>
		<fieldset class="cinebot-event-fieldset" data-event-key="<?php echo esc_attr( $key ); ?>">
			<legend><?php esc_html_e( 'Event', 'cinebot-wp' ); ?></legend>
			<input type="hidden" name="events[<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( (string) ( $event->id ?? 0 ) ); ?>" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Inizio', 'cinebot-wp' ); ?></label></th>
					<td><input type="datetime-local" name="events[<?php echo esc_attr( $key ); ?>][inizio]" value="<?php echo esc_attr( $inizio_v ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Locale', 'cinebot-wp' ); ?></label></th>
					<td>
						<select name="events[<?php echo esc_attr( $key ); ?>][locale_id]">
							<option value=""><?php esc_html_e( '-- Select venue --', 'cinebot-wp' ); ?></option>
							<?php foreach ( $venues as $venue ) : ?>
								<option value="<?php echo esc_attr( (string) $venue->id ); ?>" <?php selected( $event->localeId, (int) $venue->id ); ?>>
									<?php echo esc_html( $venue->nome . ' — ' . ( $venue->comune ?? '' ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Organizzatore ID', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" name="events[<?php echo esc_attr( $key ); ?>][organizzatore_id]" value="<?php echo esc_attr( (string) ( $event->organizzatoreId ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Organizzatore CF', 'cinebot-wp' ); ?></label></th>
					<td><input type="text" name="events[<?php echo esc_attr( $key ); ?>][organizzatore_cf]" value="<?php echo esc_attr( $event->organizzatoreCf ?? '' ); ?>" class="regular-text" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Stato', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" name="events[<?php echo esc_attr( $key ); ?>][stato]" value="<?php echo esc_attr( (string) ( $event->stato ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'OTP', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" name="events[<?php echo esc_attr( $key ); ?>][otp]" value="<?php echo esc_attr( (string) ( $event->otp ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Controllo accessi', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" name="events[<?php echo esc_attr( $key ); ?>][controlloaccessi]" value="<?php echo esc_attr( (string) ( $event->controlloaccessi ?? '' ) ); ?>" /></td>
				</tr>
				<tr>
					<th scope="row"><label><?php esc_html_e( 'Mappa', 'cinebot-wp' ); ?></label></th>
					<td><input type="number" name="events[<?php echo esc_attr( $key ); ?>][mappa]" value="<?php echo esc_attr( (string) ( $event->mappa ?? '' ) ); ?>" /></td>
				</tr>
			</table>

			<h4><?php esc_html_e( 'Settori', 'cinebot-wp' ); ?></h4>
			<div class="cinebot-sectors" data-event-key="<?php echo esc_attr( $key ); ?>" data-next-index="<?php echo esc_attr( (string) $max_sid ); ?>">
				<?php foreach ( $sectors as $entry ) : ?>
					<?php $this->render_sector_row( $entry['sector'], $entry['prices'], $key, $venues ); ?>
				<?php endforeach; ?>
			</div>
			<p><button type="button" class="button cinebot-add-sector" data-event-key="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Add settore', 'cinebot-wp' ); ?></button></p>
			<p><button type="button" class="button cinebot-remove-event"><?php esc_html_e( 'Remove event', 'cinebot-wp' ); ?></button></p>
		</fieldset>
		<?php
	}

	/** Render a single sector row with its nested prices. */
	private function render_sector_row( Settore $sector, array $prices, string $event_key, array $venues ): void {
		$key      = (string) ( $sector->id ?? 0 );
		$max_pid  = $this->max_price_key( $prices );
		?>
		<div class="cinebot-sector-row" data-sector-key="<?php echo esc_attr( $key ); ?>">
			<input type="hidden" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( (string) ( $sector->id ?? 0 ) ); ?>" />
			<label><?php esc_html_e( 'Nome settore', 'cinebot-wp' ); ?>:
				<input type="text" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $key ); ?>][nome]" value="<?php echo esc_attr( $sector->nome ?? '' ); ?>" />
			</label>

			<table class="cinebot-prices-table" data-event-key="<?php echo esc_attr( $event_key ); ?>" data-sector-key="<?php echo esc_attr( $key ); ?>" data-next-index="<?php echo esc_attr( (string) $max_pid ); ?>">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Nome', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Tipo', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Importo', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Prevendita', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Stato', 'cinebot-wp' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $prices as $price ) : ?>
						<?php $this->render_price_row( $price, $event_key, $key ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button cinebot-add-price" data-event-key="<?php echo esc_attr( $event_key ); ?>" data-sector-key="<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Add prezzo', 'cinebot-wp' ); ?></button></p>
			<p><button type="button" class="button cinebot-remove-sector"><?php esc_html_e( 'Remove settore', 'cinebot-wp' ); ?></button></p>
		</div>
		<?php
	}

	/** Render a single price row. */
	private function render_price_row( Prezzo $price, string $event_key, string $sector_key ): void {
		$key = (string) ( $price->id ?? 0 );
		?>
		<tr class="cinebot-price-row" data-price-key="<?php echo esc_attr( $key ); ?>">
			<td>
				<input type="hidden" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][id]" value="<?php echo esc_attr( (string) ( $price->id ?? 0 ) ); ?>" />
				<input type="text" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][nome]" value="<?php echo esc_attr( $price->nome ?? '' ); ?>" />
			</td>
			<td>
				<select name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][tipo]">
					<option value="I" <?php selected( $price->tipo, 'I' ); ?>>I</option>
					<option value="R" <?php selected( $price->tipo, 'R' ); ?>>R</option>
				</select>
			</td>
			<td><input type="text" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][importo]" value="<?php echo esc_attr( $price->importo ?? '' ); ?>" size="8" /></td>
			<td><input type="text" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][prevendita]" value="<?php echo esc_attr( $price->prevendita ?? '' ); ?>" size="8" /></td>
			<td><input type="number" name="events[<?php echo esc_attr( $event_key ); ?>][sectors][<?php echo esc_attr( $sector_key ); ?>][prices][<?php echo esc_attr( $key ); ?>][stato]" value="<?php echo esc_attr( (string) ( $price->stato ?? '' ) ); ?>" size="4" /></td>
			<td><button type="button" class="button cinebot-remove-price"><?php esc_html_e( 'Remove', 'cinebot-wp' ); ?></button></td>
		</tr>
		<?php
	}

	/** Render the three clone templates for events, sectors, and prices. */
	private function render_templates( array $types, array $venues ): void {
		$type_options = '<option value="">' . esc_html__( '-- Select --', 'cinebot-wp' ) . '</option>';
		foreach ( $types as $type ) {
			$type_options .= '<option value="' . esc_attr( $type->codice ) . '">' . esc_html( $type->codice . ' — ' . $type->descrizione ) . '</option>';
		}

		$venue_options = '<option value="">' . esc_html__( '-- Select venue --', 'cinebot-wp' ) . '</option>';
		foreach ( $venues as $venue ) {
			$venue_options .= '<option value="' . esc_attr( (string) $venue->id ) . '">' . esc_html( $venue->nome . ' — ' . ( $venue->comune ?? '' ) ) . '</option>';
		}
		?>
		<template id="cinebot-event-template">
			<fieldset class="cinebot-event-fieldset" data-event-key="__INDEX__">
				<legend><?php esc_html_e( 'Event', 'cinebot-wp' ); ?></legend>
				<input type="hidden" name="events[__INDEX__][id]" value="0" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Inizio', 'cinebot-wp' ); ?></label></th>
						<td><input type="datetime-local" name="events[__INDEX__][inizio]" value="" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Locale', 'cinebot-wp' ); ?></label></th>
						<td><select name="events[__INDEX__][locale_id]"><?php echo $venue_options; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></select></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Organizzatore ID', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" name="events[__INDEX__][organizzatore_id]" value="" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Organizzatore CF', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" name="events[__INDEX__][organizzatore_cf]" value="" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Stato', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" name="events[__INDEX__][stato]" value="" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'OTP', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" name="events[__INDEX__][otp]" value="" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Controllo accessi', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" name="events[__INDEX__][controlloaccessi]" value="" /></td>
					</tr>
					<tr>
						<th scope="row"><label><?php esc_html_e( 'Mappa', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" name="events[__INDEX__][mappa]" value="" /></td>
					</tr>
				</table>
				<h4><?php esc_html_e( 'Settori', 'cinebot-wp' ); ?></h4>
				<div class="cinebot-sectors" data-event-key="__INDEX__" data-next-index="1"></div>
				<p><button type="button" class="button cinebot-add-sector" data-event-key="__INDEX__"><?php esc_html_e( 'Add settore', 'cinebot-wp' ); ?></button></p>
				<p><button type="button" class="button cinebot-remove-event"><?php esc_html_e( 'Remove event', 'cinebot-wp' ); ?></button></p>
			</fieldset>
		</template>

		<template id="cinebot-sector-template">
			<div class="cinebot-sector-row" data-sector-key="__INDEX__">
				<input type="hidden" name="events[__EVENT_INDEX__][sectors][__INDEX__][id]" value="0" />
				<label><?php esc_html_e( 'Nome settore', 'cinebot-wp' ); ?>:
					<input type="text" name="events[__EVENT_INDEX__][sectors][__INDEX__][nome]" value="" />
				</label>
				<table class="cinebot-prices-table" data-event-key="__EVENT_INDEX__" data-sector-key="__INDEX__" data-next-index="1">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Nome', 'cinebot-wp' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'cinebot-wp' ); ?></th>
							<th><?php esc_html_e( 'Importo', 'cinebot-wp' ); ?></th>
							<th><?php esc_html_e( 'Prevendita', 'cinebot-wp' ); ?></th>
							<th><?php esc_html_e( 'Stato', 'cinebot-wp' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button cinebot-add-price" data-event-key="__EVENT_INDEX__" data-sector-key="__INDEX__"><?php esc_html_e( 'Add prezzo', 'cinebot-wp' ); ?></button></p>
				<p><button type="button" class="button cinebot-remove-sector"><?php esc_html_e( 'Remove settore', 'cinebot-wp' ); ?></button></p>
			</div>
		</template>

		<template id="cinebot-price-template">
			<tr class="cinebot-price-row" data-price-key="__INDEX__">
				<td>
					<input type="hidden" name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][id]" value="0" />
					<input type="text" name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][nome]" value="" />
				</td>
				<td>
					<select name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][tipo]">
						<option value="I">I</option>
						<option value="R">R</option>
					</select>
				</td>
				<td><input type="text" name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][importo]" value="" size="8" /></td>
				<td><input type="text" name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][prevendita]" value="" size="8" /></td>
				<td><input type="number" name="events[__EVENT_INDEX__][sectors][__SECTOR_INDEX__][prices][__INDEX__][stato]" value="" size="4" /></td>
				<td><button type="button" class="button cinebot-remove-price"><?php esc_html_e( 'Remove', 'cinebot-wp' ); ?></button></td>
			</tr>
		</template>
		<?php
	}

	// ----------------------------------------------------------------- parsing.

	/**
	 * Build a Titolo DTO from POST data.
	 *
	 * @param array<string,mixed> $post     Sanitized POST.
	 * @param int                 $title_id Existing title ID or 0.
	 * @throws RuntimeException When an existing title is not found.
	 */
	private function build_title( array $post, int $title_id ): Titolo {
		if ( $title_id > 0 ) {
			$stored = $this->titles->find( $title_id );
			if ( null === $stored ) {
				throw new RuntimeException( 'Title not found.' );
			}
			$title = $stored;
		} else {
			$title           = new Titolo();
			$title->source   = 'manual';
			$title->idtitolo = null;
			$title->frontendId = null;
		}

		$title->titolo           = isset( $post['titolo'] ) ? sanitize_text_field( (string) $post['titolo'] ) : '';
		$title->autore           = $this->nullable_text( $post['autore'] ?? '' );
		$title->esecutore        = $this->nullable_text( $post['esecutore'] ?? '' );
		$title->durata           = $this->nullable_int( $post['durata'] ?? '' );
		$title->descrizione      = isset( $post['descrizione'] ) ? wp_kses_post( (string) $post['descrizione'] ) : null;
		$title->tipoeventoCodice = $this->nullable_text( $post['tipoevento_codice'] ?? '' );
		$title->locandinaUrl     = $this->nullable_url( $post['locandina_url'] ?? '' );
		$title->cinetel          = $this->nullable_text( $post['cinetel'] ?? '' );
		$title->tmdb             = $this->nullable_text( $post['tmdb'] ?? '' );
		$title->trailer          = $this->nullable_url( $post['trailer'] ?? '' );
		$title->cast             = $this->nullable_textarea( $post['cast'] ?? '' );
		$title->tag              = $this->parse_tags( $post['tag'] ?? '' );

		return $title;
	}

	/**
	 * Build the events data structure from POST.
	 *
	 * @param array<string,mixed> $post Sanitized POST.
	 * @return array<int,array{event:Evento,sectors:array<int,array{sector:Settore,prices:array<int,Prezzo>}>}>
	 */
	private function build_events( array $post ): array {
		$raw = $post['events'] ?? array();
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$result = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$event_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$event    = new Evento();
			$event->id = $event_id > 0 ? $event_id : null;
			$event->titoloId = 0;
			$event->inizio = isset( $entry['inizio'] ) ? sanitize_text_field( (string) $entry['inizio'] ) : '';
			$event->localeId = isset( $entry['locale_id'] ) ? absint( $entry['locale_id'] ) : 0;
			$event->organizzatoreId = $this->nullable_int( $entry['organizzatore_id'] ?? '' );
			$event->organizzatoreCf = $this->nullable_text( $entry['organizzatore_cf'] ?? '' );
			$event->stato = $this->nullable_int( $entry['stato'] ?? '' );
			$event->otp = $this->nullable_int( $entry['otp'] ?? '' );
			$event->controlloaccessi = $this->nullable_int( $entry['controlloaccessi'] ?? '' );
			$event->mappa = $this->nullable_int( $entry['mappa'] ?? '' );

			$result[] = array(
				'event'   => $event,
				'sectors' => $this->build_sectors( $entry['sectors'] ?? array() ),
			);
		}
		return $result;
	}

	/**
	 * Build the sectors data structure from POST.
	 *
	 * @param array<string,mixed> $raw Raw sectors array.
	 * @return array<int,array{sector:Settore,prices:array<int,Prezzo>}>
	 */
	private function build_sectors( array $raw ): array {
		$result = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$sector_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$sector    = new Settore();
			$sector->id = $sector_id > 0 ? $sector_id : null;
			$sector->eventoId = 0;
			$sector->nome = $this->nullable_text( $entry['nome'] ?? '' );

			$result[] = array(
				'sector' => $sector,
				'prices' => $this->build_prices( $entry['prices'] ?? array() ),
			);
		}
		return $result;
	}

	/**
	 * Build the prices array from POST.
	 *
	 * @param array<string,mixed> $raw Raw prices array.
	 * @return array<int,Prezzo>
	 */
	private function build_prices( array $raw ): array {
		$result = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$price_id = isset( $entry['id'] ) ? absint( $entry['id'] ) : 0;
			$price    = new Prezzo();
			$price->id = $price_id > 0 ? $price_id : null;
			$price->settoreId = 0;
			$price->nome = $this->nullable_text( $entry['nome'] ?? '' );
			$price->tipo = $this->nullable_price_tipo( $entry['tipo'] ?? '' );
			$price->importo = $this->nullable_decimal( $entry['importo'] ?? '' );
			$price->prevendita = $this->nullable_decimal( $entry['prevendita'] ?? '' );
			$price->stato = $this->nullable_int( $entry['stato'] ?? '' );

			$result[] = $price;
		}
		return $result;
	}

	// ------------------------------------------------------------- validation.

	/**
	 * Validate the title and events.
	 *
	 * @param Titolo $title       Title DTO.
	 * @param array  $events_data Events data.
	 * @return array<int,string> Error messages (empty if valid).
	 */
	private function validate( Titolo $title, array $events_data ): array {
		$errors = array();
		if ( '' === trim( $title->titolo ) ) {
			$errors[] = __( 'The title is required.', 'cinebot-wp' );
		}

		foreach ( $events_data as $i => $entry ) {
			$event = $entry['event'];
			if ( '' === trim( $event->inizio ) ) {
				$errors[] = sprintf( __( 'Event %d requires a start time.', 'cinebot-wp' ), $i + 1 );
			}
			if ( $event->localeId <= 0 ) {
				$errors[] = sprintf( __( 'Event %d requires a venue.', 'cinebot-wp' ), $i + 1 );
			}

			foreach ( $entry['sectors'] as $j => $sector_data ) {
				$sector = $sector_data['sector'];
				if ( '' === trim( (string) $sector->nome ) ) {
					$errors[] = sprintf( __( 'Event %d sector %d requires a name.', 'cinebot-wp' ), $i + 1, $j + 1 );
				}

				foreach ( $sector_data['prices'] as $k => $price ) {
					if ( null !== $price->importo && (float) $price->importo < 0 ) {
						$errors[] = sprintf( __( 'Event %d sector %d price %d has a negative amount.', 'cinebot-wp' ), $i + 1, $j + 1, $k + 1 );
					}
					if ( null !== $price->prevendita && (float) $price->prevendita < 0 ) {
						$errors[] = sprintf( __( 'Event %d sector %d price %d has a negative pre-sale fee.', 'cinebot-wp' ), $i + 1, $j + 1, $k + 1 );
					}
				}
			}
		}

		return $errors;
	}

	// -------------------------------------------------------------- persistence.

	/**
	 * Persist the full hierarchy within the caller's transaction.
	 *
	 * @param Titolo $title           Title DTO.
	 * @param array  $events_data     Events data.
	 * @param int    $original_title  Original title ID (0 for new).
	 * @return int Saved title ID.
	 * @throws RuntimeException When ownership checks fail.
	 * @throws Throwable When repository persistence fails.
	 */
	private function persist_hierarchy( Titolo $title, array $events_data, int $original_title ): int {
		$title_id = $this->titles->save( $title );

		$existing_events = array();
		if ( $original_title > 0 ) {
			foreach ( $this->events->findByTitoloId( $title_id ) as $e ) {
				$existing_events[ (int) $e->id ] = $e;
			}
		}

		$saved_event_ids = array();

		foreach ( $events_data as $entry ) {
			$event          = $entry['event'];
			$event->titoloId = $title_id;
			$event_id       = (int) ( $event->id ?? 0 );

			if ( $event_id > 0 ) {
				if ( ! isset( $existing_events[ $event_id ] ) ) {
					throw new RuntimeException( 'Event does not belong to this title.' );
				}
				$stored               = $existing_events[ $event_id ];
				$event->source        = $stored->source;
				$event->idevento      = $stored->idevento;
				$event->syncActive    = $stored->syncActive;
				$event->lastSeenSync  = $stored->lastSeenSync;
			} else {
				$event->source       = 'manual';
				$event->idevento     = null;
				$event->syncActive   = 1;
				$event->lastSeenSync = null;
			}

			$saved_event_id = $this->events->save( $event );
			$saved_event_ids[] = $saved_event_id;

			$existing_sectors = array();
			if ( $event_id > 0 ) {
				foreach ( $this->sectors->findByEventoId( $saved_event_id ) as $s ) {
					$existing_sectors[ (int) $s->id ] = $s;
				}
			}

			$saved_sector_ids = array();

			foreach ( $entry['sectors'] as $sector_entry ) {
				$sector           = $sector_entry['sector'];
				$sector->eventoId = $saved_event_id;
				$sector_id        = (int) ( $sector->id ?? 0 );

				if ( $sector_id > 0 ) {
					if ( ! isset( $existing_sectors[ $sector_id ] ) ) {
						throw new RuntimeException( 'Sector does not belong to this event.' );
					}
					$stored               = $existing_sectors[ $sector_id ];
					$sector->source       = $stored->source;
					$sector->idsettore    = $stored->idsettore;
					$sector->syncActive   = $stored->syncActive;
					$sector->lastSeenSync = $stored->lastSeenSync;
				} else {
					$sector->source       = 'manual';
					$sector->idsettore    = null;
					$sector->syncActive   = 1;
					$sector->lastSeenSync = null;
				}

				$saved_sector_id = $this->sectors->save( $sector );
				$saved_sector_ids[] = $saved_sector_id;

				$existing_prices = array();
				if ( $sector_id > 0 ) {
					foreach ( $this->prices->findBySettoreId( $saved_sector_id ) as $p ) {
						$existing_prices[ (int) $p->id ] = $p;
					}
				}

				$saved_price_ids = array();

				foreach ( $sector_entry['prices'] as $price ) {
					$price_id = (int) ( $price->id ?? 0 );

					if ( $price_id > 0 ) {
						if ( ! isset( $existing_prices[ $price_id ] ) ) {
							throw new RuntimeException( 'Price does not belong to this sector.' );
						}
						$stored               = $existing_prices[ $price_id ];
						$price->source        = $stored->source;
						$price->idprezzo      = $stored->idprezzo;
						$price->syncActive    = $stored->syncActive;
						$price->lastSeenSync  = $stored->lastSeenSync;
					} else {
						$price->source       = 'manual';
						$price->idprezzo     = null;
						$price->syncActive   = 1;
						$price->lastSeenSync = null;
					}

					$price->settoreId = $saved_sector_id;
					$saved_price_ids[] = $this->prices->save( $price );
				}

				foreach ( $existing_prices as $ep_id => $ep ) {
					if ( ! in_array( $ep_id, $saved_price_ids, true ) ) {
						$this->prices->delete( $ep_id );
					}
				}
			}

			foreach ( $existing_sectors as $es_id => $es ) {
				if ( ! in_array( $es_id, $saved_sector_ids, true ) ) {
					$this->prices->deleteBySettoreId( $es_id );
					$this->sectors->delete( $es_id );
				}
			}
		}

		foreach ( $existing_events as $ee_id => $ee ) {
			if ( ! in_array( $ee_id, $saved_event_ids, true ) ) {
				$orphan_sectors = $this->sectors->findByEventoId( $ee_id );
				foreach ( $orphan_sectors as $os ) {
					$this->prices->deleteBySettoreId( (int) $os->id );
				}
				$this->sectors->deleteByEventoId( $ee_id );
				$this->events->delete( $ee_id );
			}
		}

		return $title_id;
	}

	// ----------------------------------------------------------------- helpers.

	/** Return a trimmed non-empty string or null. */
	private function nullable_text( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$trimmed = trim( sanitize_text_field( $value ) );
		return '' === $trimmed ? null : $trimmed;
	}

	/** Return a trimmed textarea string or null. */
	private function nullable_textarea( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$trimmed = trim( sanitize_textarea_field( $value ) );
		return '' === $trimmed ? null : $trimmed;
	}

	/** Return an integer or null (empty string yields null, 0 is preserved). */
	private function nullable_int( $value ): ?int {
		if ( ! is_string( $value ) && ! is_int( $value ) ) {
			return null;
		}
		if ( is_string( $value ) && '' === trim( $value ) ) {
			return null;
		}
		return (int) $value;
	}

	/** Return an esc_url_raw URL or null. */
	private function nullable_url( $value ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$raw = esc_url_raw( trim( $value ) );
		return '' === $raw ? null : $raw;
	}

	/** Return a validated price tipo (I or R) or null. */
	private function nullable_price_tipo( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$upper = strtoupper( trim( $value ) );
		return in_array( $upper, array( 'I', 'R' ), true ) ? $upper : null;
	}

	/** Return a sanitized decimal string or null. */
	private function nullable_decimal( $value ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$trimmed = trim( $value );
		if ( '' === $trimmed ) {
			return null;
		}
		return preg_match( '/^[0-9]+(?:\.[0-9]{1,2})?$/', $trimmed ) ? $trimmed : null;
	}

	/**
	 * Parse comma-separated tags into a unique array.
	 *
	 * @param string $value Comma-separated tags.
	 * @return array<int,string>
	 */
	private function parse_tags( $value ): array {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$parts = explode( ',', $value );
		$tags  = array();
		foreach ( $parts as $part ) {
			$tag = trim( sanitize_text_field( $part ) );
			if ( '' !== $tag && ! in_array( $tag, $tags, true ) ) {
				$tags[] = $tag;
			}
		}
		return $tags;
	}

	/** Convert a MySQL datetime to a datetime-local value. */
	private function datetime_local_value( string $mysql_datetime ): string {
		if ( '' === $mysql_datetime ) {
			return '';
		}
		$ts = strtotime( $mysql_datetime );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i', $ts );
	}

	/** Return the next event key (max existing ID + 1, or 1). */
	private function max_event_key( array $events ): int {
		$max = 0;
		foreach ( $events as $entry ) {
			$id = (int) ( $entry['event']->id ?? 0 );
			if ( $id > $max ) {
				$max = $id;
			}
		}
		return $max + 1;
	}

	/** Return the next sector key (max existing ID + 1, or 1). */
	private function max_sector_key( array $sectors ): int {
		$max = 0;
		foreach ( $sectors as $entry ) {
			$id = (int) ( $entry['sector']->id ?? 0 );
			if ( $id > $max ) {
				$max = $id;
			}
		}
		return $max + 1;
	}

	/** Return the next price key (max existing ID + 1, or 1). */
	private function max_price_key( array $prices ): int {
		$max = 0;
		foreach ( $prices as $price ) {
			$id = (int) ( $price->id ?? 0 );
			if ( $id > $max ) {
				$max = $id;
			}
		}
		return $max + 1;
	}

	/** Redirect to the edit page with a success flag. */
	private function redirect_saved( int $title_id ): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'saved' => '1' ),
				admin_url( 'admin.php?page=cinebot-wp-programmazione-edit&id=' . $title_id )
			)
		);
		exit;
	}

	/** Redirect to the edit page with an error flag. */
	private function redirect_error( int $title_id ): void {
		$url = $title_id > 0
			? admin_url( 'admin.php?page=cinebot-wp-programmazione-edit&id=' . $title_id )
			: admin_url( 'admin.php?page=cinebot-wp-programmazione-edit' );

		wp_safe_redirect(
			add_query_arg( array( 'error' => '1' ), $url )
		);
		exit;
	}

	/** Return the filtered admin capability. */
	private function capability(): string {
		return (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}
}
