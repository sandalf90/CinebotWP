<?php
namespace CinebotWp\Tests\Integration;

use CinebotWp\Plugin;
use WP_UnitTestCase;

final class PluginBootstrapTest extends WP_UnitTestCase
{
    public function test_plugin_bootstraps_once(): void
    {
        self::assertSame(Plugin::instance(), Plugin::instance());
        self::assertTrue(defined('CINEBOT_WP_VERSION'));
        self::assertSame('1.0.0', CINEBOT_WP_VERSION);
    }

    public function test_runtime_does_not_require_composer_vendor_directory(): void
    {
        self::assertFileExists(CINEBOT_WP_PATH . 'includes/autoload.php');
        self::assertStringNotContainsString('vendor/autoload.php', file_get_contents(CINEBOT_WP_FILE));
    }
}
