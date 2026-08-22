<?php
/**
 * Sync log admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Repositories\SyncLogRepository;
use DateTimeImmutable;

/**
 * Renders the synchronization log list with retention actions.
 */
final class SyncLogPage {
	/** @var SyncLogRepository */
	private $logs;

	/** @var string */
	private $capability;

	/** @param SyncLogRepository $logs */
	public function __construct( SyncLogRepository $logs ) {
		$this->logs       = $logs;
		$this->capability = (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}

	/** Render the log list page. */
	public function render(): void {
		$this->maybe_handle_bulk();

		require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

		$repo = $this->logs;

		$table = new class( $repo ) extends \WP_List_Table {
			/** @var SyncLogRepository */
			private $logs;

			public function __construct( SyncLogRepository $logs ) {
				parent::__construct( array(
					'singular' => 'log',
					'plural'   => 'logs',
					'screen'   => 'cinebot-wp-log',
				) );
				$this->logs = $logs;
			}

			public function get_columns() {
				return array(
					'started_at'     => __( 'Started', 'cinebot-wp' ),
					'finished_at'    => __( 'Finished', 'cinebot-wp' ),
					'status'         => __( 'Status', 'cinebot-wp' ),
					'titoli'         => __( 'Titoli +/Δ', 'cinebot-wp' ),
					'eventi'         => __( 'Eventi +/Δ', 'cinebot-wp' ),
					'error_message'  => __( 'Error', 'cinebot-wp' ),
				);
			}

			public function get_sortable_columns() {
				return array( 'started_at' => array( 'started_at', true ) );
			}

			/** @param object $item */
			public function column_default( $item, $column_name ) {
				switch ( $column_name ) {
					case 'started_at':
						return esc_html( $item->startedAt ?? '' );
					case 'finished_at':
						return esc_html( $item->finishedAt ?? '—' );
					case 'status':
						$class = 'success' === $item->status ? 'status-success' : ( 'error' === $item->status ? 'status-error' : 'status-partial' );
						return sprintf( '<span class="cinebot-%s">%s</span>', esc_attr( $class ), esc_html( $item->status ?? '—' ) );
					case 'titoli':
						return sprintf( '+%d / Δ%d', (int) $item->titoliAdded, (int) $item->titoliUpdated );
					case 'eventi':
						return sprintf( '+%d / Δ%d', (int) $item->eventiAdded, (int) $item->eventiUpdated );
					case 'error_message':
						return $item->errorMessage ? esc_html( mb_substr( (string) $item->errorMessage, 0, 80 ) ) : '—';
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
				$status   = isset( $_REQUEST['status'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['status'] ) ) : '';
				if ( in_array( $status, array( 'success', 'error', 'partial', 'running' ), true ) ) {
					$filters['status'] = $status;
				}

				$total      = $this->logs->count( $filters );
				$page       = $this->get_pagenum();
				$this->items = $this->logs->search( $filters, $page, $per_page );

				$this->set_pagination_args( array(
					'total_items' => $total,
					'per_page'    => $per_page,
					'total_pages' => max( 1, (int) ceil( $total / $per_page ) ),
				) );
			}
		};

		$table->prepare_items();
		$cleanup_url = wp_nonce_url( admin_url( 'admin-post.php?action=cinebot_cleanup_logs' ), 'cinebot_cleanup_logs' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Log sincronizzazioni', 'cinebot-wp' ); ?></h1>
			<p><a href="<?php echo esc_url( $cleanup_url ); ?>" class="button"><?php esc_html_e( 'Pulisci log > 30 giorni', 'cinebot-wp' ); ?></a></p>
			<form method="get">
				<input type="hidden" name="page" value="cinebot-wp-log" />
				<select name="status">
					<option value=""><?php esc_html_e( 'All statuses', 'cinebot-wp' ); ?></option>
					<option value="success" <?php selected( $_REQUEST['status'] ?? '', 'success' ); ?>><?php esc_html_e( 'Success', 'cinebot-wp' ); ?></option>
					<option value="error" <?php selected( $_REQUEST['status'] ?? '', 'error' ); ?>><?php esc_html_e( 'Error', 'cinebot-wp' ); ?></option>
					<option value="partial" <?php selected( $_REQUEST['status'] ?? '', 'partial' ); ?>><?php esc_html_e( 'Partial', 'cinebot-wp' ); ?></option>
				</select>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	/** Delete log entries older than 30 days. */
	public function deleteOld(): void {
		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}
		check_admin_referer( 'cinebot_cleanup_logs' );

		$cutoff = new DateTimeImmutable( '-30 days', wp_timezone() );
		$deleted = $this->logs->deleteOlderThan( $cutoff );

		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-log' ) );
		exit;
	}

	/**
	 * Handle bulk delete action for log entries.
	 */
	private function maybe_handle_bulk(): void {
		if ( ! isset( $_REQUEST['action'] ) || 'delete' !== $_REQUEST['action'] ) {
			return;
		}

		if ( ! current_user_can( $this->capability ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}

		check_admin_referer( 'bulk-logs' );

		$ids = isset( $_REQUEST['log'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['log'] ) ) : array();

		foreach ( $ids as $id ) {
			if ( $id > 0 ) {
				$this->logs->delete( $id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-log' ) );
		exit;
	}
}
