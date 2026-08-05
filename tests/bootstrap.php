<?php
$pluginRoot = dirname(__DIR__);
$testsDir = getenv('WP_TESTS_DIR') ?: '/tmp/wordpress-develop/tests/phpunit';

require $pluginRoot . '/vendor/autoload.php';
putenv('WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php');

require $testsDir . '/includes/functions.php';
tests_add_filter('muplugins_loaded', static function () use ($pluginRoot): void {
    require $pluginRoot . '/cinebot-wp.php';
});
require $testsDir . '/includes/bootstrap.php';
