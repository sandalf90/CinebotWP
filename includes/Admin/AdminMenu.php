<?php
/**
 * Admin menu registration.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Admin;

use CinebotWp\Admin\Pages\ApiPage;
use CinebotWp\Admin\Pages\DashboardPage;

/**
 * Registers the Cinebot admin menu and page routes.
 */
final class AdminMenu {
	/** @var DashboardPage */
	private $dashboard;

	/** @var ApiPage */
	private $api_page;

	/**
	 * Store the page collaborators.
	 */
	public function __construct( DashboardPage $dashboard, ApiPage $api_page ) {
		$this->dashboard = $dashboard;
		$this->api_page  = $api_page;
	}

	/** Register the top-level menu, API submenu, and AJAX handlers. */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_cinebot_wp_save_api', array( $this->api_page, 'save' ) );
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
