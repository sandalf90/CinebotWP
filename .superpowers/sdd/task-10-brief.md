# Task 10 Brief — WP-Cron scheduler

Implement only Task 10. Read `CONVENTIONS.md`, SettingsService, SyncService, plan Task 10, and existing Plugin lifecycle. Create `includes/Services/CronScheduler.php` and `tests/Integration/CronSchedulerTest.php`; modify only composition/plan text if required by this task.

Interfaces: `register(): void`, `schedule(): void`, `reschedule(array $old, array $new): void`, `clear(): void`; hook `cinebot_wp_sync_event`; custom schedule key `cinebot_weekly` with `WEEK_IN_SECONDS`.

Requirements: inject SettingsService and SyncService plus optional scheduling function callables if needed for testability. `register()` adds cron_schedules filter, sync action, and `update_option_cinebot_wp_settings` (3 args). Disabled settings clear event. Enabled creates exactly one selected schedule among `hourly|twicedaily|daily|weekly`; unknown becomes daily. Reschedule only on enabled/frequency change, always clears before scheduling, never duplicates. Cron action calls `SyncService::sync()` and contains thrown errors safely so WP cron does not fatal or leak. Lifecycle `Plugin::activate()` schedules only if enabled after schema install; `deactivate()` clears. No data deletion.

TDD: tests weekly interval, disabled no schedule, each recurrence/one event, change replacement/no duplicate, disable clear, update-option behavior, cron invokes sync once and catches throwable, activation/deactivation behavior. Attempt Docker commands, then static checks. Report task-10-report; commit `feat: schedule cinebot synchronization`; no coordinator/review artifacts staged.
