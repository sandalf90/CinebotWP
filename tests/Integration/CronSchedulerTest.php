<?php
/**
 * WP-Cron scheduler integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Plugin;
use CinebotWp\Services\CronScheduler;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use WP_UnitTestCase;

/** Verifies scheduled synchronization registration and lifecycle cleanup. */
final class CronSchedulerTest extends WP_UnitTestCase {
	private const HOOK = 'cinebot_wp_sync_event';

	/** Remove cron state and settings before every test. */
	public function set_up(): void {
		parent::set_up();
		wp_clear_scheduled_hook( self::HOOK );
		remove_all_actions( self::HOOK );
		remove_all_actions( 'update_option_cinebot_wp_settings' );
		remove_all_filters( 'cron_schedules' );
		delete_option( 'cinebot_wp_settings' );
	}

	/** Registers the Cinebot weekly interval. */
	public function test_registers_weekly_schedule(): void {
		$scheduler = $this->scheduler();
		$scheduler->register();

		$schedules = wp_get_schedules();
		self::assertArrayHasKey( 'cinebot_weekly', $schedules );
		self::assertSame( WEEK_IN_SECONDS, $schedules['cinebot_weekly']['interval'] );
	}

	/** Disabled synchronization does not create a cron event. */
	public function test_disabled_settings_do_not_schedule_an_event(): void {
		update_option( 'cinebot_wp_settings', $this->settings( false, 'daily' ) );

		$scheduler = $this->scheduler();
		$scheduler->register();
		$scheduler->schedule();

		self::assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	/** Each supported recurrence produces one scheduled event. */
	public function test_enabled_settings_schedule_each_supported_recurrence_once(): void {
		foreach ( array( 'hourly', 'twicedaily', 'daily', 'weekly' ) as $frequency ) {
			wp_clear_scheduled_hook( self::HOOK );
			update_option( 'cinebot_wp_settings', $this->settings( true, $frequency ) );

			$scheduler = $this->scheduler();
			$scheduler->register();
			$scheduler->schedule();
			$scheduler->schedule();

			$event = wp_get_scheduled_event( self::HOOK );
			self::assertNotFalse( $event );
			self::assertSame( $frequency === 'weekly' ? 'cinebot_weekly' : $frequency, $event->schedule );
			self::assertSame( 1, $this->scheduled_event_count() );
		}
	}

	/** Unknown schedule frequencies fall back to the daily event. */
	public function test_unknown_frequency_schedules_daily_event(): void {
		update_option( 'cinebot_wp_settings', $this->settings( true, 'unexpected' ) );

		$scheduler = $this->scheduler();
		$scheduler->register();
		$scheduler->schedule();

		$event = wp_get_scheduled_event( self::HOOK );
		self::assertNotFalse( $event );
		self::assertSame( 'daily', $event->schedule );
	}

	/** A frequency update replaces the event without duplication. */
	public function test_reschedule_replaces_changed_frequency_without_duplicates(): void {
		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
		$scheduler = $this->scheduler();
		$scheduler->register();
		$scheduler->schedule();

		$scheduler->reschedule( $this->settings( true, 'daily' ), $this->settings( true, 'weekly' ) );

		$event = wp_get_scheduled_event( self::HOOK );
		self::assertNotFalse( $event );
		self::assertSame( 'cinebot_weekly', $event->schedule );
		self::assertSame( 1, $this->scheduled_event_count() );
	}

	/** Disabling synchronization clears an existing event. */
	public function test_reschedule_clears_event_when_synchronization_is_disabled(): void {
		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
		$scheduler = $this->scheduler();
		$scheduler->register();
		$scheduler->schedule();

		$scheduler->reschedule( $this->settings( true, 'daily' ), $this->settings( false, 'daily' ) );

		self::assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	/** Settings option updates invoke scheduler replacement only for cron changes. */
	public function test_settings_update_hook_reschedules_only_when_cron_settings_change(): void {
		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
		$scheduler = $this->scheduler();
		$scheduler->register();
		$scheduler->schedule();

		update_option( 'cinebot_wp_settings', $this->settings( true, 'weekly' ) );
		$event = wp_get_scheduled_event( self::HOOK );
		self::assertNotFalse( $event );
		self::assertSame( 'cinebot_weekly', $event->schedule );
		self::assertSame( 1, $this->scheduled_event_count() );

		update_option( 'cinebot_wp_settings', $this->settings( false, 'weekly' ) );
		self::assertFalse( wp_next_scheduled( self::HOOK ) );
	}

	/** Cron dispatch invokes the synchronization operation once and contains failures. */
	public function test_cron_dispatch_invokes_sync_once_and_contains_throwables(): void {
		$sync_count = 0;
		$scheduler = $this->scheduler(
			static function () use ( &$sync_count ): void {
				++$sync_count;
				throw new \RuntimeException( 'Do not expose this failure.' );
			}
		);
		$scheduler->register();

		do_action( self::HOOK );

		self::assertSame( 1, $sync_count );
	}

	/** Activation schedules enabled synchronization and deactivation only removes cron. */
	public function test_plugin_lifecycle_schedules_enabled_sync_and_clears_it_on_deactivation(): void {
		update_option( 'cinebot_wp_settings', $this->settings( true, 'weekly' ) );

		Plugin::activate();
		$event = wp_get_scheduled_event( self::HOOK );
		self::assertNotFalse( $event );
		self::assertSame( 'cinebot_weekly', $event->schedule );

		Plugin::deactivate();
		self::assertFalse( wp_next_scheduled( self::HOOK ) );
		self::assertSame( true, get_option( 'cinebot_wp_settings' )['sync_enabled'] );
	}

	/** Builds the scheduler with an injectable synchronization operation. */
	private function scheduler( ?callable $sync = null ): CronScheduler {
		global $wpdb;

		return new CronScheduler( new SettingsService(), new SyncService( $wpdb ), $sync );
	}

	/** Returns settings persisted directly by these scheduling tests. */
	private function settings( bool $enabled, string $frequency ): array {
		return array(
			'api_username'   => '',
			'api_frontend'   => null,
			'sync_enabled'   => $enabled,
			'sync_frequency' => $frequency,
			'api_base_url'   => 'https://ws.cinebot.it',
		);
	}

	/** Counts events stored for the Cinebot synchronization hook. */
	private function scheduled_event_count(): int {
		$cron = _get_cron_array();
		$count = 0;
		foreach ( $cron as $events ) {
			if ( isset( $events[ self::HOOK ] ) ) {
				$count += count( $events[ self::HOOK ] );
			}
		}

		return $count;
	}
}
