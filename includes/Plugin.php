<?php
/**
 * Main plugin coordinator.
 *
 * @package CinebotWp
 */

namespace CinebotWp;

use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Services\CronScheduler;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;

final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Whether the plugin has booted.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Return the singleton plugin instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Install the plugin database schema on activation.
	 */
	public static function activate(): void {
		global $wpdb;

		( new SchemaInstaller( $wpdb ) )->install();
		$scheduler = self::scheduler();
		$scheduler->register();
		$scheduler->schedule();
	}

	/**
	 * Stop scheduled synchronization on deactivation.
	 */
	public static function deactivate(): void {
		self::scheduler()->clear();
	}

	/**
	 * Boot the plugin once.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		self::scheduler()->register();
		do_action( 'cinebot_wp_booted' );
	}

	/** Compose the synchronization scheduler at the plugin boundary. */
	private static function scheduler(): CronScheduler {
		global $wpdb;

		return new CronScheduler( new SettingsService(), new SyncService( $wpdb ) );
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}
}
