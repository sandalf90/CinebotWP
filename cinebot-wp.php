<?php
/**
 * Plugin Name: Cinebot WP
 * Description: Cinebot schedule synchronization for WordPress.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: cinebot-wp
 * License: GPL-2.0-or-later
 *
 * @package CinebotWp
 */

define('CINEBOT_WP_VERSION', '1.0.0');
define('CINEBOT_WP_FILE', __FILE__);
define('CINEBOT_WP_PATH', plugin_dir_path(__FILE__));
define('CINEBOT_WP_URL', plugin_dir_url(__FILE__));

require CINEBOT_WP_PATH . 'includes/autoload.php';

CinebotWp\Plugin::instance()->boot();
