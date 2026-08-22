<?php
/**
 * Event-type list admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Repositories\TipologiaRepository;

/**
 * Renders the event-type management list table.
 */
final class TipologieListPage {
	/** @var TipologiaRepository */
	private $types;

	/** @var string */
	private $capability;

	/**
	 * Store collaborators.
	 */
	public function __construct( TipologiaRepository $types ) {
		$this->types      = $types;
		$this->capability = (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}

	/** Render the list page. */
	public function render(): void {
		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$repo       = $this->types;
		$cap        = $this->capability;
		$toggle_url = admin_url( 'admin-post.php' );

		$table = new class( $repo, $toggle_url ) extends \WP_List_Table {
			/** @var TipologiaRepository */
			private $types;
			/** @var string */
			private $toggle_url;

			public function __construct( TipologiaRepository $types, string $toggle_url ) {
				parent::__construct( array(
					'singular' => 'tipologia',
					'plural'   => 'tipologie',
					'screen'   => 'cinebot-wp-tipologie',
				) );
				$this->types      = $types;
				$this->toggle_url  = $toggle_url;
			}

			public function get_columns() {
				return array(
					'codice'       => __( 'Codice', 'cinebot-wp' ),
					'descrizione'  => __( 'Descrizione', 'cinebot-wp' ),
					'predefinito'  => __( 'Predefinito', 'cinebot-wp' ),
					'attivo'       => __( 'Attivo', 'cinebot-wp' ),
				);
			}

			/** @param object $item */
			public function column_codice( $item ) {
				$edit_url = admin_url( 'admin.php?page=cinebot-wp-tipologia-edit&id=' . (int) $item->id );
				return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item->codice ) );
			}

			/** @param object $item */
			public function column_descrizione( $item ) {
				return esc_html( $item->descrizione );
			}

			/** @param object $item */
			public function column_predefinito( $item ) {
				return $item->predefinito ? '<span class="badge">' . esc_html__( 'Predefinito', 'cinebot-wp' ) . '</span>' : '&mdash;';
			}

			/** @param object $item */
			public function column_attivo( $item ) {
				$toggle_url = wp_nonce_url(
					add_query_arg( array(
						'action'  => 'cinebot_toggle_tipologia',
						'id'      => (int) $item->id,
						'attivo'  => $item->attivo ? 0 : 1,
					), $this->toggle_url ),
					'cinebot_toggle_tipologia_' . (int) $item->id
				);
				$label = $item->attivo ? __( 'Active', 'cinebot-wp' ) : __( 'Inactive', 'cinebot-wp' );
				return sprintf( '<a href="%s">%s</a>', esc_url( $toggle_url ), esc_html( $label ) );
			}

			public function prepare_items() {
				$active_only = isset( $_REQUEST['filter'] ) && 'active' === $_REQUEST['filter'];
				$this->items = $this->types->findAll( $active_only );
			}
		};

		$table->prepare_items();
		$new_url = admin_url( 'admin.php?page=cinebot-wp-tipologia-edit' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Tipologie evento', 'cinebot-wp' ); ?> <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Nuova tipologia', 'cinebot-wp' ); ?></a></h1>
			<form method="get">
				<input type="hidden" name="page" value="cinebot-wp-tipologie" />
				<select name="filter">
					<option value=""><?php esc_html_e( 'All', 'cinebot-wp' ); ?></option>
					<option value="active" <?php selected( $_REQUEST['filter'] ?? '', 'active' ); ?>><?php esc_html_e( 'Active only', 'cinebot-wp' ); ?></option>
				</select>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Handle the active/inactive toggle. */
	public function toggleActive(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}

		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$attivo = isset( $_GET['attivo'] ) ? (bool) absint( $_GET['attivo'] ) : true;

		check_admin_referer( 'cinebot_toggle_tipologia_' . $id );

		if ( $id > 0 ) {
			$this->types->setActive( $id, $attivo );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-tipologie' ) );
		exit;
	}
}
