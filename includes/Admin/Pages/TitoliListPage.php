<?php
/**
 * Programs list table admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use CinebotWp\Models\Titolo;
use CinebotWp\Repositories\EventoRepository;

use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use RuntimeException;
use Throwable;

/**
 * Renders the programs list table and handles single and bulk deletion.
 */
final class TitoliListPage extends \WP_List_Table {
	/** @var TitoloRepository */
	private $titles;

	/** @var EventoRepository */
	private $events;


	/** @var TipologiaRepository */
	private $types;

	/** Store the repository collaborators. */
	public function __construct(
		TitoloRepository $titles,
		EventoRepository $events,
		TipologiaRepository $types
	) {
		$this->titles  = $titles;
		$this->events  = $events;
		$this->types   = $types;
	}

	/** Render the page wrapper, notices, filters, and list table. */
	public function render(): void {
		if ( ! isset( $this->_args['plural'] ) ) {
			parent::__construct(
				array(
					'plural'   => 'titoli',
					'singular' => 'titolo',
					'ajax'     => false,
					'screen'   => 'cinebot-wp-programmazioni',
				)
			);
		}
		$this->handle_actions();
		$this->prepare_items();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Programmazioni', 'cinebot-wp' ); ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=cinebot-wp-programmazione-edit' ) ); ?>" class="page-title-action"><?php esc_html_e( 'Nuovo titolo', 'cinebot-wp' ); ?></a>
			</h1>
			<?php $this->render_notices(); ?>
			<form method="get" id="cinebot-titoli-filter">
				<input type="hidden" name="page" value="cinebot-wp-programmazioni" />
				<?php $this->render_filter_controls(); ?>
			</form>
			<form method="post" id="cinebot-titoli-list">
				<?php wp_nonce_field( 'bulk-titoli' ); ?>
				<input type="hidden" name="page" value="cinebot-wp-programmazioni" />
				<?php $this->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Return the declared column headers. */
	public function get_columns(): array {
		return array(
			'cb'                 => '<input type="checkbox" />',
			'titolo'             => __( 'Titolo', 'cinebot-wp' ),
			'autore'             => __( 'Autore', 'cinebot-wp' ),
			'tipoevento_codice' => __( 'Tipo evento', 'cinebot-wp' ),
			'locandina_url'     => __( 'Locandina', 'cinebot-wp' ),
			'eventi_count'      => __( 'Eventi', 'cinebot-wp' ),
			'source'            => __( 'Source', 'cinebot-wp' ),
			'updated_at'        => __( 'Ultima modifica', 'cinebot-wp' ),
		);
	}

	/** Return the bulk actions. */
	public function get_bulk_actions(): array {
		return array(
			'delete' => __( 'Delete', 'cinebot-wp' ),
		);
	}

	/** No sortable columns in version 1.0. */
	protected function get_sortable_columns(): array {
		return array();
	}

	/** Load items, count, and pagination arguments. */
	public function prepare_items(): void {
		$filters  = $this->current_filters();
		$per_page = 50;
		$page     = $this->get_pagenum();

		$this->items = $this->titles->search( $filters, $page, $per_page );
		$total       = $this->titles->count( $filters );

		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
			)
		);

		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);
	}

	/**
	 * Render the bulk checkbox column.
	 *
	 * @param Titolo $item Title row.
	 */
	protected function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="titolo[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Render the title column with a nonce-protected delete row action.
	 *
	 * @param Titolo $item Title row.
	 */
	protected function column_titolo( $item ): string {
		$id         = (int) $item->id;
		$edit_url   = admin_url( 'admin.php?page=cinebot-wp-programmazione-edit&id=' . $id );
		$delete_url = wp_nonce_url(
			admin_url( 'admin.php?page=cinebot-wp-programmazioni&action=delete&titolo=' . $id ),
			'cinebot-wp-delete-titolo_' . $id
		);
		$actions    = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				esc_html__( 'Modifica', 'cinebot-wp' )
			),
			'delete' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'cinebot-wp' )
			),
		);

		return esc_html( $item->titolo ) . $this->row_actions( $actions );
	}

	/**
	 * Render the remaining columns by name.
	 *
	 * @param Titolo $item Title row.
	 * @param string $column_name Column key.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'autore':
				return esc_html( $item->autore ?? '' );
			case 'tipoevento_codice':
				return esc_html( $item->tipoeventoCodice ?? '' );
			case 'locandina_url':
				return $this->render_poster( $item );
			case 'eventi_count':
				return (string) $this->events->countByTitoloId( (int) $item->id );
			case 'source':
				return esc_html( $item->source );
			case 'updated_at':
				return esc_html( $item->updatedAt ?? '' );
			default:
				return '';
		}
	}

	/** Render the empty-state message. */
	public function no_items(): void {
		esc_html_e( 'No titles found.', 'cinebot-wp' );
	}

	/**
	 * Delete titles and their events in a transaction.
	 *
	 * Deletion order: events -> title.
	 *
	 * @param array<int,mixed> $ids Title IDs.
	 */
	public function delete_titles( array $ids ): bool {
		$ids = array_values(
			array_filter(
				array_map( 'intval', $ids ),
				static function ( int $id ): bool {
					return $id > 0;
				}
			)
		);

		if ( array() === $ids ) {
			return false;
		}

		global $wpdb;

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'START TRANSACTION' );

			foreach ( $ids as $title_id ) {
				$this->events->deleteByTitoloId( $title_id );
				if ( ! $this->titles->delete( $title_id ) ) {
					throw new RuntimeException( 'delete failed' );
				}
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'COMMIT' );
			return true;
		} catch ( Throwable $e ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->query( 'ROLLBACK' );
			return false;
		}
	}

	/** Verify capability and nonce, then dispatch single or bulk deletion. */
	private function handle_actions(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$action = $this->current_action();
		if ( 'delete' !== $action ) {
			return;
		}

		$titolo = isset( $_REQUEST['titolo'] ) ? wp_unslash( $_REQUEST['titolo'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- verified below.

		if ( is_array( $titolo ) ) {
			check_admin_referer( 'bulk-titoli' );
			$ids    = array_map( 'intval', $titolo );
			$result = $this->delete_titles( $ids );
			$this->redirect( $result ? 'success' : 'failed' );
		}

		if ( null !== $titolo ) {
			$id     = (int) $titolo;
			check_admin_referer( 'cinebot-wp-delete-titolo_' . $id );
			$result = $this->delete_titles( array( $id ) );
			$this->redirect( $result ? 'success' : 'failed' );
		}
	}

	/** Return sanitized and allowlisted filter values from the request. */
	private function current_filters(): array {
		$filters = array();

		if ( isset( $_GET['tipoevento_codice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
			$code = sanitize_text_field( wp_unslash( $_GET['tipoevento_codice'] ) );
			if ( '' !== $code ) {
				$filters['tipoevento_codice'] = $code;
			}
		}

		if ( isset( $_GET['source'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
			$source = sanitize_text_field( wp_unslash( $_GET['source'] ) );
			if ( in_array( $source, array( 'api', 'manual' ), true ) ) {
				$filters['source'] = $source;
			}
		}

		if ( isset( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only filter.
			$search = sanitize_text_field( wp_unslash( $_GET['s'] ) );
			if ( '' !== $search ) {
				$filters['search'] = $search;
			}
		}

		return $filters;
	}

	/** Render admin notices based on the redirect status. */
	private function render_notices(): void {
		if ( ! isset( $_GET['deleted'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
			return;
		}

		$status = sanitize_text_field( wp_unslash( $_GET['deleted'] ) );

		if ( 'success' === $status ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Selected titles have been deleted.', 'cinebot-wp' ) . '</p></div>';
		} elseif ( 'failed' === $status ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Cinebot WP could not delete the selected titles. Try again.', 'cinebot-wp' ) . '</p></div>';
		}
	}

	/** Render the search box, type filter, and source filter. */
	private function render_filter_controls(): void {
		$types            = $this->types->findAll( true );
		$current_type     = isset( $_GET['tipoevento_codice'] ) ? sanitize_text_field( wp_unslash( $_GET['tipoevento_codice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		$current_source   = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		$current_search   = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- read-only.
		?>
		<p class="search-box">
			<label for="cinebot-search-input" class="screen-reader-text"><?php esc_html_e( 'Search titles', 'cinebot-wp' ); ?></label>
			<input type="search" id="cinebot-search-input" name="s" value="<?php echo esc_attr( $current_search ); ?>" />
			<?php submit_button( __( 'Search', 'cinebot-wp' ), '', '', false ); ?>
		</p>
		<div class="alignleft actions">
			<label for="cinebot-filter-type" class="screen-reader-text"><?php esc_html_e( 'Filter by event type', 'cinebot-wp' ); ?></label>
			<select id="cinebot-filter-type" name="tipoevento_codice">
				<option value=""><?php esc_html_e( 'All event types', 'cinebot-wp' ); ?></option>
				<?php foreach ( $types as $type ) : ?>
					<option value="<?php echo esc_attr( $type->codice ); ?>" <?php selected( $current_type, $type->codice ); ?>>
						<?php echo esc_html( $type->codice . ' — ' . $type->descrizione ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<label for="cinebot-filter-source" class="screen-reader-text"><?php esc_html_e( 'Filter by source', 'cinebot-wp' ); ?></label>
			<select id="cinebot-filter-source" name="source">
				<option value=""><?php esc_html_e( 'All sources', 'cinebot-wp' ); ?></option>
				<option value="api" <?php selected( $current_source, 'api' ); ?>><?php esc_html_e( 'API', 'cinebot-wp' ); ?></option>
				<option value="manual" <?php selected( $current_source, 'manual' ); ?>><?php esc_html_e( 'Manual', 'cinebot-wp' ); ?></option>
			</select>
			<?php submit_button( __( 'Filter', 'cinebot-wp' ), '', '', false ); ?>
		</div>
		<?php
	}

	/** Render an escaped poster thumbnail or a dash when the URL is absent. */
	private function render_poster( Titolo $item ): string {
		if ( empty( $item->locandinaUrl ) ) {
			return '&mdash;';
		}

		return sprintf(
			'<img src="%s" alt="%s" width="48" height="64" loading="lazy" />',
			esc_url( $item->locandinaUrl ),
			esc_attr( $item->titolo )
		);
	}

	/** Redirect to the clean page URL with the status flag. */
	private function redirect( string $status ): void {
		wp_safe_redirect(
			add_query_arg(
				array( 'deleted' => $status ),
				admin_url( 'admin.php?page=cinebot-wp-programmazioni' )
			)
		);
		exit;
	}

	/** Return the filtered admin capability. */
	private function capability(): string {
		return (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}
}
