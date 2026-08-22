<?php
/**
 * Dashboard admin page.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin\Pages;

use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\SettingsService;

/**
 * Renders the Dashboard with sync status, counters, and recent logs.
 */
final class DashboardPage {
	/** @var SettingsService */
	private $settings;

	/** @var TitoloRepository */
	private $titles;

	/** @var SyncLogRepository */
	private $logs;

	/**
	 * Store collaborators.
	 */
	public function __construct( SettingsService $settings, TitoloRepository $titles, SyncLogRepository $logs ) {
		$this->settings = $settings;
		$this->titles   = $titles;
		$this->logs     = $logs;
	}

	/** Render the Dashboard page. */
	public function render(): void {
		$enabled    = $this->settings->enabled();
		$frequency  = $this->settings->frequency();
		$next_sync  = wp_next_scheduled( 'cinebot_wp_sync_event' );
		$api_url    = admin_url( 'admin.php?page=cinebot-wp-api' );
		$stats      = $this->titles->statistics();
		$recent     = $this->logs->recent( 5 );

		$status_label = $enabled
			? __( 'Enabled', 'cinebot-wp' )
			: __( 'Disabled', 'cinebot-wp' );

		$next_label = $next_sync
			? esc_html( gmdate( 'Y-m-d H:i', (int) $next_sync ) . ' UTC' )
			: esc_html__( 'Not scheduled', 'cinebot-wp' );

		$log_url  = admin_url( 'admin.php?page=cinebot-wp-log' );
		$prog_url = admin_url( 'admin.php?page=cinebot-wp-programmazioni' );
		$loc_url  = admin_url( 'admin.php?page=cinebot-wp-locali' );
		$tip_url  = admin_url( 'admin.php?page=cinebot-wp-tipologie' );
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
					<?php echo $next_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both branches escaped above. ?>
				</p>
				<p>
					<a href="<?php echo esc_url( $api_url ); ?>" class="button button-primary"><?php esc_html_e( 'API Settings', 'cinebot-wp' ); ?></a>
					<a href="<?php echo esc_url( $prog_url ); ?>" class="button"><?php esc_html_e( 'Programmazioni', 'cinebot-wp' ); ?></a>
					<a href="<?php echo esc_url( $loc_url ); ?>" class="button"><?php esc_html_e( 'Locali', 'cinebot-wp' ); ?></a>
					<a href="<?php echo esc_url( $tip_url ); ?>" class="button"><?php esc_html_e( 'Tipologie', 'cinebot-wp' ); ?></a>
				</p>
			</div>

			<h2><?php esc_html_e( 'Conteggi rapidi', 'cinebot-wp' ); ?></h2>
			<div class="cinebot-dashboard-counters">
				<div class="cinebot-counter"><span class="cinebot-counter-num"><?php echo esc_html( (string) $stats['titoli_totali'] ); ?></span><?php esc_html_e( 'Titoli', 'cinebot-wp' ); ?></div>
				<div class="cinebot-counter"><span class="cinebot-counter-num"><?php echo esc_html( (string) $stats['titoli_manuali'] ); ?></span><?php esc_html_e( 'Manuali', 'cinebot-wp' ); ?></div>
				<div class="cinebot-counter"><span class="cinebot-counter-num"><?php echo esc_html( (string) $stats['eventi_totali'] ); ?></span><?php esc_html_e( 'Eventi', 'cinebot-wp' ); ?></div>
				<div class="cinebot-counter"><span class="cinebot-counter-num"><?php echo esc_html( (string) $stats['locali_totali'] ); ?></span><?php esc_html_e( 'Locali', 'cinebot-wp' ); ?></div>
				<div class="cinebot-counter"><span class="cinebot-counter-num"><?php echo esc_html( (string) $stats['tipologie_attive'] ); ?></span><?php esc_html_e( 'Tipologie attive', 'cinebot-wp' ); ?></div>
			</div>

			<h2><?php esc_html_e( 'Ultime sincronizzazioni', 'cinebot-wp' ); ?> <a href="<?php echo esc_url( $log_url ); ?>" class="page-title-action"><?php esc_html_e( 'Vedi tutti', 'cinebot-wp' ); ?></a></h2>
			<table class="widefat striped" role="presentation">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Data', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Status', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Titoli +/Δ', 'cinebot-wp' ); ?></th>
						<th><?php esc_html_e( 'Eventi +/Δ', 'cinebot-wp' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $recent ) : ?>
						<tr><td colspan="4"><?php esc_html_e( 'Nessuna sincronizzazione registrata.', 'cinebot-wp' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $recent as $log ) : ?>
							<tr>
								<td><?php echo esc_html( $log->startedAt ); ?></td>
								<td><?php echo esc_html( $log->status ?? '—' ); ?></td>
								<td>+<?php echo esc_html( (string) $log->titoliAdded ); ?>/Δ<?php echo esc_html( (string) $log->titoliUpdated ); ?></td>
								<td>+<?php echo esc_html( (string) $log->eventiAdded ); ?>/Δ<?php echo esc_html( (string) $log->eventiUpdated ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
