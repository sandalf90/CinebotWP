<?php
/**
 * Event-type edit admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Models\TipologiaEvento;
use CinebotWp\Repositories\TipologiaRepository;

/**
 * Renders the event-type create/edit form.
 */
final class TipologiaEditPage {
	/** @var TipologiaRepository */
	private $types;

	/** @var string */
	private $capability;

	/** @param TipologiaRepository $types */
	public function __construct( TipologiaRepository $types ) {
		$this->types      = $types;
		$this->capability = (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}

	/** Render the edit form. */
	public function render(): void {
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$type = $id > 0 ? $this->types->find( $id ) : new TipologiaEvento();

		if ( null === $type ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Tipologia not found.', 'cinebot-wp' ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php $id > 0 ? esc_html_e( 'Edit tipologia', 'cinebot-wp' ) : esc_html_e( 'New tipologia', 'cinebot-wp' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cinebot_wp_save_tipologia', 'cinebot_wp_tipologia_nonce' ); ?>
				<input type="hidden" name="action" value="cinebot_wp_save_tipologia" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="codice"><?php esc_html_e( 'Codice', 'cinebot-wp' ); ?> *</label></th>
						<td><input type="text" id="codice" name="codice" value="<?php echo esc_attr( $type->codice ); ?>" class="regular-text" <?php echo $type->predefinito ? 'readonly' : ''; ?> required /></td></tr>
					<tr><th scope="row"><label for="descrizione"><?php esc_html_e( 'Descrizione', 'cinebot-wp' ); ?> *</label></th>
						<td><input type="text" id="descrizione" name="descrizione" value="<?php echo esc_attr( $type->descrizione ); ?>" class="regular-text" required /></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Attivo', 'cinebot-wp' ); ?></th>
						<td><label><input type="checkbox" name="attivo" value="1" <?php checked( $type->attivo ); ?> /> <?php esc_html_e( 'Active', 'cinebot-wp' ); ?></label></td></tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'cinebot-wp' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Save the submitted event type. */
	public function save(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}
		check_admin_referer( 'cinebot_wp_save_tipologia', 'cinebot_wp_tipologia_nonce' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = isset( $post['id'] ) ? absint( $post['id'] ) : 0;

		$type               = $id > 0 ? $this->types->find( $id ) : new TipologiaEvento();
		$type->codice       = sanitize_text_field( (string) ( $post['codice'] ?? '' ) );
		$type->descrizione  = sanitize_text_field( (string) ( $post['descrizione'] ?? '' ) );
		$type->attivo       = isset( $post['attivo'] ) ? 1 : 0;

		if ( '' === $type->codice || '' === $type->descrizione ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-tipologia-edit&error=1' ) );
			exit;
		}

		$this->types->save( $type );
		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-tipologie' ) );
		exit;
	}
}
