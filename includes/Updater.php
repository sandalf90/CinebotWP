<?php
/**
 * Plugin update checker integration.
 *
 * @package CinebotWp
 */

namespace CinebotWp;

use YahnisElsts\PluginUpdateChecker\v5p7\Vcs\GitHubApi;

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
		if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			$autoload = CINEBOT_WP_PATH . 'vendor/autoload.php';
			if ( ! is_file( $autoload ) ) {
				return;
			}
			// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted vendor autoloader, mirrors WP core require.
			require $autoload;
		}

		if ( ! class_exists( \YahnisElsts\PluginUpdateChecker\v5\PucFactory::class ) ) {
			return;
		}

		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
			self::REPO_URL,
			CINEBOT_WP_FILE,
			'cinebot-wp'
		);

		$api = $checker->getVcsApi();
		if ( $api instanceof GitHubApi ) {
			$api->enableReleaseAssets();
		}
	}
}
