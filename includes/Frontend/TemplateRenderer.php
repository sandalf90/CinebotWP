<?php
/**
 * Template renderer with theme override support.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Frontend;

use Throwable;

/**
 * Renders PHP templates with safe output buffering and theme override lookup.
 */
final class TemplateRenderer {
	/**
	 * Render a template with context.
	 *
	 * @param string $template Template name without .php.
	 * @param array  $context  Variables for the template.
	 * @return string Rendered HTML.
	 */
	public function render( string $template, array $context ): string {
		$path = $this->resolve( $template );
		if ( null === $path ) {
			return '';
		}

		ob_start();
		try {
			// phpcs:ignore WordPress.Files.DirectFileAccess -- trusted plugin template.
			require $path;
			return (string) ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			throw $e;
		}
	}

	/**
	 * Resolve template path: theme override first, then plugin.
	 */
	private function resolve( string $template ): ?string {
		$theme_path = get_stylesheet_directory() . '/cinebot-wp/' . $template . '.php';
		if ( file_exists( $theme_path ) ) {
			return $theme_path;
		}

		$plugin_path = CINEBOT_WP_PATH . 'templates/' . $template . '.php';
		if ( file_exists( $plugin_path ) ) {
			return $plugin_path;
		}

		return null;
	}
}
