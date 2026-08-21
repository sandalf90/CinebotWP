# Task 10 Reviewer Handoff Package

## Scope

Task 10 adds the WP-Cron scheduler, Plugin lifecycle composition, and integration coverage. Review range: parent `9e40ff84b4f814faed3e0cb9d3d81e34bfb3b523` to commit `2b4473c775a37554851734ae44bb1274892668bc`.

The Task 10 brief requires weekly registration, idempotent selected-frequency scheduling, update-option replacement, safe sync dispatch, and activation/deactivation scheduling/cleanup. The uncommitted report records `NEEDS_RUNTIME`: Docker/PHP/Composer gates are unavailable, while whitespace validation passed.

## Commit Metadata

```text
commit 2b4473c775a37554851734ae44bb1274892668bc
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 19:56:40 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 19:56:40 2026 +0200

    feat: schedule cinebot synchronization
```

## Full Stat

```text
commit 2b4473c775a37554851734ae44bb1274892668bc
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 19:56:40 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 19:56:40 2026 +0200

    feat: schedule cinebot synchronization

 includes/Plugin.php                     |  16 ++-
 includes/Services/CronScheduler.php     | 123 +++++++++++++++++++++
 tests/Integration/CronSchedulerTest.php | 186 ++++++++++++++++++++++++++++++++
 3 files changed, 324 insertions(+), 1 deletion(-)
```

## Full Relevant Diff

Command: `git diff --unified=10 9e40ff8 2b4473c -- includes/Plugin.php includes/Services/CronScheduler.php tests/Integration/CronSchedulerTest.php`

```diff
diff --git a/includes/Plugin.php b/includes/Plugin.php
index 42fe036..9dbc9db 100644
--- a/includes/Plugin.php
+++ b/includes/Plugin.php
@@ -1,20 +1,23 @@
 <?php
 /**
  * Main plugin coordinator.
  *
  * @package CinebotWp
  */
 
 namespace CinebotWp;
 
 use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Services\CronScheduler;
+use CinebotWp\Services\SettingsService;
+use CinebotWp\Services\SyncService;
 
 final class Plugin {
 	/**
 	 * Singleton instance.
 	 *
 	 * @var self|null
 	 */
 	private static $instance;
 
 	/**
@@ -35,37 +38,48 @@ final class Plugin {
 		return self::$instance;
 	}
 
 	/**
 	 * Install the plugin database schema on activation.
 	 */
 	public static function activate(): void {
 		global $wpdb;
 
 		( new SchemaInstaller( $wpdb ) )->install();
+		$scheduler = self::scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
 	}
 
 	/**
 	 * Stop scheduled synchronization on deactivation.
 	 */
 	public static function deactivate(): void {
-		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+		self::scheduler()->clear();
 	}
 
 	/**
 	 * Boot the plugin once.
 	 */
 	public function boot(): void {
 		if ( $this->booted ) {
 			return;
 		}
 
 		$this->booted = true;
+		self::scheduler()->register();
 		do_action( 'cinebot_wp_booted' );
 	}
 
+	/** Compose the synchronization scheduler at the plugin boundary. */
+	private static function scheduler(): CronScheduler {
+		global $wpdb;
+
+		return new CronScheduler( new SettingsService(), new SyncService( $wpdb ) );
+	}
+
 	/**
 	 * Prevent direct construction.
 	 */
 	private function __construct() {
 	}
 }
diff --git a/includes/Services/CronScheduler.php b/includes/Services/CronScheduler.php
new file mode 100644
index 0000000..3673a29
--- /dev/null
+++ b/includes/Services/CronScheduler.php
@@ -0,0 +1,123 @@
+<?php
+/**
+ * WordPress cron scheduling for Cinebot synchronization.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use Throwable;
+
+/** Registers and maintains the configured synchronization schedule. */
+final class CronScheduler {
+	private const HOOK = 'cinebot_wp_sync_event';
+	private const WEEKLY_SCHEDULE = 'cinebot_weekly';
+
+	/** @var SettingsService */
+	private $settings;
+
+	/** @var SyncService */
+	private $sync;
+
+	/** @var callable|null */
+	private $sync_operation;
+
+	/**
+	 * Creates a scheduler with an optional synchronization operation for tests.
+	 */
+	public function __construct( SettingsService $settings, SyncService $sync, ?callable $sync_operation = null ) {
+		$this->settings       = $settings;
+		$this->sync           = $sync;
+		$this->sync_operation = $sync_operation;
+	}
+
+	/** Registers the recurrence, cron dispatch, and settings update hooks. */
+	public function register(): void {
+		add_filter( 'cron_schedules', array( $this, 'add_weekly_schedule' ) );
+		add_action( self::HOOK, array( $this, 'run_sync' ) );
+		add_action( 'update_option_cinebot_wp_settings', array( $this, 'reschedule' ), 10, 3 );
+	}
+
+	/** Schedules the enabled synchronization frequency without duplicating events. */
+	public function schedule(): void {
+		if ( ! $this->settings->enabled() ) {
+			$this->clear();
+			return;
+		}
+
+		if ( false !== wp_next_scheduled( self::HOOK ) ) {
+			return;
+		}
+
+		$this->schedule_frequency( $this->settings->frequency() );
+	}
+
+	/** Replaces an event only when enabled state or frequency changes. */
+	public function reschedule( array $old, array $new ): void {
+		if (
+			$this->enabled( $old ) === $this->enabled( $new )
+			&& $this->frequency( $this->value( $old, 'sync_frequency' ) ) === $this->frequency( $this->value( $new, 'sync_frequency' ) )
+		) {
+			return;
+		}
+
+		$this->clear();
+		if ( $this->enabled( $new ) ) {
+			$this->schedule_frequency( $this->value( $new, 'sync_frequency' ) );
+		}
+	}
+
+	/** Removes every pending Cinebot synchronization event. */
+	public function clear(): void {
+		wp_clear_scheduled_hook( self::HOOK );
+	}
+
+	/** Adds the Cinebot weekly recurrence to WordPress's built-in schedules. */
+	public function add_weekly_schedule( array $schedules ): array {
+		$schedules[ self::WEEKLY_SCHEDULE ] = array(
+			'interval' => WEEK_IN_SECONDS,
+			'display'  => 'Once Weekly',
+		);
+
+		return $schedules;
+	}
+
+	/** Executes synchronization while ensuring WP-Cron never exposes an error. */
+	public function run_sync(): void {
+		try {
+			if ( null !== $this->sync_operation ) {
+				call_user_func( $this->sync_operation );
+				return;
+			}
+
+			$this->sync->sync();
+		} catch ( Throwable $ignored ) {
+			// WP-Cron must not expose synchronization exceptions to visitors.
+		}
+	}
+
+	/** Normalizes settings into a supported WordPress recurrence key. */
+	private function frequency( $frequency ): string {
+		return in_array( $frequency, array( 'hourly', 'twicedaily', 'daily', 'weekly' ), true )
+			? ( 'weekly' === $frequency ? self::WEEKLY_SCHEDULE : $frequency )
+			: 'daily';
+	}
+
+	/** Creates one cron event using the given normalized settings frequency. */
+	private function schedule_frequency( $frequency ): void {
+		wp_schedule_event( time(), $this->frequency( $frequency ), self::HOOK );
+	}
+
+	/** Returns whether the stored settings explicitly enable scheduled sync. */
+	private function enabled( array $settings ): bool {
+		$value = $this->value( $settings, 'sync_enabled' );
+
+		return true === $value || 1 === $value || '1' === $value || 'on' === $value;
+	}
+
+	/** Returns one optional settings value without coercing invalid values. */
+	private function value( array $settings, string $key ) {
+		return $settings[ $key ] ?? null;
+	}
+}
diff --git a/tests/Integration/CronSchedulerTest.php b/tests/Integration/CronSchedulerTest.php
new file mode 100644
index 0000000..bf32daf
--- /dev/null
+++ b/tests/Integration/CronSchedulerTest.php
@@ -0,0 +1,186 @@
+<?php
+/**
+ * WP-Cron scheduler integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+use CinebotWp\Plugin;
+use CinebotWp\Services\CronScheduler;
+use CinebotWp\Services\SettingsService;
+use CinebotWp\Services\SyncService;
+use WP_UnitTestCase;
+
+/** Verifies scheduled synchronization registration and lifecycle cleanup. */
+final class CronSchedulerTest extends WP_UnitTestCase {
+	private const HOOK = 'cinebot_wp_sync_event';
+
+	/** Remove cron state and settings before every test. */
+	public function set_up(): void {
+		parent::set_up();
+		wp_clear_scheduled_hook( self::HOOK );
+		remove_all_actions( self::HOOK );
+		remove_all_actions( 'update_option_cinebot_wp_settings' );
+		remove_all_filters( 'cron_schedules' );
+		delete_option( 'cinebot_wp_settings' );
+	}
+
+	/** Registers the Cinebot weekly interval. */
+	public function test_registers_weekly_schedule(): void {
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+
+		$schedules = wp_get_schedules();
+		self::assertArrayHasKey( 'cinebot_weekly', $schedules );
+		self::assertSame( WEEK_IN_SECONDS, $schedules['cinebot_weekly']['interval'] );
+	}
+
+	/** Disabled synchronization does not create a cron event. */
+	public function test_disabled_settings_do_not_schedule_an_event(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( false, 'daily' ) );
+
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
+
+		self::assertFalse( wp_next_scheduled( self::HOOK ) );
+	}
+
+	/** Each supported recurrence produces one scheduled event. */
+	public function test_enabled_settings_schedule_each_supported_recurrence_once(): void {
+		foreach ( array( 'hourly', 'twicedaily', 'daily', 'weekly' ) as $frequency ) {
+			wp_clear_scheduled_hook( self::HOOK );
+			update_option( 'cinebot_wp_settings', $this->settings( true, $frequency ) );
+
+			$scheduler = $this->scheduler();
+			$scheduler->register();
+			$scheduler->schedule();
+			$scheduler->schedule();
+
+			$event = wp_get_scheduled_event( self::HOOK );
+			self::assertNotFalse( $event );
+			self::assertSame( $frequency === 'weekly' ? 'cinebot_weekly' : $frequency, $event->schedule );
+			self::assertSame( 1, $this->scheduled_event_count() );
+		}
+	}
+
+	/** Unknown schedule frequencies fall back to the daily event. */
+	public function test_unknown_frequency_schedules_daily_event(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'unexpected' ) );
+
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
+
+		$event = wp_get_scheduled_event( self::HOOK );
+		self::assertNotFalse( $event );
+		self::assertSame( 'daily', $event->schedule );
+	}
+
+	/** A frequency update replaces the event without duplication. */
+	public function test_reschedule_replaces_changed_frequency_without_duplicates(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
+
+		$scheduler->reschedule( $this->settings( true, 'daily' ), $this->settings( true, 'weekly' ) );
+
+		$event = wp_get_scheduled_event( self::HOOK );
+		self::assertNotFalse( $event );
+		self::assertSame( 'cinebot_weekly', $event->schedule );
+		self::assertSame( 1, $this->scheduled_event_count() );
+	}
+
+	/** Disabling synchronization clears an existing event. */
+	public function test_reschedule_clears_event_when_synchronization_is_disabled(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
+
+		$scheduler->reschedule( $this->settings( true, 'daily' ), $this->settings( false, 'daily' ) );
+
+		self::assertFalse( wp_next_scheduled( self::HOOK ) );
+	}
+
+	/** Settings option updates invoke scheduler replacement only for cron changes. */
+	public function test_settings_update_hook_reschedules_only_when_cron_settings_change(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'daily' ) );
+		$scheduler = $this->scheduler();
+		$scheduler->register();
+		$scheduler->schedule();
+
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'weekly' ) );
+		$event = wp_get_scheduled_event( self::HOOK );
+		self::assertNotFalse( $event );
+		self::assertSame( 'cinebot_weekly', $event->schedule );
+		self::assertSame( 1, $this->scheduled_event_count() );
+
+		update_option( 'cinebot_wp_settings', $this->settings( false, 'weekly' ) );
+		self::assertFalse( wp_next_scheduled( self::HOOK ) );
+	}
+
+	/** Cron dispatch invokes the synchronization operation once and contains failures. */
+	public function test_cron_dispatch_invokes_sync_once_and_contains_throwables(): void {
+		$sync_count = 0;
+		$scheduler = $this->scheduler(
+			static function () use ( &$sync_count ): void {
+				++$sync_count;
+				throw new \RuntimeException( 'Do not expose this failure.' );
+			}
+		);
+		$scheduler->register();
+
+		do_action( self::HOOK );
+
+		self::assertSame( 1, $sync_count );
+	}
+
+	/** Activation schedules enabled synchronization and deactivation only removes cron. */
+	public function test_plugin_lifecycle_schedules_enabled_sync_and_clears_it_on_deactivation(): void {
+		update_option( 'cinebot_wp_settings', $this->settings( true, 'weekly' ) );
+
+		Plugin::activate();
+		$event = wp_get_scheduled_event( self::HOOK );
+		self::assertNotFalse( $event );
+		self::assertSame( 'cinebot_weekly', $event->schedule );
+
+		Plugin::deactivate();
+		self::assertFalse( wp_next_scheduled( self::HOOK ) );
+		self::assertSame( true, get_option( 'cinebot_wp_settings' )['sync_enabled'] );
+	}
+
+	/** Builds the scheduler with an injectable synchronization operation. */
+	private function scheduler( ?callable $sync = null ): CronScheduler {
+		global $wpdb;
+
+		return new CronScheduler( new SettingsService(), new SyncService( $wpdb ), $sync );
+	}
+
+	/** Returns settings persisted directly by these scheduling tests. */
+	private function settings( bool $enabled, string $frequency ): array {
+		return array(
+			'api_username'   => '',
+			'api_frontend'   => null,
+			'sync_enabled'   => $enabled,
+			'sync_frequency' => $frequency,
+			'api_base_url'   => 'https://ws.cinebot.it',
+		);
+	}
+
+	/** Counts events stored for the Cinebot synchronization hook. */
+	private function scheduled_event_count(): int {
+		$cron = _get_cron_array();
+		$count = 0;
+		foreach ( $cron as $events ) {
+			if ( isset( $events[ self::HOOK ] ) ) {
+				$count += count( $events[ self::HOOK ] );
+			}
+		}
+
+		return $count;
+	}
+}
```

## Current Uncommitted Status

```text
## feat/cinebot-wp
 M specs/execution-status.yaml
 M specs/state.yaml
?? .superpowers/sdd/progress.md
?? .superpowers/sdd/task-1-review-package.md
?? .superpowers/sdd/task-1-review.md
?? .superpowers/sdd/task-10-brief.md
?? .superpowers/sdd/task-10-report.md
?? .superpowers/sdd/task-10-review-package.md
?? .superpowers/sdd/task-2-brief.md
?? .superpowers/sdd/task-2-review-package.md
?? .superpowers/sdd/task-2-review.md
?? .superpowers/sdd/task-3-brief.md
?? .superpowers/sdd/task-3-review-package.md
?? .superpowers/sdd/task-3-review.md
?? .superpowers/sdd/task-4-brief.md
?? .superpowers/sdd/task-4-review-package.md
?? .superpowers/sdd/task-4-review.md
?? .superpowers/sdd/task-5-brief.md
?? .superpowers/sdd/task-5-review-package.md
?? .superpowers/sdd/task-5-review.md
?? .superpowers/sdd/task-6-brief.md
?? .superpowers/sdd/task-6-review-package.md
?? .superpowers/sdd/task-6-review.md
?? .superpowers/sdd/task-7-brief.md
?? .superpowers/sdd/task-7-review-package.md
?? .superpowers/sdd/task-7-review.md
?? .superpowers/sdd/task-8-brief.md
?? .superpowers/sdd/task-8-review-package.md
?? .superpowers/sdd/task-8-review.md
?? .superpowers/sdd/task-9-brief.md
?? .superpowers/sdd/task-9-review-package.md
?? .superpowers/sdd/task-9-review.md
```

The modified specs and coordinator/review artifacts are outside the Task 10 commit. No Task 10 implementation file is currently modified or untracked.
