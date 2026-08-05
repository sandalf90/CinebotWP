<?php
/**
 * Runtime class autoloader.
 *
 * @package CinebotWp
 */

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'CinebotWp\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		if ( false === $relative_class || false !== strpos( $relative_class, '..' ) ) {
			return;
		}

		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative_class ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
