<?php
/**
 * Minimal Dashboard admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Services\SettingsService;

/**
 * Renders a concrete Dashboard shell with sync status.
 *
 * Task 15 enhances this page with counters, recent logs, and manual sync.
 */
final class DashboardPage {
	/** @var SettingsService */
	private $settings;

	/**
	 * Store the settings service.
	 */
	public function __construct( SettingsService $settings ) {
		$this->settings = $settings;
	}

	/** Render the minimal Dashboard page. */
	public function render(): void {
		$enabled    = $this->settings->enabled();
		$frequency  = $this->settings->frequency();
		$next_sync  = wp_next_scheduled( 'cinebot_wp_sync_event' );
		$api_url    = admin_url( 'admin.php?page=cinebot-wp-api' );

		$status_label = $enabled
			? __( 'Enabled', 'cinebot-wp' )
			: __( 'Disabled', 'cinebot-wp' );

		$next_label = $next_sync
			? esc_html( gmdate( 'Y-m-d H:i', (int) $next_sync ) . ' UTC' )
			: __( 'Not scheduled', 'cinebot-wp' );

		?>
		<div class="wrap cinebot-dashboard">
			<h1><?php esc_html_e( 'Cinebot Dashboard', 'cinebot-wp' ); ?></h1>
			<div class="cinebot-dashboard-status">
				<p>
					<strong><?php esc_html_e( 'Synchronization:', 'cinebot-wp' ); ?></strong>
					<?php echo esc_html( $status_label ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Frequency:', 'cinebot-wp' ); ?></strong>
					<?php echo esc_html( $frequency ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Next sync:', 'cinebot-wp' ); ?></strong>
					<?php echo $next_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $api_url ); ?>" class="button button-primary">
						<?php esc_html_e( 'API Settings', 'cinebot-wp' ); ?>
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}
