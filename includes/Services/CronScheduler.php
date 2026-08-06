<?php
/**
 * WordPress cron scheduling for Cinebot synchronization.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Services;

use Throwable;

/** Registers and maintains the configured synchronization schedule. */
final class CronScheduler {
	private const HOOK = 'cinebot_wp_sync_event';
	private const WEEKLY_SCHEDULE = 'cinebot_weekly';

	/** @var SettingsService */
	private $settings;

	/** @var SyncService */
	private $sync;

	/** @var callable|null */
	private $sync_operation;

	/**
	 * Creates a scheduler with an optional synchronization operation for tests.
	 */
	public function __construct( SettingsService $settings, SyncService $sync, ?callable $sync_operation = null ) {
		$this->settings       = $settings;
		$this->sync           = $sync;
		$this->sync_operation = $sync_operation;
	}

	/** Registers the recurrence, cron dispatch, and settings update hooks. */
	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
		add_action( self::HOOK, array( $this, 'run_sync' ) );
		add_action( 'update_option_cinebot_wp_settings', array( $this, 'reschedule' ), 10, 3 );
	}

	/** Schedules the enabled synchronization frequency without duplicating events. */
	public function schedule(): void {
		if ( ! $this->settings->enabled() ) {
			$this->clear();
			return;
		}

		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$this->schedule_frequency( $this->settings->frequency() );
	}

	/** Replaces an event only when enabled state or frequency changes. */
	public function reschedule( array $old, array $new ): void {
		if (
			$this->enabled( $old ) === $this->enabled( $new )
			&& $this->frequency( $this->value( $old, 'sync_frequency' ) ) === $this->frequency( $this->value( $new, 'sync_frequency' ) )
		) {
			return;
		}

		$this->clear();
		if ( $this->enabled( $new ) ) {
			$this->schedule_frequency( $this->value( $new, 'sync_frequency' ) );
		}
	}

	/** Removes every pending Cinebot synchronization event. */
	public function clear(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Adds the Cinebot weekly recurrence to WordPress's built-in schedules. */
	public function add_weekly_schedule( array $schedules ): array {
		$schedules[ self::WEEKLY_SCHEDULE ] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Once Weekly',
		);

		return $schedules;
	}

	/** Executes synchronization while ensuring WP-Cron never exposes an error. */
	public function run_sync(): void {
		try {
			if ( null !== $this->sync_operation ) {
				call_user_func( $this->sync_operation );
				return;
			}

			$this->sync->sync();
		} catch ( Throwable $ignored ) {
			// WP-Cron must not expose synchronization exceptions to visitors.
		}
	}

	/** Normalizes settings into a supported WordPress recurrence key. */
	private function frequency( $frequency ): string {
		return in_array( $frequency, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true )
			? ( 'weekly' === $frequency ? self::WEEKLY_SCHEDULE : $frequency )
			: 'daily';
	}

	/** Creates one cron event using the given normalized settings frequency. */
	private function schedule_frequency( $frequency ): void {
		wp_schedule_event( time(), $this->frequency( $frequency ), self::HOOK );
	}

	/** Returns whether the stored settings explicitly enable scheduled sync. */
	private function enabled( array $settings ): bool {
		$value = $this->value( $settings, 'sync_enabled' );

		return true === $value || 1 === $value || '1' === $value || 'on' === $value;
	}

	/** Returns one optional settings value without coercing invalid values. */
	private function value( array $settings, string $key ) {
		return $settings[ $key ] ?? null;
	}
}
