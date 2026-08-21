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
use CinebotWp\Admin\Pages\LocaliListPage;
use CinebotWp\Admin\Pages\LocaleEditPage;
use CinebotWp\Admin\Pages\SyncLogPage;
use CinebotWp\Admin\Pages\TipologieListPage;
use CinebotWp\Admin\Pages\TipologiaEditPage;
use CinebotWp\Admin\Pages\TitoloEditPage;
use CinebotWp\Admin\Pages\TitoliListPage;
use CinebotWp\Database\SchemaInstaller;
use CinebotWp\Frontend\ShortcodeHandler;
use CinebotWp\Frontend\TemplateRenderer;
use CinebotWp\Repositories\EventoRepository;
use CinebotWp\Repositories\LocaleRepository;

use CinebotWp\Repositories\SyncLogRepository;
use CinebotWp\Repositories\TipologiaRepository;
use CinebotWp\Repositories\TitoloRepository;
use CinebotWp\Services\ApiClient;
use CinebotWp\Services\CronScheduler;
use CinebotWp\Services\SettingsService;
use CinebotWp\Services\SyncService;
use Throwable;

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
	 * Boot the plugin once, after confirming its schema is current.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		if ( ! $this->upgrade_schema() ) {
			return;
		}

		self::scheduler()->register();
		self::admin_menu()->register();
		self::shortcodes()->register();
		do_action( 'cinebot_wp_booted' );
	}

	/** Upgrade the schema or contain the failure to this plugin's boot. */
	private function upgrade_schema(): bool {
		global $wpdb;

		try {
			( new SchemaInstaller( $wpdb ) )->upgradeIfNeeded();
			return true;
		} catch ( Throwable $ignored ) {
			add_action( 'admin_notices', array( $this, 'render_schema_upgrade_error' ) );
			return false;
		}
	}

	/** Render a safe upgrade failure notice to administrators only. */
	public function render_schema_upgrade_error(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'Cinebot WP could not update its database. The plugin will retry automatically on the next request.', 'cinebot-wp' )
			. '</p></div>';
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

		$titoli_repo  = new TitoloRepository( $wpdb );
		$locale_repo  = new LocaleRepository( $wpdb );
		$tipo_repo    = new TipologiaRepository( $wpdb );

		$evento_repo  = new EventoRepository( $wpdb );

		$titoli_page = new TitoliListPage(
			$titoli_repo,
			$evento_repo,
			$tipo_repo
		);

		$edit_page = new TitoloEditPage(
			$titoli_repo,
			$evento_repo,
			$tipo_repo,
			$locale_repo
		);

		$locali_page = new LocaliListPage( $locale_repo, $titoli_repo, $evento_repo );
		$locale_edit = new LocaleEditPage( $locale_repo );
		$tipologie_page = new TipologieListPage( $tipo_repo );
		$tipologia_edit = new TipologiaEditPage( $tipo_repo );
		$log_repo = new SyncLogRepository( $wpdb );
		$log_page = new SyncLogPage( $log_repo );

		$dashboard = new DashboardPage( $settings, $titoli_repo, $log_repo );

		return new AdminMenu(
			$dashboard,
			new ApiPage( $settings, $scheduler, new SyncService( $wpdb, $api ) ),
			$titoli_page,
			$edit_page,
			$locali_page,
			$locale_edit,
			$tipologie_page,
			$tipologia_edit,
			$log_page
		);
	}

	/** Compose the frontend shortcodes at the plugin boundary. */
	private static function shortcodes(): ShortcodeHandler {
		global $wpdb;

		return new ShortcodeHandler(
			new TitoloRepository( $wpdb ),
			new TemplateRenderer(),
			new EventoRepository( $wpdb ),
			new SettingsService()
		);
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}
}
