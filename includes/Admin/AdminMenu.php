<?php
/**
 * Admin menu registration.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin;

use CinebotWp\Admin\Pages\ApiPage;
use CinebotWp\Admin\Pages\DashboardPage;
use CinebotWp\Admin\Pages\LocaliListPage;
use CinebotWp\Admin\Pages\LocaleEditPage;
use CinebotWp\Admin\Pages\SyncLogPage;
use CinebotWp\Admin\Pages\TipologieListPage;
use CinebotWp\Admin\Pages\TipologiaEditPage;
use CinebotWp\Admin\Pages\TitoloEditPage;
use CinebotWp\Admin\Pages\TitoliListPage;

/**
 * Registers the Cinebot admin menu and page routes.
 */
final class AdminMenu {
	/** Dashboard page instance.
	 *
	 * @var DashboardPage
	 */
	private $dashboard;

	/** API settings page instance.
	 *
	 * @var ApiPage
	 */
	private $api_page;

	/** Titles list page instance.
	 *
	 * @var TitoliListPage
	 */
	private $titoli_page;

	/** Title edit page instance.
	 *
	 * @var TitoloEditPage
	 */
	private $edit_page;

	/** Venues list page instance.
	 *
	 * @var LocaliListPage
	 */
	private $locali_page;

	/** Venue edit page instance.
	 *
	 * @var LocaleEditPage
	 */
	private $locale_edit;

	/** Event types list page instance.
	 *
	 * @var TipologieListPage
	 */
	private $tipologie_page;

	/** Event type edit page instance.
	 *
	 * @var TipologiaEditPage
	 */
	private $tipologia_edit;

	/** Sync log page instance.
	 *
	 * @var SyncLogPage
	 */
	private $log_page;

	/**
	 * Store the page collaborators.
	 *
	 * @param DashboardPage      $dashboard      Dashboard page.
	 * @param ApiPage            $api_page       API settings page.
	 * @param TitoliListPage     $titoli_page    Titles list page.
	 * @param TitoloEditPage     $edit_page      Title edit page.
	 * @param LocaliListPage     $locali_page    Venues list page.
	 * @param LocaleEditPage     $locale_edit    Venue edit page.
	 * @param TipologieListPage  $tipologie_page Event types list page.
	 * @param TipologiaEditPage  $tipologia_edit Event type edit page.
	 * @param SyncLogPage        $log_page       Sync log page.
	 */
	public function __construct(
		DashboardPage $dashboard,
		ApiPage $api_page,
		TitoliListPage $titoli_page,
		TitoloEditPage $edit_page,
		LocaliListPage $locali_page,
		LocaleEditPage $locale_edit,
		TipologieListPage $tipologie_page,
		TipologiaEditPage $tipologia_edit,
		SyncLogPage $log_page
	) {
		$this->dashboard      = $dashboard;
		$this->api_page       = $api_page;
		$this->titoli_page    = $titoli_page;
		$this->edit_page      = $edit_page;
		$this->locali_page    = $locali_page;
		$this->locale_edit    = $locale_edit;
		$this->tipologie_page = $tipologie_page;
		$this->tipologia_edit = $tipologia_edit;
		$this->log_page       = $log_page;
	}

	/** Register the top-level menu, API submenu, and AJAX handlers. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_cinebot_wp_save_api', array( $this->api_page, 'save' ) );
		add_action( 'admin_post_cinebot_wp_save_titolo', array( $this->edit_page, 'save' ) );
		add_action( 'admin_post_cinebot_wp_save_locale', array( $this->locale_edit, 'save' ) );
		add_action( 'admin_post_cinebot_wp_save_tipologia', array( $this->tipologia_edit, 'save' ) );
		add_action( 'admin_post_cinebot_toggle_tipologia', array( $this->tipologie_page, 'toggleActive' ) );
		add_action( 'admin_post_cinebot_cleanup_logs', array( $this->log_page, 'deleteOld' ) );
		add_action( 'wp_ajax_cinebot_wp_test_connection', array( $this->api_page, 'testConnection' ) );
		add_action( 'wp_ajax_cinebot_wp_sync_now', array( $this->api_page, 'syncNow' ) );
	}

	/** Add the top-level Cinebot menu with Dashboard and API submenus. */
	public function add_menu(): void {
		$capability = $this->capability();

		add_menu_page(
			__( 'Cinebot', 'cinebot-wp' ),
			__( 'Cinebot', 'cinebot-wp' ),
			$capability,
			'cinebot-wp',
			array( $this->dashboard, 'render' ),
			'dashicons-calendar-alt',
			26
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Dashboard', 'cinebot-wp' ),
			__( 'Dashboard', 'cinebot-wp' ),
			$capability,
			'cinebot-wp',
			array( $this->dashboard, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'API Settings', 'cinebot-wp' ),
			__( 'API', 'cinebot-wp' ),
			$capability,
			'cinebot-wp-api',
			array( $this->api_page, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Programmazioni', 'cinebot-wp' ),
			__( 'Programmazioni', 'cinebot-wp' ),
			$capability,
			'cinebot-wp-programmazioni',
			array( $this->titoli_page, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Edit Title', 'cinebot-wp' ),
			'',
			$capability,
			'cinebot-wp-programmazione-edit',
			array( $this->edit_page, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Locali', 'cinebot-wp' ),
			__( 'Locali', 'cinebot-wp' ),
			$capability,
			'cinebot-wp-locali',
			array( $this->locali_page, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Edit Locale', 'cinebot-wp' ),
			'',
			$capability,
			'cinebot-wp-locale-edit',
			array( $this->locale_edit, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Tipologie evento', 'cinebot-wp' ),
			__( 'Tipologie', 'cinebot-wp' ),
			$capability,
			'cinebot-wp-tipologie',
			array( $this->tipologie_page, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Edit Tipologia', 'cinebot-wp' ),
			'',
			$capability,
			'cinebot-wp-tipologia-edit',
			array( $this->tipologia_edit, 'render' )
		);

		add_submenu_page(
			'cinebot-wp',
			__( 'Log sincronizzazioni', 'cinebot-wp' ),
			__( 'Log', 'cinebot-wp' ),
			$capability,
			'cinebot-wp-log',
			array( $this->log_page, 'render' )
		);
	}

	/** Enqueue admin assets only on Cinebot screens. */
	public function enqueue_assets( string $hook ): void {
		if ( false === strpos( $hook, 'cinebot-wp' ) ) {
			return;
		}

		wp_enqueue_script(
			'cinebot-admin',
			plugins_url( 'assets/js/cinebot-admin.js', CINEBOT_WP_FILE ),
			array(),
			CINEBOT_WP_VERSION,
			true
		);

		wp_enqueue_style(
			'cinebot-admin',
			plugins_url( 'assets/css/cinebot-admin.css', CINEBOT_WP_FILE ),
			array(),
			CINEBOT_WP_VERSION
		);

		wp_localize_script(
			'cinebot-admin',
			'cinebotAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'cinebot_wp_admin' ),
				'i18n'    => array(
					'testing'  => __( 'Testing connection…', 'cinebot-wp' ),
					'syncing'  => __( 'Synchronizing…', 'cinebot-wp' ),
					'error'    => __( 'An error occurred. Please try again.', 'cinebot-wp' ),
					'success'  => __( 'Success', 'cinebot-wp' ),
				),
			)
		);
	}

	/** Return the filtered admin capability. */
	private function capability(): string {
		return (string) apply_filters( 'cinebot_wp_capability', 'manage_options' );
	}
}
