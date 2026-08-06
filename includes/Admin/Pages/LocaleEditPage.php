<?php
/**
 * Locale edit admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Models\Locale;
use CinebotWp\Repositories\LocaleRepository;

/**
 * Renders the venue create/edit form and handles save/delete.
 */
final class LocaleEditPage {
	/** @var LocaleRepository */
	private $venues;

	/** @var string */
	private $capability;

	/**
	 * Store collaborators.
	 */
	public function __construct( LocaleRepository $venues ) {
		$this->venues    = $venues;
		$this->capability = (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}

	/** Render the venue edit form. */
	public function render(): void {
		$id    = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$locale = $id > 0 ? $this->venues->find( $id ) : new Locale();

		if ( null === $locale ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Locale not found.', 'cinebot-wp' ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php $id > 0 ? esc_html_e( 'Edit locale', 'cinebot-wp' ) : esc_html_e( 'New locale', 'cinebot-wp' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cinebot_wp_save_locale', 'cinebot_wp_locale_nonce' ); ?>
				<input type="hidden" name="action" value="cinebot_wp_save_locale" />
				<input type="hidden" name="locale_id" value="<?php echo esc_attr( (string) $id ); ?>" />
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="nome"><?php esc_html_e( 'Nome', 'cinebot-wp' ); ?> *</label></th>
						<td><input type="text" id="nome" name="nome" value="<?php echo esc_attr( $locale->nome ); ?>" class="regular-text" required /></td></tr>
					<tr><th scope="row"><label for="codice"><?php esc_html_e( 'Codice', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" id="codice" name="codice" value="<?php echo esc_attr( $locale->codice ?? '' ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="indirizzo"><?php esc_html_e( 'Indirizzo', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" id="indirizzo" name="indirizzo" value="<?php echo esc_attr( $locale->indirizzo ?? '' ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="cap"><?php esc_html_e( 'CAP', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" id="cap" name="cap" value="<?php echo esc_attr( $locale->cap ?? '' ); ?>" /></td></tr>
					<tr><th scope="row"><label for="comune"><?php esc_html_e( 'Comune', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" id="comune" name="comune" value="<?php echo esc_attr( $locale->comune ?? '' ); ?>" class="regular-text" /></td></tr>
					<tr><th scope="row"><label for="provincia"><?php esc_html_e( 'Provincia', 'cinebot-wp' ); ?></label></th>
						<td><input type="text" id="provincia" name="provincia" value="<?php echo esc_attr( $locale->provincia ?? '' ); ?>" /></td></tr>
					<tr><th scope="row"><label for="mappa"><?php esc_html_e( 'Mappa', 'cinebot-wp' ); ?></label></th>
						<td><input type="number" id="mappa" name="mappa" value="<?php echo esc_attr( (string) ( $locale->mappa ?? '' ) ); ?>" /></td></tr>
				</table>
				<p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save', 'cinebot-wp' ); ?></button></p>
			</form>
		</div>
		<?php
	}

	/** Save the submitted venue. */
	public function save(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}
		check_admin_referer( 'cinebot_wp_save_locale', 'cinebot_wp_locale_nonce' );

		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$id   = isset( $post['locale_id'] ) ? absint( $post['locale_id'] ) : 0;

		$locale = $id > 0 ? $this->venues->find( $id ) : new Locale();
		if ( null === $locale ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-locali' ) );
			exit;
		}

		$locale->nome      = sanitize_text_field( (string) ( $post['nome'] ?? '' ) );
		$locale->codice     = sanitize_text_field( (string) ( $post['codice'] ?? '' ) );
		$locale->indirizzo  = sanitize_text_field( (string) ( $post['indirizzo'] ?? '' ) );
		$locale->cap        = sanitize_text_field( (string) ( $post['cap'] ?? '' ) );
		$locale->comune     = sanitize_text_field( (string) ( $post['comune'] ?? '' ) );
		$locale->provincia  = sanitize_text_field( (string) ( $post['provincia'] ?? '' ) );
		$locale->mappa      = isset( $post['mappa'] ) && '' !== $post['mappa'] ? (int) $post['mappa'] : null;
		if ( 0 === $id ) {
			$locale->source = 'manual';
		}

		if ( '' === $locale->nome ) {
			wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-locale-edit&error=1' ) );
			exit;
		}

		$this->venues->save( $locale );
		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-locali' ) );
		exit;
	}
}
