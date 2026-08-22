<?php
/**
 * Plugin update checker integration.
 *
 * @package CinebotWp
 */

namespace CinebotWp;

/**
 * Initialises the plugin-update-checker library to poll GitHub Releases
 * for new versions of the plugin.
 */
final class Updater {
	/**
	 * GitHub repository URL.
	 */
	private const REPO_URL = 'https://github.com/sandalf90/CinebotWP';

	/**
	 * Boot the update checker if the library is available.
	 */
	public static function init(): void {
		if ( ! class_exists( '\Puc_v4_Factory' ) ) {
			$autoload = CINEBOT_WP_PATH . 'vendor/autoload.php';
			if ( ! is_file( $autoload ) ) {
				return;
			}
			require $autoload;
		}

		if ( ! class_exists( '\Puc_v4_Factory' ) ) {
			return;
		}

		$checker = \Puc_v4_Factory::buildUpdateChecker(
			self::REPO_URL,
			CINEBOT_WP_FILE,
			'cinebot-wp'
		);

		$checker->getVcsApi()->enableReleaseAssetsFilter();
	}
}

