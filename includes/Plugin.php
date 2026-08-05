<?php
/**
 * Main plugin coordinator.
 *
 * @package CinebotWp
 */

namespace CinebotWp;

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
		if (null === self::$instance) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot the plugin once.
	 */
	public function boot(): void {
		if ($this->booted) {
			return;
		}

		$this->booted = true;
	}

	/**
	 * Prevent direct construction.
	 */
	private function __construct() {
	}
}
