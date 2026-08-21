<?php
/**
 * API settings admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Services\ApiClient;
use CinebotWp\Services\ApiException;
use CinebotWp\Services\CronScheduler;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use Throwable;

/**
 * Renders the API settings form and handles save, test-connection, and sync-now AJAX.
 */
final class ApiPage {
	/** @var SettingsService */
	private $settings;

	/** @var CronScheduler */
	private $scheduler;

	/** @var SyncService */
	private $sync;

	/**
	 * Store page collaborators.
	 */
	public function __construct( SettingsService $settings, CronScheduler $scheduler, SyncService $sync ) {
		$this->settings  = $settings;
		$this->scheduler  = $scheduler;
		$this->sync       = $sync;
	}

	/** Render the API settings form. */
	public function render(): void {
		$settings  = $this->settings->get();
		$has_pass  = $settings['has_password'];
		$freqs     = array(
			'hourly'      => __( 'Hourly', 'cinebot-wp' ),
			'twicedaily'  => __( 'Twice daily', 'cinebot-wp' ),
			'daily'       => __( 'Daily', 'cinebot-wp' ),
			'weekly'      => __( 'Weekly', 'cinebot-wp' ),
		);
		?>
		<div class="wrap cinebot-api-page">
			<h1><?php esc_html_e( 'Cinebot API Settings', 'cinebot-wp' ); ?></h1>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'cinebot_wp_save_api', 'cinebot_wp_api_nonce' ); ?>
				<input type="hidden" name="action" value="cinebot_wp_save_api" />
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cinebot_username"><?php esc_html_e( 'Username', 'cinebot-wp' ); ?></label></th>
						<td>
							<input type="text" id="cinebot_username" name="api_username"
								value="<?php echo esc_attr( $settings['api_username'] ); ?>"
								class="regular-text" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cinebot_password"><?php esc_html_e( 'Password', 'cinebot-wp' ); ?></label></th>
						<td>
							<input type="password" id="cinebot_password" name="api_password"
								placeholder="<?php echo $has_pass ? esc_attr__( '•••••••• (leave blank to keep current)', 'cinebot-wp' ) : ''; ?>"
								class="regular-text" autocomplete="new-password" />
							<?php if ( $has_pass ) : ?>
								<p class="description"><?php esc_html_e( 'A password is already stored. Leave blank to keep it.', 'cinebot-wp' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cinebot_frontend"><?php esc_html_e( 'Frontend ID', 'cinebot-wp' ); ?></label></th>
						<td>
							<input type="number" id="cinebot_frontend" name="api_frontend"
								value="<?php echo esc_attr( (string) ( $settings['api_frontend'] ?? '' ) ); ?>"
								min="1" class="regular-text" />
							<p class="description"><?php esc_html_e( 'Optional numeric frontend identifier.', 'cinebot-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cinebot_detail_slug"><?php esc_html_e( 'Detail Page Slug', 'cinebot-wp' ); ?></label></th>
						<td>
							<input type="text" id="cinebot_detail_slug" name="detail_slug"
								value="<?php echo esc_attr( $settings['detail_slug'] ?? '' ); ?>"
								class="regular-text" placeholder="es. spettacolo" />
							<p class="description"><?php esc_html_e( 'Optional slug to enable pretty permalinks for event details (e.g., "spettacolo" for /spettacolo/15/title/).', 'cinebot-wp' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cinebot_frequency"><?php esc_html_e( 'Sync frequency', 'cinebot-wp' ); ?></label></th>
						<td>
							<select id="cinebot_frequency" name="sync_frequency">
								<?php foreach ( $freqs as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"
										<?php selected( $settings['sync_frequency'], $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable cron sync', 'cinebot-wp' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="sync_enabled" value="1"
									<?php checked( $settings['sync_enabled'] ); ?> />
								<?php esc_html_e( 'Schedule automatic synchronization', 'cinebot-wp' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'cinebot-wp' ); ?></button>
					<button type="button" class="button" id="cinebot-test-connection"><?php esc_html_e( 'Test connection', 'cinebot-wp' ); ?></button>
					<button type="button" class="button" id="cinebot-sync-now"><?php esc_html_e( 'Synchronize now', 'cinebot-wp' ); ?></button>
				</p>
				<div id="cinebot-ajax-status" role="status" aria-live="polite" class="cinebot-ajax-status"></div>
			</form>
		</div>
		<?php
	}

	/** Save the submitted API settings. */
	public function save(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'cinebot-wp' ) );
		}

		check_admin_referer( 'cinebot_wp_save_api', 'cinebot_wp_api_nonce' );

		$old = $this->settings->get();
		$this->settings->save( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$new = $this->settings->get();
		$this->scheduler->reschedule( $old, $new );

		wp_safe_redirect( admin_url( 'admin.php?page=cinebot-wp-api' ) );
		exit;
	}

	/** AJAX handler: test API connection without persisting. */
	public function testConnection(): void {
		if ( ! check_ajax_referer( 'cinebot_wp_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'cinebot-wp' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cinebot-wp' ) ), 403 );
			return;
		}

		try {
			$client    = new ApiClient( $this->settings );
			$payload   = $client->fetchProgrammazione();
			$titoli    = 0;
			foreach ( $payload['programmazione'] ?? array() as $envelope ) {
				$titoli += isset( $envelope['titoli'] ) && is_array( $envelope['titoli'] )
					? count( $envelope['titoli'] )
					: 0;
			}
			wp_send_json_success( array(
				'titoli_count' => $titoli,
			) );
			return;
		} catch ( ApiException $e ) {
			wp_send_json_error( array(
				'message' => $e->getMessage(),
			) );
			return;
		} catch ( Throwable $e ) {
			wp_send_json_error( array(
				'message' => __( 'Unable to connect to the Cinebot API.', 'cinebot-wp' ),
			) );
			return;
		}
	}

	/** AJAX handler: trigger an immediate synchronization. */
	public function syncNow(): void {
		if ( ! check_ajax_referer( 'cinebot_wp_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Nonce verification failed.', 'cinebot-wp' ) ), 403 );
			return;
		}

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'cinebot-wp' ) ), 403 );
			return;
		}

		$result = $this->sync->sync();

		if ( $result->isSuccess() ) {
			wp_send_json_success( array(
				'stats'   => $result->stats(),
				'message' => $result->message(),
			) );
			return;
		}

		wp_send_json_error( array(
			'message' => $result->message(),
		) );
		return;
	}

	/** Return the filtered admin capability. */
	private function capability(): string {
		return (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}
}
