<?php
/**
 * Venue list admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\TitoloRepository;

/**
 * Renders the venue management list table.
 */
final class LocaliListPage {
	/** @var LocaleRepository */
	private $venues;

	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;

	/**
	 * Store repository collaborators.
	 */
	public function __construct( LocaleRepository $venues, TitoloRepository $titles, EventoRepository $events ) {
		$this->venues = $venues;
		$this->titles = $titles;
		$this->events = $events;
	}

	/** Render the list page. */
	public function render(): void {
		$this->maybe_handle_bulk();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$table = new class( $this->venues, $this->events ) extends \WP_List_Table {
			/** @var LocaleRepository */
			private $venues;
			/** @var EventoRepository */
			private $events;

			public function __construct( LocaleRepository $venues, EventoRepository $events ) {
				parent::__construct( array(
					'singular' => 'locale',
					'plural'   => 'locali',
					'screen'   => 'cinebot-wp-locali',
				) );
				$this->venues = $venues;
				$this->events = $events;
			}

			public function get_columns() {
				return array(
					'cb'      => '<input type="checkbox" />',
					'nome'    => __( 'Nome', 'cinebot-wp' ),
					'codice'  => __( 'Codice', 'cinebot-wp' ),
					'comune'  => __( 'Comune', 'cinebot-wp' ),
					'provincia' => __( 'Provincia', 'cinebot-wp' ),
					'eventi'  => __( 'Eventi', 'cinebot-wp' ),
				);
			}

			public function get_sortable_columns() {
				return array(
					'nome'   => array( 'nome', true ),
					'comune' => array( 'comune', false ),
				);
			}

			/** @param object $item */
			public function column_cb( $item ) {
				return sprintf( '<input type="checkbox" name="locale[]" value="%d" />', (int) $item->id );
			}

			/** @param object $item */
			public function column_nome( $item ) {
				$edit_url = admin_url( 'admin.php?page=cinebot-wp-locale-edit&id=' . (int) $item->id );
				return sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html( $item->nome ) );
			}

			/** @param object $item */
			public function column_default( $item, $column_name ) {
				switch ( $column_name ) {
				case 'codice':
					return esc_html( $item->codice ?? '' );
				case 'comune':
					return esc_html( $item->comune ?? '' );
				case 'provincia':
					return esc_html( $item->provincia ?? '' );
				case 'eventi':
					return (string) $this->events->countByLocaleId( (int) $item->id );
					default:
						return '';
				}
			}

			public function get_bulk_actions() {
				return array( 'delete' => __( 'Delete', 'cinebot-wp' ) );
			}

			public function prepare_items() {
				$per_page = 50;
				$filters  = array();
				$comune   = isset( $_REQUEST['comune'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['comune'] ) ) : '';
				if ( '' !== $comune ) {
					$filters['comune'] = $comune;
				}
				$provincia = isset( $_REQUEST['provincia'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['provincia'] ) ) : '';
				if ( '' !== $provincia ) {
					$filters['provincia'] = $provincia;
				}
				$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
				if ( '' !== $search ) {
					$filters['search'] = $search;
				}

				$total = $this->venues->count( $filters );
				$page  = $this->get_pagenum();
				$this->items = $this->venues->search( $filters, $page, $per_page );

				$this->set_pagination_args( array(
					'total_items' => $total,
					'per_page'    => $per_page,
					'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
				) );
			}
		};

		$table->prepare_items();
		$new_url = admin_url( 'admin.php?page=cinebot-wp-locale-edit' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Locali', 'cinebot-wp' ); ?> <a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Nuovo locale', 'cinebot-wp' ); ?></a></h1>
			<form method="get">
				<input type="hidden" name="page" value="cinebot-wp-locali" />
				<input type="text" name="comune" placeholder="<?php esc_attr_e( 'Comune', 'cinebot-wp' ); ?>" value="<?php echo esc_attr( $comune ?? '' ); ?>" />
				<input type="text" name="provincia" placeholder="<?php esc_attr_e( 'Provincia', 'cinebot-wp' ); ?>" value="<?php echo esc_attr( $provincia ?? '' ); ?>" />
				<?php $table->search_box( __( 'Search', 'cinebot-wp' ), 'locale' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle bulk delete action with nonce and referential integrity.
	 */
	private function maybe_handle_bulk(): void {
		if ( ! isset( $_REQUEST['action'] ) || 'delete' !== $_REQUEST['action'] ) {
			return;
		}

		if ( ! current_user_can( (string) apply_filters( 'cinebot_wp_capability', 'manage_options' ) ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}

		check_admin_referer( 'bulk-locali' );

		$ids = isset( $_REQUEST['locale'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['locale'] ) ) : array();
		$deleted = 0;

		foreach ( $ids as $id ) {
			if ( $id > 0 && 0 === $this->events->countByLocaleId( $id ) ) {
				if ( $this->venues->delete( $id ) ) {
					$deleted++;
				}
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-locali' ) );
		exit;
	}
}
