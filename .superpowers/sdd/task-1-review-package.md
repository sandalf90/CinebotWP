# Task 1 Reviewer Handoff Package

## Scope

Complete Task 1 range from the empty repository through the foundation hardening and final WPCS alignment fixes. The current `.superpowers/sdd/task-1-report.md` records `DONE_WITH_CONCERNS`: all review findings and static WPCS alignment checks were addressed, but PHPUnit, WPCS, PHPStan, and the actual ZIP build remain unexecuted because neither a working Docker daemon nor native PHP/Composer is available.

## Commit List

```text
commit cdc8021815d273dc0013fca07106f95ade8b0e05
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 18:01:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 18:01:47 2026 +0200

    chore: bootstrap cinebot wordpress plugin

commit 203ceacd2f735c9579539694c7207c89d326f731
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:20:15 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:20:15 2026 +0200

    fix: harden plugin foundation

commit 1aaf9f510b52a641ba51f049203e425dea299ac0
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:27:51 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:27:51 2026 +0200

    fix: align bootstrap with wordpress standards
```

`cdc8021815d273dc0013fca07106f95ade8b0e05` is the repository root commit. `203ceacd2f735c9579539694c7207c89d326f731` hardens the foundation. `1aaf9f510b52a641ba51f049203e425dea299ac0` completes WPCS alignment.

## Complete Stat

Command: `git show --stat --format=fuller cdc8021815d273dc0013fca07106f95ade8b0e05 203ceacd2f735c9579539694c7207c89d326f731 1aaf9f510b52a641ba51f049203e425dea299ac0`

```text
commit cdc8021815d273dc0013fca07106f95ade8b0e05
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 18:01:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 18:01:47 2026 +0200

    chore: bootstrap cinebot wordpress plugin

 .gitignore                                         |    4 +
 .superpowers/sdd/task-1-brief.md                   |   57 +
 AGENTS.md                                          |    5 +
 CLAUDE.md                                          |   22 +
 CONVENTIONS.md                                     |   53 +
 cinebot-wp.php                                     |   21 +
 compose.yaml                                       |   34 +
 composer.json                                      |   45 +
 docker/php/Dockerfile                              |    8 +
 docker/prepare-tests.sh                            |   20 +
 docker/run-tests.sh                                |   12 +
 .../plans/2026-08-02-cinebot-wp-plugin.md          | 1443 ++++++++++++++++++++
 .../specs/2026-08-02-cinebot-wp-plugin-design.md   |  717 ++++++++++
 includes/Plugin.php                                |   52 +
 includes/autoload.php                              |   26 +
 phpcs.xml.dist                                     |   14 +
 phpstan.neon.dist                                  |   14 +
 phpunit.xml.dist                                   |   20 +
 specs/PLAN-AUDIT.md                                |   74 +
 specs/README.md                                    |    3 +
 specs/bugs/registry.yaml                           |    1 +
 specs/epics/archive/.gitkeep                       |    0
 specs/execution-status.yaml                        |    3 +
 specs/planning-status.yaml                         |    4 +
 specs/product/GLOSSARY_LATEST.yaml                 |   10 +
 specs/product/SCOPE_LATEST.yaml                    |   13 +
 specs/product/VISION_LATEST.yaml                   |    6 +
 specs/product/snapshots/.gitkeep                   |    0
 specs/release-plan.yaml                            |    4 +
 specs/state.yaml                                   |    9 +
 specs/tech-architecture/IMPACT_LATEST.md           |    3 +
 specs/tech-architecture/REFACTOR_LATEST.md         |    3 +
 specs/tech-architecture/design.md                  |    3 +
 specs/tech-architecture/security.md                |    8 +
 specs/tech-architecture/tech-stack.md              |    8 +
 specs/tech-architecture/test.md                    |    7 +
 specs/verifications/.gitkeep                       |    0
 tests/Integration/PluginBootstrapTest.php          |   21 +
 tests/bootstrap.php                                |   12 +
 tests/wp-tests-config.php                          |   14 +
 tools/build.php                                    |   62 +
 41 files changed, 2835 insertions(+)

commit 203ceacd2f735c9579539694c7207c89d326f731
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:20:15 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:20:15 2026 +0200

    fix: harden plugin foundation

 .superpowers/sdd/task-1-report.md                  | 152 +++++++++++++++
 cinebot-wp.php                                     |   8 +-
 .../plans/2026-08-02-cinebot-wp-plugin.md          | 204 ++++++++++++++++-----
 includes/Plugin.php                                |   5 +-
 includes/autoload.php                              |  12 +-
 phpstan.neon.dist                                  |   2 -
 tests/Integration/PluginBootstrapTest.php          |  93 ++++++++--
 tests/bootstrap.php                                |  27 ++-
 tests/wp-tests-config.php                          |  30 +--
 tools/build.php                                    |  77 +++++---
 10 files changed, 489 insertions(+), 121 deletions(-)

commit 1aaf9f510b52a641ba51f049203e425dea299ac0
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:27:51 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:27:51 2026 +0200

    fix: align bootstrap with wordpress standards

 .superpowers/sdd/task-1-report.md                  | 22 ++++++++++++++++++++++
 .../plans/2026-08-02-cinebot-wp-plugin.md          | 16 +++++++++++++++-
 phpcs.xml.dist                                     |  7 ++++++-
 tests/Integration/PluginBootstrapTest.php          |  9 +++++++++
 4 files changed, 52 insertions(+), 2 deletions(-)
```

Documentation, conventions, lifecycle specifications, the updated report, and the approved plan are represented completely by path and stat above. Their content is omitted from the implementation diff to keep the review focused.

## Complete Relevant Implementation Diff

This cumulative diff compares the empty tree with the final Task 1 commit and therefore shows the final form of every Task 1 implementation file across all three commits. The command requests 10 context lines; because all implementation files are additions relative to the empty tree, every file is included in full.

Command: `git diff --unified=10 4b825dc642cb6eb9a060e54bf8d69288fbee4904 1aaf9f510b52a641ba51f049203e425dea299ac0 -- .gitignore composer.json cinebot-wp.php includes/autoload.php includes/Plugin.php compose.yaml docker/php/Dockerfile docker/prepare-tests.sh docker/run-tests.sh phpunit.xml.dist phpcs.xml.dist phpstan.neon.dist tests/bootstrap.php tests/wp-tests-config.php tests/Integration/PluginBootstrapTest.php tools/build.php`

```diff
diff --git a/.gitignore b/.gitignore
new file mode 100644
index 0000000..6be758a
--- /dev/null
+++ b/.gitignore
@@ -0,0 +1,4 @@
+/vendor/
+/dist/
+/.phpunit.result.cache
+/.docker-cache/
diff --git a/cinebot-wp.php b/cinebot-wp.php
new file mode 100644
index 0000000..8e3f890
--- /dev/null
+++ b/cinebot-wp.php
@@ -0,0 +1,21 @@
+<?php
+/**
+ * Plugin Name: Cinebot WP
+ * Description: Cinebot schedule synchronization for WordPress.
+ * Version: 1.0.0
+ * Requires at least: 6.0
+ * Requires PHP: 7.4
+ * Text Domain: cinebot-wp
+ * License: GPL-2.0-or-later
+ *
+ * @package CinebotWp
+ */
+
+define( 'CINEBOT_WP_VERSION', '1.0.0' );
+define( 'CINEBOT_WP_FILE', __FILE__ );
+define( 'CINEBOT_WP_PATH', plugin_dir_path( __FILE__ ) );
+define( 'CINEBOT_WP_URL', plugin_dir_url( __FILE__ ) );
+
+require CINEBOT_WP_PATH . 'includes/autoload.php';
+
+CinebotWp\Plugin::instance()->boot();
diff --git a/compose.yaml b/compose.yaml
new file mode 100644
index 0000000..755f7b4
--- /dev/null
+++ b/compose.yaml
@@ -0,0 +1,34 @@
+services:
+  db:
+    image: mysql:8.0
+    environment:
+      MYSQL_DATABASE: wordpress_test
+      MYSQL_ROOT_PASSWORD: root
+    healthcheck:
+      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-proot"]
+      interval: 2s
+      timeout: 2s
+      retries: 30
+  php:
+    build:
+      context: .
+      dockerfile: docker/php/Dockerfile
+      args:
+        PHP_VERSION: ${PHP_VERSION:-7.4}
+    working_dir: /plugin
+    volumes:
+      - ./:/plugin
+      - wordpress_tests:/tmp/wordpress-develop
+    depends_on:
+      db:
+        condition: service_healthy
+    environment:
+      WP_VERSION: ${WP_VERSION:-6.0.12}
+      WP_CORE_DIR: /tmp/wordpress-develop/src
+      WP_TESTS_DIR: /tmp/wordpress-develop/tests/phpunit
+      WP_TESTS_DB_NAME: wordpress_test
+      WP_TESTS_DB_USER: root
+      WP_TESTS_DB_PASSWORD: root
+      WP_TESTS_DB_HOST: db
+volumes:
+  wordpress_tests:
diff --git a/composer.json b/composer.json
new file mode 100644
index 0000000..6e8cf10
--- /dev/null
+++ b/composer.json
@@ -0,0 +1,45 @@
+{
+  "name": "cinebot/cinebot-wp",
+  "description": "Cinebot schedule synchronization for WordPress",
+  "type": "wordpress-plugin",
+  "license": "GPL-2.0-or-later",
+  "require": {
+    "php": ">=7.4"
+  },
+  "require-dev": {
+    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
+    "phpstan/extension-installer": "^1.4",
+    "phpstan/phpstan": "^2.1",
+    "phpunit/phpunit": "^9.6",
+    "szepeviktor/phpstan-wordpress": "^2.0",
+    "wp-coding-standards/wpcs": "^3.0",
+    "yoast/phpunit-polyfills": "^2.0"
+  },
+  "autoload": {
+    "psr-4": {
+      "CinebotWp\\": "includes/"
+    }
+  },
+  "autoload-dev": {
+    "psr-4": {
+      "CinebotWp\\Tests\\": "tests/"
+    }
+  },
+  "config": {
+    "allow-plugins": {
+      "dealerdirect/phpcodesniffer-composer-installer": true,
+      "phpstan/extension-installer": true
+    }
+  },
+  "scripts": {
+    "prepare-tests": "bash docker/prepare-tests.sh",
+    "test": "bash docker/run-tests.sh all",
+    "test:unit": "bash docker/run-tests.sh unit",
+    "test:integration": "bash docker/run-tests.sh integration",
+    "lint": "phpcs --standard=phpcs.xml.dist",
+    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
+    "analyse": "phpstan analyse -c phpstan.neon.dist --no-progress",
+    "build": "php tools/build.php",
+    "check": ["@lint", "@analyse", "@test", "@build"]
+  }
+}
diff --git a/docker/php/Dockerfile b/docker/php/Dockerfile
new file mode 100644
index 0000000..247a310
--- /dev/null
+++ b/docker/php/Dockerfile
@@ -0,0 +1,8 @@
+ARG PHP_VERSION=7.4
+FROM php:${PHP_VERSION}-cli
+RUN apt-get update \
+ && apt-get install -y --no-install-recommends git unzip zip libzip-dev default-mysql-client \
+ && docker-php-ext-install mysqli zip \
+ && rm -rf /var/lib/apt/lists/*
+COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
+WORKDIR /plugin
diff --git a/docker/prepare-tests.sh b/docker/prepare-tests.sh
new file mode 100755
index 0000000..7705fd8
--- /dev/null
+++ b/docker/prepare-tests.sh
@@ -0,0 +1,20 @@
+#!/bin/sh
+set -eu
+
+target=/tmp/wordpress-develop
+version="${WP_VERSION:-6.0.12}"
+current=""
+
+if [ -f "$target/.cinebot-wp-version" ]; then
+    current="$(cat "$target/.cinebot-wp-version")"
+fi
+
+if [ "$current" != "$version" ]; then
+    mkdir -p "$target"
+    find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
+    git clone --depth 1 --branch "$version" \
+        https://github.com/WordPress/wordpress-develop.git "$target"
+    printf '%s' "$version" > "$target/.cinebot-wp-version"
+fi
+
+test -f "$target/tests/phpunit/includes/bootstrap.php"
diff --git a/docker/run-tests.sh b/docker/run-tests.sh
new file mode 100755
index 0000000..46e5045
--- /dev/null
+++ b/docker/run-tests.sh
@@ -0,0 +1,12 @@
+#!/bin/sh
+set -eu
+
+suite="${1:-all}"
+shift || true
+bash docker/prepare-tests.sh
+
+if [ "$suite" = "all" ]; then
+    exec vendor/bin/phpunit -c phpunit.xml.dist "$@"
+fi
+
+exec vendor/bin/phpunit -c phpunit.xml.dist --testsuite "$suite" "$@"
diff --git a/includes/Plugin.php b/includes/Plugin.php
new file mode 100644
index 0000000..e23540f
--- /dev/null
+++ b/includes/Plugin.php
@@ -0,0 +1,53 @@
+<?php
+/**
+ * Main plugin coordinator.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp;
+
+final class Plugin {
+	/**
+	 * Singleton instance.
+	 *
+	 * @var self|null
+	 */
+	private static $instance;
+
+	/**
+	 * Whether the plugin has booted.
+	 *
+	 * @var bool
+	 */
+	private $booted = false;
+
+	/**
+	 * Return the singleton plugin instance.
+	 */
+	public static function instance(): self {
+		if ( null === self::$instance ) {
+			self::$instance = new self();
+		}
+
+		return self::$instance;
+	}
+
+	/**
+	 * Boot the plugin once.
+	 */
+	public function boot(): void {
+		if ( $this->booted ) {
+			return;
+		}
+
+		$this->booted = true;
+		do_action( 'cinebot_wp_booted' );
+	}
+
+	/**
+	 * Prevent direct construction.
+	 */
+	private function __construct() {
+	}
+}
diff --git a/includes/autoload.php b/includes/autoload.php
new file mode 100644
index 0000000..1861f9b
--- /dev/null
+++ b/includes/autoload.php
@@ -0,0 +1,26 @@
+<?php
+/**
+ * Runtime class autoloader.
+ *
+ * @package CinebotWp
+ */
+
+spl_autoload_register(
+	static function ( string $class ): void {
+		$prefix = 'CinebotWp\\';
+
+		if ( 0 !== strpos( $class, $prefix ) ) {
+			return;
+		}
+
+		$relative_class = substr( $class, strlen( $prefix ) );
+		if ( false === $relative_class || false !== strpos( $relative_class, '..' ) ) {
+			return;
+		}
+
+		$file = __DIR__ . '/' . str_replace( '\\', '/', $relative_class ) . '.php';
+		if ( is_file( $file ) ) {
+			require $file;
+		}
+	}
+);
diff --git a/phpcs.xml.dist b/phpcs.xml.dist
new file mode 100644
index 0000000..9a798b5
--- /dev/null
+++ b/phpcs.xml.dist
@@ -0,0 +1,19 @@
+<?xml version="1.0"?>
+<ruleset name="Cinebot WP">
+    <description>Cinebot WP coding standards.</description>
+    <file>cinebot-wp.php</file>
+    <file>includes</file>
+    <file>templates</file>
+    <file>tests</file>
+    <file>uninstall.php</file>
+    <exclude-pattern>*/vendor/*</exclude-pattern>
+    <exclude-pattern>*/dist/*</exclude-pattern>
+    <arg value="ps"/>
+    <config name="minimum_supported_wp_version" value="6.0"/>
+    <rule ref="WordPress">
+        <exclude name="WordPress.Files.FileName"/>
+    </rule>
+    <rule ref="WordPress.Files.FileName">
+        <exclude-pattern type="relative">^includes/</exclude-pattern>
+    </rule>
+</ruleset>
diff --git a/phpstan.neon.dist b/phpstan.neon.dist
new file mode 100644
index 0000000..79e19a6
--- /dev/null
+++ b/phpstan.neon.dist
@@ -0,0 +1,12 @@
+includes:
+    - vendor/szepeviktor/phpstan-wordpress/extension.neon
+
+parameters:
+    level: 6
+    phpVersion: 70400
+    paths:
+        - cinebot-wp.php
+        - includes
+    excludePaths:
+        - vendor
+        - dist
diff --git a/phpunit.xml.dist b/phpunit.xml.dist
new file mode 100644
index 0000000..7f45393
--- /dev/null
+++ b/phpunit.xml.dist
@@ -0,0 +1,20 @@
+<?xml version="1.0" encoding="UTF-8"?>
+<phpunit bootstrap="tests/bootstrap.php"
+         colors="true"
+         failOnRisky="true"
+         failOnWarning="true"
+         beStrictAboutOutputDuringTests="true">
+    <testsuites>
+        <testsuite name="unit">
+            <directory suffix="Test.php">tests/Unit</directory>
+        </testsuite>
+        <testsuite name="integration">
+            <directory suffix="Test.php">tests/Integration</directory>
+        </testsuite>
+    </testsuites>
+    <coverage processUncoveredFiles="true">
+        <include>
+            <directory suffix=".php">includes</directory>
+        </include>
+    </coverage>
+</phpunit>
diff --git a/tests/Integration/PluginBootstrapTest.php b/tests/Integration/PluginBootstrapTest.php
new file mode 100644
index 0000000..4b32151
--- /dev/null
+++ b/tests/Integration/PluginBootstrapTest.php
@@ -0,0 +1,95 @@
+<?php
+/**
+ * Plugin foundation integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+use CinebotWp\Plugin;
+use ReflectionClass;
+use WP_UnitTestCase;
+use ZipArchive;
+
+/**
+ * Verifies the executable plugin foundation.
+ */
+final class PluginBootstrapTest extends WP_UnitTestCase {
+	/**
+	 * Verifies that plugin boot is idempotent.
+	 */
+	public function test_plugin_bootstraps_once(): void {
+		$boot_count = 0;
+		$observer   = static function () use ( &$boot_count ): void {
+			++$boot_count;
+		};
+
+		add_action( 'cinebot_wp_booted', $observer );
+
+		try {
+			$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
+			$plugin->boot();
+			$plugin->boot();
+		} finally {
+			remove_action( 'cinebot_wp_booted', $observer );
+		}
+
+		self::assertSame( 1, $boot_count );
+		self::assertSame( Plugin::instance(), Plugin::instance() );
+		self::assertTrue( defined( 'CINEBOT_WP_VERSION' ) );
+		self::assertSame( '1.0.0', CINEBOT_WP_VERSION );
+	}
+
+	/**
+	 * Verifies that runtime loading does not depend on Composer.
+	 */
+	public function test_runtime_does_not_require_composer_vendor_directory(): void {
+		self::assertFileExists( CINEBOT_WP_PATH . 'includes/autoload.php' );
+		// Direct access is appropriate for a local test fixture.
+		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
+		$entry_point = file_get_contents( CINEBOT_WP_FILE );
+		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
+		self::assertIsString( $entry_point );
+		self::assertStringNotContainsString( 'vendor/autoload.php', $entry_point );
+	}
+
+	/**
+	 * Verifies that the distribution contains only runtime files.
+	 */
+	public function test_distribution_contains_only_runtime_files(): void {
+		$archive_path = CINEBOT_WP_PATH . 'dist/cinebot-wp.zip';
+
+		ob_start();
+		try {
+			require CINEBOT_WP_PATH . 'tools/build.php';
+		} finally {
+			$build_output = ob_get_clean();
+		}
+
+		self::assertIsString( $build_output );
+		self::assertStringContainsString( $archive_path, $build_output );
+
+		$archive = new ZipArchive();
+		self::assertTrue( $archive->open( $archive_path ) );
+
+		try {
+			self::assertNotFalse( $archive->locateName( 'cinebot-wp/' ) );
+			self::assertNotFalse( $archive->locateName( 'cinebot-wp/cinebot-wp.php' ) );
+			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/autoload.php' ) );
+			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/Plugin.php' ) );
+
+			for ( $index = 0; $index < $archive->count(); ++$index ) {
+				$name = $archive->getNameIndex( $index );
+				self::assertIsString( $name );
+				self::assertStringStartsWith( 'cinebot-wp/', $name );
+				self::assertDoesNotMatchRegularExpression(
+					'#^cinebot-wp/(?:\.git|\.github|docker|docs|specs|tests|tools|vendor|dist)(?:/|$)#',
+					$name
+				);
+			}
+		} finally {
+			$archive->close();
+		}
+	}
+}
diff --git a/tests/bootstrap.php b/tests/bootstrap.php
new file mode 100644
index 0000000..cbc116a
--- /dev/null
+++ b/tests/bootstrap.php
@@ -0,0 +1,21 @@
+<?php
+/**
+ * Load the WordPress integration test environment.
+ *
+ * @package CinebotWp
+ */
+
+$plugin_root = dirname( __DIR__ );
+$tests_dir   = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-develop/tests/phpunit';
+
+require $plugin_root . '/vendor/autoload.php';
+putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );
+
+require $tests_dir . '/includes/functions.php';
+tests_add_filter(
+	'muplugins_loaded',
+	static function () use ( $plugin_root ): void {
+		require $plugin_root . '/cinebot-wp.php';
+	}
+);
+require $tests_dir . '/includes/bootstrap.php';
diff --git a/tests/wp-tests-config.php b/tests/wp-tests-config.php
new file mode 100644
index 0000000..649f5dc
--- /dev/null
+++ b/tests/wp-tests-config.php
@@ -0,0 +1,20 @@
+<?php
+/**
+ * WordPress integration test configuration.
+ *
+ * @package CinebotWp
+ */
+
+define( 'ABSPATH', rtrim( (string) getenv( 'WP_CORE_DIR' ), '/\\' ) . '/' );
+define( 'DB_NAME', (string) getenv( 'WP_TESTS_DB_NAME' ) );
+define( 'DB_USER', (string) getenv( 'WP_TESTS_DB_USER' ) );
+define( 'DB_PASSWORD', (string) getenv( 'WP_TESTS_DB_PASSWORD' ) );
+define( 'DB_HOST', (string) getenv( 'WP_TESTS_DB_HOST' ) );
+define( 'DB_CHARSET', 'utf8' );
+define( 'DB_COLLATE', '' );
+define( 'WP_TESTS_DOMAIN', 'example.org' );
+define( 'WP_TESTS_EMAIL', 'admin@example.org' );
+define( 'WP_TESTS_TITLE', 'Cinebot WP Tests' );
+define( 'WP_PHP_BINARY', 'php' );
+define( 'WP_DEBUG', true );
+$table_prefix = 'wptests_';
diff --git a/tools/build.php b/tools/build.php
new file mode 100644
index 0000000..3be7f9f
--- /dev/null
+++ b/tools/build.php
@@ -0,0 +1,95 @@
+<?php
+/**
+ * Build the installable plugin archive.
+ *
+ * @package CinebotWp
+ */
+
+$project_root = dirname( __DIR__ );
+$dist_dir     = $project_root . '/dist';
+$archive_path = $dist_dir . '/cinebot-wp.zip';
+$runtime      = array(
+	'cinebot-wp.php',
+	'uninstall.php',
+	'includes',
+	'assets',
+	'templates',
+	'languages',
+	'README.md',
+	'LICENSE',
+);
+
+if ( ! is_dir( $dist_dir ) && ! mkdir( $dist_dir, 0777, true ) && ! is_dir( $dist_dir ) ) {
+	throw new RuntimeException( 'Unable to create the distribution directory.' );
+}
+
+$archive = new ZipArchive();
+if ( true !== $archive->open( $archive_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
+	throw new RuntimeException( 'Unable to create the distribution archive.' );
+}
+
+$discard_archive = static function () use ( $archive, $archive_path ): bool {
+	$archive->close();
+
+	return ! is_file( $archive_path ) || unlink( $archive_path );
+};
+
+$add_file = static function ( string $source, string $destination ) use (
+	$archive,
+	$archive_path,
+	$discard_archive
+): void {
+	if ( $archive->addFile( $source, $destination ) ) {
+		return;
+	}
+
+	$cleanup_failed = ! $discard_archive();
+	$message        = sprintf( 'Unable to add runtime file to archive: %s', $source );
+	if ( $cleanup_failed ) {
+		$message .= sprintf( '; incomplete archive could not be removed: %s', $archive_path );
+	}
+
+	throw new RuntimeException( $message );
+};
+
+if ( ! $archive->addEmptyDir( 'cinebot-wp' ) ) {
+	$discard_archive();
+	$message = sprintf( 'Unable to add archive root for source: %s', $project_root );
+	throw new RuntimeException( $message );
+}
+
+foreach ( $runtime as $entry ) {
+	$source = $project_root . '/' . $entry;
+	if ( is_file( $source ) ) {
+		$add_file( $source, 'cinebot-wp/' . $entry );
+		continue;
+	}
+
+	if ( ! is_dir( $source ) ) {
+		continue;
+	}
+
+	$files = new RecursiveIteratorIterator(
+		new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
+		RecursiveIteratorIterator::LEAVES_ONLY
+	);
+
+	foreach ( $files as $file ) {
+		if ( ! $file->isFile() ) {
+			continue;
+		}
+
+		$relative_path = substr( $file->getPathname(), strlen( $project_root ) + 1 );
+		$relative_path = str_replace( '\\', '/', $relative_path );
+		$add_file( $file->getPathname(), 'cinebot-wp/' . $relative_path );
+	}
+}
+
+if ( ! $archive->close() ) {
+	if ( is_file( $archive_path ) ) {
+		unlink( $archive_path );
+	}
+	throw new RuntimeException( 'Unable to finalize the distribution archive.' );
+}
+
+echo $archive_path . PHP_EOL;
```

## Current Task Report

Source: `.superpowers/sdd/task-1-report.md` at `1aaf9f510b52a641ba51f049203e425dea299ac0`.

```markdown
# Task 1 Implementation Report

## Status

`DONE_WITH_CONCERNS`

The executable plugin and test foundation was implemented and committed. Docker, PHP, and Composer are not installed or available on the host, so the required PHPUnit, WPCS, PHPStan, dependency installation, and ZIP build gates could not execute.

## Files Created

- `.gitignore`
- `composer.json`
- `cinebot-wp.php`
- `includes/autoload.php`
- `includes/Plugin.php`
- `compose.yaml`
- `docker/php/Dockerfile`
- `docker/prepare-tests.sh`
- `docker/run-tests.sh`
- `phpunit.xml.dist`
- `phpcs.xml.dist`
- `phpstan.neon.dist`
- `tests/bootstrap.php`
- `tests/wp-tests-config.php`
- `tests/Integration/PluginBootstrapTest.php`
- `tools/build.php`
- `.superpowers/sdd/task-1-report.md` (coordination report, written after the implementation commit so it can record that commit's hash)

The existing approved `CLAUDE.md`, `AGENTS.md`, `CONVENTIONS.md`, `docs/`, `specs/`, and Task 1 brief were preserved in the initial repository commit.

## TDD Red

The configuration and `PluginBootstrapTest` were created before `cinebot-wp.php`, `includes/autoload.php`, `includes/Plugin.php`, and `tools/build.php`.

Command attempted:

~~~powershell
docker compose run --rm php composer test:integration -- --filter PluginBootstrapTest
~~~

Observed result: PowerShell failed before PHPUnit could start because `docker` was not recognized as a cmdlet, function, script, or executable (`CommandNotFoundException`). Thus the expected missing `CinebotWp\Plugin`/constants assertion failure could not be observed in this environment.

The preceding `docker compose build` attempt failed for the same reason.

## Green And Quality Results

| Command | Exact result |
|---|---|
| `docker compose run --rm php composer test:integration -- --filter PluginBootstrapTest` | Not executed: `docker` command not found (`CommandNotFoundException`). |
| `docker compose run --rm php composer lint` | Not executed: `docker` command not found (`CommandNotFoundException`). |
| `docker compose run --rm php composer analyse` | Not executed: `docker` command not found (`CommandNotFoundException`). |
| `docker compose run --rm php composer build` | Not executed: `docker` command not found (`CommandNotFoundException`); `dist/cinebot-wp.zip` was therefore not produced. |
| `php -v` | Native fallback unavailable: `php` command not found (`CommandNotFoundException`). |
| `composer --version` | Native fallback unavailable: `composer` command not found (`CommandNotFoundException`). |
| `Get-Content -Raw composer.json \| ConvertFrom-Json` | Passed with exit code 0 and no output. |
| Parse `phpunit.xml.dist` and `phpcs.xml.dist` as PowerShell `[xml]` | Passed with exit code 0 and no output. |
| `git diff --check` before staging | Passed with exit code 0 and no output; files were untracked at that point. |
| `git diff --cached --check` | Reported pre-existing trailing whitespace in nine lines of the approved design document. No Task 1 implementation file was identified. The approved document was preserved rather than modified. |

## Commit

`cdc8021815d273dc0013fca07106f95ade8b0e05` (`chore: bootstrap cinebot wordpress plugin`)

Repository initialization: `git init -b feat/cinebot-wp`.

The two shell scripts are committed with executable mode `100755`. Status, staged diff, staged diff summary/check, and the empty initial log were inspected before commit. Only the approved project documents and Task 1 foundation files were staged.

## Self-Review

- Scope is limited to the foundation; no schema, activation callback, deactivation callback, or later feature was added.
- Runtime loads committed `includes/autoload.php` and does not load Composer's autoloader.
- Runtime autoloading is restricted to `CinebotWp\`, rejects relative class paths containing `..`, and conditionally requires existing files.
- `Plugin::instance()` returns one final singleton instance; `boot()` returns early after its first invocation.
- Plugin metadata and constants declare version `1.0.0`, WordPress `6.0`, PHP `7.4`, and text domain `cinebot-wp`.
- Compose and test scripts use MySQL 8, parameterized PHP with default 7.4, and exact WordPress tag default `6.0.12`; the preparer validates the WordPress PHPUnit bootstrap.
- Composer dependencies, scripts, PHPUnit, PHPCS, PHPStan, WordPress test configuration, and bootstrap match Task 1's specified values.
- The build script uses an allowlist of runtime entries, creates a `cinebot-wp/` archive root, and cannot include development directories or `vendor/` through that allowlist.
- Shell scripts were committed executable, and no credential or secret file was staged.

## Concerns

- The mandatory dynamic gates remain unverified because Docker is unavailable; PHP and Composer are also unavailable as native fallbacks.
- The red phase was attempted in the required order, but the environment failure occurred before PHPUnit could demonstrate the expected missing implementation failure.
- No `composer.lock` exists because dependency installation could not run. The Task 1 file list does not require creating it manually.
- The approved design document contains nine existing trailing-whitespace findings. It was intentionally not altered.
- This report is uncommitted because it was created after the implementation commit to record that commit's immutable hash.

## Fix Review

### Status

`DONE_WITH_CONCERNS`

All Important and Minor findings in `.superpowers/sdd/task-1-review.md` were addressed. Dynamic gates were not run because the local Docker daemon, PHP, and Composer remain unavailable by user decision.

### Files Modified

- `phpstan.neon.dist`
- `cinebot-wp.php`
- `includes/autoload.php`
- `includes/Plugin.php`
- `tests/bootstrap.php`
- `tests/wp-tests-config.php`
- `tests/Integration/PluginBootstrapTest.php`
- `tools/build.php`
- `docs/superpowers/plans/2026-08-02-cinebot-wp-plugin.md`
- `.superpowers/sdd/task-1-report.md`

### Corrections

- Limited Task 1 PHPStan analysis paths to the existing `cinebot-wp.php` file and `includes/` directory.
- Corrected the Task 1 plan snippet and added explicit Task 2 and Task 16 instructions to add `uninstall.php` and `templates/` when those paths are created.
- Reformatted PHPCS-scanned Task 1 PHP and corresponding plan snippets with WPCS spacing, braces, tab indentation, and snake_case local variables. The one direct local source-file read has a narrow, documented `WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents` exclusion.
- Made idempotent boot behavior observable through one `cinebot_wp_booted` action after the boot guard is set.
- Added a behavioral boot test that invokes `boot()` twice on a fresh reflected `Plugin` and observes exactly one action.
- Added a distribution test that executes `tools/build.php`, opens the generated ZIP, verifies the top-level root and foundation runtime files, and rejects `vendor/` and development paths.
- Added an explicit ZIP root entry and checked the result of every `ZipArchive::addFile()` call. A rejected source closes and removes the incomplete archive before throwing an actionable exception containing the source path; cleanup failure is also reported.

### Covering Tests

- `PluginBootstrapTest::test_plugin_bootstraps_once()` covers singleton identity, constants, and the one-time observable boot action.
- `PluginBootstrapTest::test_runtime_does_not_require_composer_vendor_directory()` covers the committed runtime loader boundary.
- `PluginBootstrapTest::test_distribution_contains_only_runtime_files()` executes the builder and covers ZIP root, runtime entries, and development-path exclusions.

The focused red command was attempted after adding the new tests and before changing production behavior:

~~~powershell
docker compose run --rm php composer test:integration -- --filter PluginBootstrapTest
~~~

Observed result: Docker CLI reached Compose, but could not connect to `npipe:////./pipe/dockerDesktopLinuxEngine` because the Docker Desktop Linux engine pipe was absent. PHPUnit did not start, so an assertion-level red result could not be observed.

### Commands And Results

| Command/check | Result |
|---|---|
| Focused PHPUnit command above | Not run at PHPUnit level: Docker daemon unavailable. |
| PowerShell `ConvertFrom-Json` validation of `composer.json` | Passed, exit code 0, no output. |
| PowerShell `[xml]` parsing of `phpunit.xml.dist` and `phpcs.xml.dist` | Passed, exit code 0, no output. |
| PowerShell checks that each Task 1 PHPStan path exists and deferred paths are absent | Passed, exit code 0, no output. |
| Static camelCase local-variable scan across Task 1 PHP | Passed: no matches. |
| Static unspaced-control-structure scan across Task 1 PHP | Passed: no matches. |
| Static `ZipArchive::addFile()` call-site scan | Passed: the single call is conditionally checked. |
| Plan scan for deferred `uninstall.php` and `templates/` PHPStan instructions | Passed: both instructions found. |
| `git diff --check` | Passed with no whitespace errors; Git emitted only Windows LF-to-CRLF conversion warnings. |
| PHPUnit, WPCS, PHPStan, and distribution build | Not run by user decision because no working Docker daemon or native PHP/Composer is available. |

### Concerns

- The new and existing dynamic tests, WPCS, PHPStan, and actual ZIP build still require execution in CI or another environment with Docker.
- The TDD red command was issued in the correct order, but the environment stopped execution before PHPUnit could prove the expected behavioral failures.
- The original implementation commit remains `cdc8021815d273dc0013fca07106f95ade8b0e05`; review corrections are committed separately as required.

## Final WPCS Alignment Review

### Corrections

- Updated `phpcs.xml.dist` to exclude `WordPress.Files.FileName` from the parent `WordPress` ruleset, restore that sniff independently, and apply a relative exclusion only to `^includes/`. This preserves the required PSR-4 `includes/Plugin.php` filename while retaining the filename sniff everywhere else.
- Added concise WPCS-compatible docblocks to all three methods in `PluginBootstrapTest`.
- Mirrored the PHPCS ruleset and test method docblocks in the Task 1 literal plan snippets.

### Static Checks

| Check | Result |
|---|---|
| Parse `phpcs.xml.dist` and inspect its rules through PowerShell XML APIs | Passed: the parent rule excludes only `WordPress.Files.FileName`; the restored sniff has one relative `^includes/` exclusion. |
| Check the three expected method descriptions in `PluginBootstrapTest.php` | Passed: all three docblocks found. |
| Check the PHPCS exclusion and three method descriptions in the Task 1 plan | Passed: all mirrored literals found. |
| Scan test PHP for lines longer than 100 characters | Passed: no matches. |
| `git diff --check` | Passed with no whitespace errors; Git emitted only Windows LF-to-CRLF conversion warnings. |

### Concerns

- WPCS itself was not run because the accepted local Docker/PHP/Composer environment gap remains. CI or another equipped environment must execute the dynamic quality gate.
```

## Current Status

Command: `git status --short --branch --untracked-files=all`

```text
## feat/cinebot-wp
?? .superpowers/sdd/task-1-review-package.md
?? .superpowers/sdd/task-1-review.md
```

No implementation file is modified or uncommitted.
