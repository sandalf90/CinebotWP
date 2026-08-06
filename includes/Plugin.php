<?php
/**
 * Main plugin coordinator.
 *
 * @package CinebotWp
 */

namespace CinebotWp;

use CinebotWp\Admin\AdminMenu;
use CinebotWp\Admin\Pages\ApiPage;
use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Admin\Pages\TitoloEditPage;
use CinebotWp\Admin\Pages\TitoliListPage;
use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;
use CinebotWp\Repositories\PrezzoRepository;
use CinebotWp\Repositories\SettoreRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\ApiClient;
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
		self::admin_menu()->register();
		do_action( 'cinebot_wp_booted' );
	}

	/** Compose the synchronization scheduler at the plugin boundary. */
	private static function scheduler(): CronScheduler {
		global $wpdb;

		$settings = new SettingsService();
		return new CronScheduler( $settings, new SyncService( $wpdb, new ApiClient( $settings ) ) );
	}

	/** Compose the admin menu at the plugin boundary. */
	private static function admin_menu(): AdminMenu {
		global $wpdb;

		$settings  = new SettingsService();
		$api       = new ApiClient( $settings );
		$scheduler = new CronScheduler( $settings, new SyncService( $wpdb, $api ) );

		$titoli_page = new TitoliListPage(
			new TitoloRepository( $wpdb ),
			new EventoRepository( $wpdb ),
			new SettoreRepository( $wpdb ),
			new PrezzoRepository( $wpdb ),
			new TipologiaRepository( $wpdb )
		);

		$edit_page = new TitoloEditPage(
			new TitoloRepository( $wpdb ),
			new EventoRepository( $wpdb ),
			new SettoreRepository( $wpdb ),
			new PrezzoRepository( $wpdb ),
			new TipologiaRepository( $wpdb ),
			new LocaleRepository( $wpdb )
		);

		return new AdminMenu(
			new DashboardPage( $settings ),
			new ApiPage( $settings, $scheduler, new SyncService( $wpdb, $api ) ),
			$titoli_page,
			$edit_page
		);
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}
}
