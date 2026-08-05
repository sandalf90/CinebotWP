<?php
/**
 * Runtime class autoloader.
 *
 * @package CinebotWp
 */

spl_autoload_register(
	static function (string $class): void {
		$prefix = 'CinebotWp\\';

		if (0 !== strpos($class, $prefix)) {
			return;
		}

		$relativeClass = substr($class, strlen($prefix));
		if (false === $relativeClass || false !== strpos($relativeClass, '..')) {
			return;
		}

		$file = __DIR__ . '/' . str_replace('\\', '/', $relativeClass) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
);
