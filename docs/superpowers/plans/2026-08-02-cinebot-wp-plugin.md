# Cinebot WP Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a WordPress 6 plugin that imports Cinebot schedules into relational custom tables, supports complete admin management, and publishes filterable event cards through shortcodes.

**Architecture:** Use namespaced PHP classes with thin WordPress adapters around repositories and services. Store the imported hierarchy in seven custom tables; keep API records and manual records distinct through `source`, and render the frontend server-side with a small vanilla-JavaScript AJAX enhancement.

**Tech Stack:** PHP 7.4+, WordPress 6.0+, MySQL/MariaDB InnoDB through `$wpdb`, committed PSR-4 autoloader, Composer development tooling, Docker Compose, PHPUnit 9 with the WordPress core test suite, WPCS, PHPStan for WordPress, native WP-Cron, `WP_List_Table`, vanilla JavaScript, CSS.

## Global Constraints

- Minimum runtime: PHP 7.4 and WordPress 6.0.
- Plugin slug and text domain: `cinebot-wp`; PHP namespace: `CinebotWp\`.
- No production framework or JavaScript build step; production uses the committed `includes/autoload.php`, while Composer is development-only.
- All SQL table names use `$wpdb->prefix . 'cinebot_'`; all dynamic SQL uses `$wpdb->prepare()`.
- Sync is read-only toward Cinebot. Rows with `source=manual` are never changed by synchronization.
- API password is encrypted at rest and never rendered back to the browser.
- Admin mutations require `manage_options`, a nonce, validation, and output escaping.
- Public output is server-rendered and progressively enhanced with vanilla JavaScript, not jQuery or React.
- User-visible strings use WordPress internationalization functions with text domain `cinebot-wp`.
- Follow TDD: add one failing behavior test, observe failure, implement the minimum, then run the focused and full suites.
- WordPress Multisite, API write-back, React, Gutenberg blocks, calendar layouts, purchase links, automatic API retries, and circuit breakers are out of scope for version 1.0.
- API IDs are globally unique. Missing API hierarchy rows are deactivated and reactivated if they return; they are not deleted.
- Public schedules include only reconciliation-active events whose API `stato` equals `3`.
- Git workflow mode is `solo-git`; commits use Conventional Commits.

## Scope Boundaries

**In scope:** single-site activation; encrypted Cinebot settings; read-only scheduled synchronization; title/event/sector/price reconciliation; venue and event-type management; Dashboard and sync logs; public card/detail shortcodes; AJAX filters; Docker-based local tests; WPCS/PHPStan/PHPUnit CI.

**Out of scope:** Multisite/network activation, writing to Cinebot, React/Gutenberg, calendar layout, checkout/purchase links, automatic retry, circuit breaker, and non-WordPress consumers.

## Pre-flight Commands

- Setup image: `docker compose build`
- Setup dependencies: `docker compose run --rm php composer install`
- Test: `docker compose run --rm php composer test`
- Build: `docker compose run --rm php composer build`
- Lint: `docker compose run --rm php composer lint`
- Typecheck: `docker compose run --rm php composer analyse`
- Full gate: `docker compose run --rm php composer check`

## Vertical Delivery Gates

- **Gate A — Import administration (Tasks 1–11):** activate plugin, configure/test API, run one reconciliation, inspect Dashboard status, and pass `composer check`.
- **Gate B — Program management (Tasks 12–13):** list, create, edit, and atomically delete a complete manual hierarchy without affecting API ownership.
- **Gate C — Reference and monitoring (Tasks 14–15):** manage venues/types and inspect/retain sync history through independently registered pages.
- **Gate D — Public schedules (Tasks 16–17):** render state-3 active events without JavaScript, then filter/load more progressively with AJAX.
- **Gate E — Release (Tasks 18–19):** activate/deactivate cleanly across the supported matrix and build an installable ZIP.

---

## File Map

### Bootstrap and lifecycle

- `cinebot-wp.php`: plugin header, constants, committed autoload, activation/deactivation hooks, boot.
- `includes/autoload.php`: committed production PSR-4 loader; the plugin never requires `vendor/` at runtime.
- `composer.json`: development dependencies and executable quality/build scripts.
- `compose.yaml`, `docker/php/Dockerfile`, `docker/prepare-tests.sh`, `docker/run-tests.sh`: reproducible PHP/MySQL and WordPress test provisioning.
- `phpunit.xml.dist`: PHPUnit configuration.
- `phpcs.xml.dist`, `phpstan.neon.dist`: WPCS and PHPStan gates.
- `tests/bootstrap.php`: WordPress test-suite bootstrap and plugin loading.
- `tests/wp-tests-config.php`: environment-driven test database and WordPress core paths.
- `tools/build.php`: deterministic `dist/cinebot-wp.zip` builder excluding development files.
- `includes/Plugin.php`: composition root and hook registration.
- `includes/Database/SchemaInstaller.php`: schema creation, versioning, and default event-type seeding.
- `uninstall.php`: explicit data removal.

### Domain and persistence

- `includes/Models/*.php`: typed DTOs for title, event, sector, price, venue, event type, and sync result/log.
- `includes/ReadModels/ProgrammazioneCard.php`: named projection returned by public schedule queries.
- `includes/Repositories/TitoloRepository.php`: title CRUD and public schedule queries.
- `includes/Repositories/EventoRepository.php`: event CRUD and replacement of title children.
- `includes/Repositories/SettoreRepository.php`: sector CRUD and replacement of event children.
- `includes/Repositories/PrezzoRepository.php`: price CRUD and replacement of sector children.
- `includes/Repositories/LocaleRepository.php`: venue CRUD/upsert and filter values.
- `includes/Repositories/TipologiaRepository.php`: event-type CRUD, activation toggles, and seeding.
- `includes/Repositories/SyncLogRepository.php`: sync lifecycle, counters, history, and retention.

### Services

- `includes/Services/SettingsService.php`: validated settings and reversible credential encryption.
- `includes/Services/ApiClient.php`: BASIC-authenticated Cinebot GET and response validation.
- `includes/Services/ApiException.php`: explicit API failure type.
- `includes/Services/LocandinaService.php`: poster URL construction.
- `includes/Services/SyncService.php`: transactional payload import and cache invalidation.
- `includes/Services/SyncResult.php`: immutable sync outcome.
- `includes/Services/SyncLock.php`: atomic option-backed ownership lock with token and expiry.
- `includes/Services/CronScheduler.php`: weekly interval, schedule, reschedule, and cleanup.

### Admin

- `includes/Admin/AdminMenu.php`: top-level menu, submenus, assets, and page routing.
- `includes/Admin/Pages/DashboardPage.php`: status, counters, recent logs, manual sync.
- `includes/Admin/Pages/ApiPage.php`: settings, connection test, and manual sync endpoints.
- `includes/Admin/Pages/TitoliListPage.php`: paginated/searchable title table.
- `includes/Admin/Pages/TitoloEditPage.php`: hierarchical title/event/sector/price editor.
- `includes/Admin/Pages/LocaliListPage.php`, `LocaleEditPage.php`: venue management.
- `includes/Admin/Pages/TipologieListPage.php`, `TipologiaEditPage.php`: event-type management.
- `includes/Admin/Pages/SyncLogPage.php`: log history and retention actions.
- `assets/js/cinebot-admin.js`: dynamic nested editor controls using vanilla JavaScript.
- `assets/css/cinebot-admin.css`: scoped admin presentation.

### Frontend

- `includes/Frontend/ShortcodeHandler.php`: shortcode validation, querying, caching, AJAX.
- `includes/Frontend/TemplateRenderer.php`: theme override lookup and safe template rendering.
- `templates/programmazione-cards.php`, `titolo-card.php`, `dettaglio-titolo.php`: public markup.
- `assets/js/cinebot-frontend.js`: filters and load-more enhancement.
- `assets/css/cinebot-frontend.css`: responsive card styles and CSS variables.

### Tests and delivery

- `tests/fixtures/cinebot-sample.json`: approved API fixture.
- `tests/Integration/*Test.php`: schema, repositories, cron, admin, shortcode, lifecycle.
- `tests/Unit/*Test.php`: settings, API, poster URL, sync decisions.
- `.github/workflows/ci.yml`: PHP/WordPress compatibility matrix.
- `languages/cinebot-wp.pot`: translation template generated after implementation.

---

## Phase 1: Executable Foundation

### Task 1: Bootstrap the plugin and test harness

**Files:**
- Create: `composer.json`
- Create: `cinebot-wp.php`
- Create: `includes/autoload.php`
- Create: `includes/Plugin.php`
- Create: `compose.yaml`
- Create: `docker/php/Dockerfile`
- Create: `docker/prepare-tests.sh`
- Create: `docker/run-tests.sh`
- Create: `phpunit.xml.dist`
- Create: `phpcs.xml.dist`
- Create: `phpstan.neon.dist`
- Create: `tests/bootstrap.php`
- Create: `tests/wp-tests-config.php`
- Create: `tests/Integration/PluginBootstrapTest.php`
- Create: `tools/build.php`
- Create: `.gitignore`

**Interfaces:**
- Produces: `CinebotWp\Plugin::instance(): Plugin`, `Plugin::boot(): void`.
- Produces constants: `CINEBOT_WP_VERSION`, `CINEBOT_WP_FILE`, `CINEBOT_WP_PATH`, `CINEBOT_WP_URL`.
- Produces artifact: `dist/cinebot-wp.zip`, installable without `vendor/`.

- [ ] **Step 1: Initialize source control and development dependencies**

Run from the project root:

```powershell
rtk git init
```

Create `composer.json`:

```json
{
  "name": "cinebot/cinebot-wp",
  "description": "Cinebot schedule synchronization for WordPress",
  "type": "wordpress-plugin",
  "license": "GPL-2.0-or-later",
  "require": {
    "php": ">=7.4"
  },
  "require-dev": {
    "dealerdirect/phpcodesniffer-composer-installer": "^1.0",
    "phpstan/extension-installer": "^1.4",
    "phpstan/phpstan": "^2.1",
    "phpunit/phpunit": "^9.6",
    "szepeviktor/phpstan-wordpress": "^2.0",
    "wp-coding-standards/wpcs": "^3.0",
    "yoast/phpunit-polyfills": "^2.0"
  },
  "autoload": {
    "psr-4": {
      "CinebotWp\\": "includes/"
    }
  },
  "autoload-dev": {
    "psr-4": {
      "CinebotWp\\Tests\\": "tests/"
    }
  },
  "config": {
    "allow-plugins": {
      "dealerdirect/phpcodesniffer-composer-installer": true,
      "phpstan/extension-installer": true
    }
  },
  "scripts": {
    "prepare-tests": "bash docker/prepare-tests.sh",
    "test": "bash docker/run-tests.sh all",
    "test:unit": "bash docker/run-tests.sh unit",
    "test:integration": "bash docker/run-tests.sh integration",
    "lint": "phpcs --standard=phpcs.xml.dist",
    "lint:fix": "phpcbf --standard=phpcs.xml.dist",
    "analyse": "phpstan analyse -c phpstan.neon.dist --no-progress",
    "build": "php tools/build.php",
    "check": ["@lint", "@analyse", "@test", "@build"]
  }
}
```

Create `.gitignore` with `/vendor/`, `/dist/`, `/.phpunit.result.cache`, and `/.docker-cache/`. Production code must not load Composer files.

- [ ] **Step 2: Add the reproducible Docker environment**

Create `compose.yaml` with a MySQL 8 service and a PHP service:

```yaml
services:
  db:
    image: mysql:8.0
    environment:
      MYSQL_DATABASE: wordpress_test
      MYSQL_ROOT_PASSWORD: root
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost", "-proot"]
      interval: 2s
      timeout: 2s
      retries: 30
  php:
    build:
      context: .
      dockerfile: docker/php/Dockerfile
      args:
        PHP_VERSION: ${PHP_VERSION:-7.4}
    working_dir: /plugin
    volumes:
      - ./:/plugin
      - wordpress_tests:/tmp/wordpress-develop
    depends_on:
      db:
        condition: service_healthy
    environment:
      WP_VERSION: ${WP_VERSION:-6.0.12}
      WP_CORE_DIR: /tmp/wordpress-develop/src
      WP_TESTS_DIR: /tmp/wordpress-develop/tests/phpunit
      WP_TESTS_DB_NAME: wordpress_test
      WP_TESTS_DB_USER: root
      WP_TESTS_DB_PASSWORD: root
      WP_TESTS_DB_HOST: db
volumes:
  wordpress_tests:
```

Create `docker/php/Dockerfile`:

```dockerfile
ARG PHP_VERSION=7.4
FROM php:${PHP_VERSION}-cli
RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip zip libzip-dev default-mysql-client \
 && docker-php-ext-install mysqli zip \
 && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /plugin
```

Create executable `docker/prepare-tests.sh`:

```sh
#!/bin/sh
set -eu

target=/tmp/wordpress-develop
version="${WP_VERSION:-6.0.12}"
current=""

if [ -f "$target/.cinebot-wp-version" ]; then
    current="$(cat "$target/.cinebot-wp-version")"
fi

if [ "$current" != "$version" ]; then
    mkdir -p "$target"
    find "$target" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
    git clone --depth 1 --branch "$version" \
        https://github.com/WordPress/wordpress-develop.git "$target"
    printf '%s' "$version" > "$target/.cinebot-wp-version"
fi

test -f "$target/tests/phpunit/includes/bootstrap.php"
```

Create executable `docker/run-tests.sh` so focused PHPUnit arguments are forwarded only to PHPUnit:

```sh
#!/bin/sh
set -eu

suite="${1:-all}"
shift || true
bash docker/prepare-tests.sh

if [ "$suite" = "all" ]; then
    exec vendor/bin/phpunit -c phpunit.xml.dist "$@"
fi

exec vendor/bin/phpunit -c phpunit.xml.dist --testsuite "$suite" "$@"
```

- [ ] **Step 3: Add complete PHPUnit, WPCS, and PHPStan configuration**

Create `phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="tests/bootstrap.php"
         colors="true"
         failOnRisky="true"
         failOnWarning="true"
         beStrictAboutOutputDuringTests="true">
    <testsuites>
        <testsuite name="unit">
            <directory suffix="Test.php">tests/Unit</directory>
        </testsuite>
        <testsuite name="integration">
            <directory suffix="Test.php">tests/Integration</directory>
        </testsuite>
    </testsuites>
    <coverage processUncoveredFiles="true">
        <include>
            <directory suffix=".php">includes</directory>
        </include>
    </coverage>
</phpunit>
```

Create `tests/wp-tests-config.php`:

```php
<?php
/**
 * WordPress integration test configuration.
 *
 * @package CinebotWp
 */

define( 'ABSPATH', rtrim( (string) getenv( 'WP_CORE_DIR' ), '/\\' ) . '/' );
define( 'DB_NAME', (string) getenv( 'WP_TESTS_DB_NAME' ) );
define( 'DB_USER', (string) getenv( 'WP_TESTS_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'WP_TESTS_DB_PASSWORD' ) );
define( 'DB_HOST', (string) getenv( 'WP_TESTS_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Cinebot WP Tests' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WP_DEBUG', true );
$table_prefix = 'wptests_';
```

Create `tests/bootstrap.php`:

```php
<?php
/**
 * Load the WordPress integration test environment.
 *
 * @package CinebotWp
 */

$plugin_root = dirname( __DIR__ );
$tests_dir   = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-develop/tests/phpunit';

require $plugin_root . '/vendor/autoload.php';
putenv( 'WP_TESTS_CONFIG_FILE_PATH=' . __DIR__ . '/wp-tests-config.php' );

require $tests_dir . '/includes/functions.php';
tests_add_filter(
	'muplugins_loaded',
	static function () use ( $plugin_root ): void {
		require $plugin_root . '/cinebot-wp.php';
	}
);
require $tests_dir . '/includes/bootstrap.php';
```

Create `phpcs.xml.dist`:

```xml
<?xml version="1.0"?>
<ruleset name="Cinebot WP">
    <description>Cinebot WP coding standards.</description>
    <file>cinebot-wp.php</file>
    <file>includes</file>
    <file>templates</file>
    <file>tests</file>
    <file>uninstall.php</file>
    <exclude-pattern>*/vendor/*</exclude-pattern>
    <exclude-pattern>*/dist/*</exclude-pattern>
    <arg value="ps"/>
    <config name="minimum_supported_wp_version" value="6.0"/>
    <rule ref="WordPress">
        <exclude name="WordPress.Files.FileName"/>
    </rule>
    <rule ref="WordPress.Files.FileName">
        <exclude-pattern type="relative">^includes/</exclude-pattern>
    </rule>
</ruleset>
```

Create `phpstan.neon.dist`:

```neon
includes:
    - vendor/szepeviktor/phpstan-wordpress/extension.neon

parameters:
    level: 6
    phpVersion: 70400
    paths:
        - cinebot-wp.php
        - includes
    excludePaths:
        - vendor
        - dist
```

- [ ] **Step 4: Write the failing bootstrap and distribution tests**

```php
<?php
/**
 * Plugin foundation integration tests.
 *
 * @package CinebotWp
 */

namespace CinebotWp\Tests\Integration;

use CinebotWp\Plugin;
use ReflectionClass;
use WP_UnitTestCase;
use ZipArchive;

/**
 * Verifies the executable plugin foundation.
 */
final class PluginBootstrapTest extends WP_UnitTestCase {
	/**
	 * Verifies that plugin boot is idempotent.
	 */
	public function test_plugin_bootstraps_once(): void {
		$boot_count = 0;
		$observer   = static function () use ( &$boot_count ): void {
			++$boot_count;
		};

		add_action( 'cinebot_wp_booted', $observer );

		try {
			$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
			$plugin->boot();
			$plugin->boot();
		} finally {
			remove_action( 'cinebot_wp_booted', $observer );
		}

		self::assertSame( 1, $boot_count );
		self::assertSame( Plugin::instance(), Plugin::instance() );
		self::assertTrue( defined( 'CINEBOT_WP_VERSION' ) );
		self::assertSame( '1.0.0', CINEBOT_WP_VERSION );
	}

	/**
	 * Verifies that runtime loading does not depend on Composer.
	 */
	public function test_runtime_does_not_require_composer_vendor_directory(): void {
		self::assertFileExists( CINEBOT_WP_PATH . 'includes/autoload.php' );
		// Direct access is appropriate for a local test fixture.
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$entry_point = file_get_contents( CINEBOT_WP_FILE );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		self::assertIsString( $entry_point );
		self::assertStringNotContainsString( 'vendor/autoload.php', $entry_point );
	}

	/**
	 * Verifies that the distribution contains only runtime files.
	 */
	public function test_distribution_contains_only_runtime_files(): void {
		$archive_path = CINEBOT_WP_PATH . 'dist/cinebot-wp.zip';

		ob_start();
		try {
			require CINEBOT_WP_PATH . 'tools/build.php';
		} finally {
			$build_output = ob_get_clean();
		}

		self::assertIsString( $build_output );
		self::assertStringContainsString( $archive_path, $build_output );

		$archive = new ZipArchive();
		self::assertTrue( $archive->open( $archive_path ) );

		try {
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/cinebot-wp.php' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/autoload.php' ) );
			self::assertNotFalse( $archive->locateName( 'cinebot-wp/includes/Plugin.php' ) );

			for ( $index = 0; $index < $archive->count(); ++$index ) {
				$name = $archive->getNameIndex( $index );
				self::assertIsString( $name );
				self::assertStringStartsWith( 'cinebot-wp/', $name );
				self::assertDoesNotMatchRegularExpression(
					'#^cinebot-wp/(?:\.git|\.github|docker|docs|specs|tests|tools|vendor|dist)(?:/|$)#',
					$name
				);
			}
		} finally {
			$archive->close();
		}
	}
}
```

- [ ] **Step 5: Build the container and verify the test fails**

Run:

```powershell
rtk docker compose build
rtk docker compose run --rm php composer install
rtk docker compose run --rm php composer test:integration -- --filter PluginBootstrapTest
```

Expected: failure because `CinebotWp\Plugin`, the plugin constants, the one-time boot action, and the distribution archive behavior do not exist.

- [ ] **Step 6: Implement the committed production autoloader and bootstrap**

`includes/autoload.php` registers only classes beginning with `CinebotWp\`, maps the remainder to `includes/{Namespace/Path}.php`, rejects paths containing `..`, and requires the file only when it exists. `cinebot-wp.php` requires this committed loader, never `vendor/autoload.php`.

Implement `Plugin` as a final singleton with an idempotent `boot()` method. A successful first boot fires `cinebot_wp_booted` once after setting the guard; later calls return without firing it again. In `cinebot-wp.php`, define constants, load `includes/autoload.php`, and call `Plugin::instance()->boot()`. Activation and deactivation callbacks are added in Task 2 when their concrete methods exist.

Use this public shape:

```php
/**
 * Main plugin coordinator.
 */
final class Plugin {
	/** @var self|null */
	private static $instance;

	/** @var bool */
	private $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;
		do_action( 'cinebot_wp_booted' );
	}

	private function __construct() {
	}
}
```

Create `tools/build.php` using `ZipArchive`. It writes `dist/cinebot-wp.zip` with top-level directory `cinebot-wp/` and includes `cinebot-wp.php`, `uninstall.php`, `includes/`, `assets/`, `templates/`, `languages/`, `README.md`, and `LICENSE` when present. It excludes `.git`, `.github`, `docker`, `docs`, `specs`, `tests`, `tools`, `vendor`, and existing `dist`. Check every `ZipArchive::addFile()` result; on failure close and remove the incomplete archive, then throw a `RuntimeException` naming the rejected source path.

- [ ] **Step 7: Run all foundation gates**

Run:

```powershell
rtk docker compose run --rm php composer test:integration -- --filter PluginBootstrapTest
rtk docker compose run --rm php composer lint
rtk docker compose run --rm php composer analyse
rtk docker compose run --rm php composer build
```

Expected: focused tests pass, WPCS/PHPStan report no errors, and `dist/cinebot-wp.zip` exists without a `vendor/` entry.

- [ ] **Step 8: Commit the foundation**

```powershell
rtk git add composer.json composer.lock cinebot-wp.php includes/autoload.php includes/Plugin.php compose.yaml docker phpunit.xml.dist phpcs.xml.dist phpstan.neon.dist tests tools/build.php .gitignore
rtk git commit -m "chore: bootstrap cinebot wordpress plugin"
```

### Task 2: Install and remove the seven-table schema

**Files:**
- Create: `includes/Database/SchemaInstaller.php`
- Create: `includes/Database/EventTypeDefaults.php`
- Create: `uninstall.php`
- Create: `tests/Integration/SchemaInstallerTest.php`
- Create: `tests/Integration/UninstallTest.php`
- Modify: `cinebot-wp.php`
- Modify: `includes/Plugin.php`
- Modify: `phpstan.neon.dist`

**Interfaces:**
- Produces: `SchemaInstaller::__construct(\wpdb $db)`, `install(): void`, `supportsTransactions(): bool`.
- Produces: `EventTypeDefaults::all(): array<int,array{codice:string,descrizione:string}>` containing exactly 62 rows.
- Produces lifecycle entry points: `Plugin::activate(): void`, `Plugin::deactivate(): void`.

- [ ] **Step 1: Write failing schema tests**

Test that `install()` creates exactly these tables:

```php
$suffixes = ['titoli', 'eventi', 'settori', 'prezzi', 'locali', 'tipologie_eventi', 'sync_log'];
foreach ($suffixes as $suffix) {
    $table = self::$wpdb->prefix . 'cinebot_' . $suffix;
    self::assertSame($table, self::$wpdb->get_var(self::$wpdb->prepare('SHOW TABLES LIKE %s', $table)));
}
self::assertSame('1.0.0', get_option('cinebot_wp_db_version'));
self::assertCount(62, EventTypeDefaults::all());
```

Also assert nullable remote IDs, the composite unique indexes for sectors/prices, an index on `eventi.inizio`, `ENGINE=InnoDB` on every plugin table, and reconciliation columns `sync_active`/`last_seen_sync` on titles, events, sectors, and prices. Protect the complete ordered default catalog with count, code uniqueness, and a canonical SHA-256 fingerprint of UTF-8 `codice<TAB>descrizione` rows joined by LF without a trailing LF. Add a secondary `wpdb` test double that fails after earlier default inserts and prove rollback leaves zero rows before a successful 62-row retry.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
```

Expected: failure because installer and tables do not exist.

- [ ] **Step 3: Implement the schema using `dbDelta()`**

Create the seven tables exactly as approved in the design. Important implementation details:

- Remote IDs are nullable so manual rows can use `NULL` without violating unique indexes.
- Use `bigint(20) unsigned` IDs, `decimal(10,2)` money values, and WordPress charset/collation.
- `tipologie_eventi.codice` is `varchar(10)` and unique, preserving leading zeroes.
- Store DB version in `cinebot_wp_db_version` with autoload disabled.
- Seed the complete defaults list only when `cinebot_tipologie_eventi` is empty, exactly as approved. Wrap all default inserts in an explicit InnoDB `START TRANSACTION`/`COMMIT`, issue `ROLLBACK` on any insert or commit failure, and throw a translated safe `RuntimeException` so retry sees an empty table. Subsequent activations leave existing rows and their `attivo` state unchanged.
- Append `ENGINE=InnoDB` to every `dbDelta()` statement. Before creating tables, `supportsTransactions()` checks that InnoDB appears in `SHOW ENGINES` with support `YES` or `DEFAULT`; otherwise activation throws a translated `RuntimeException` and creates no tables.
- Add nullable `frontend_id bigint(20) unsigned` to `titoli` so reconciliation is scoped to each returned programmazione envelope. Add `sync_active tinyint(1) unsigned NOT NULL DEFAULT 1` and nullable `last_seen_sync char(36)` to `titoli`, `eventi`, `settori`, and `prezzi`; index `sync_active`, and index `last_seen_sync` together with `frontend_id` or the parent key used during reconciliation.

`EventTypeDefaults::all()` must contain the 62 code/description pairs from Appendix A of the design, including code `41` and `42` without footnote markers.

- [ ] **Step 4: Implement lifecycle behavior**

In `cinebot-wp.php`, register only `[Plugin::class, 'activate']` and `[Plugin::class, 'deactivate']`. `Plugin::activate()` constructs `SchemaInstaller` with global `$wpdb` and calls `install()`. `Plugin::deactivate()` only clears `cinebot_wp_sync_event`; it must not delete data. No other class is registered as a lifecycle callback.

Implement `uninstall.php` to verify `WP_UNINSTALL_PLUGIN`, drop the seven tables, delete `cinebot_wp_settings`, `cinebot_wp_db_version`, `cinebot_wp_encryption_salt`, `cinebot_wp_sync_lock`, clear scheduled hooks, and delete `_transient_cinebot_prog_%` plus matching timeout rows. This single-site-only cleanup is documented; no network/site loop is added. Cover the destructive boundary with an integration test that creates all seven tables and approved/unrelated options, cron hooks, and transients; execute guarded uninstall, assert only approved data is removed, and restore schema in `finally`.

When `uninstall.php` is created, add it to `parameters.paths` in `phpstan.neon.dist`; Task 1 intentionally lists only paths that exist at that foundation stage.

- [ ] **Step 5: Run focused and full tests**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter SchemaInstallerTest
rtk docker compose run --rm php composer test:integration -- --filter UninstallTest
rtk docker compose run --rm php composer check
```

Expected: schema tests and full suite pass.

- [ ] **Step 6: Commit schema lifecycle**

```powershell
rtk git add includes/Database cinebot-wp.php includes/Plugin.php uninstall.php tests/Integration/SchemaInstallerTest.php tests/Integration/UninstallTest.php phpstan.neon.dist
rtk git commit -m "feat: install cinebot database schema"
```

---

## Phase 2: Domain and Persistence

### Task 3: Add typed domain DTOs

**Files:**
- Create: `includes/Models/Titolo.php`
- Create: `includes/Models/Evento.php`
- Create: `includes/Models/Settore.php`
- Create: `includes/Models/Prezzo.php`
- Create: `includes/Models/Locale.php`
- Create: `includes/Models/TipologiaEvento.php`
- Create: `includes/Models/SyncLog.php`
- Create: `includes/ReadModels/ProgrammazioneCard.php`
- Create: `tests/Unit/ModelsTest.php`

**Interfaces:**
- Each model produces `fromArray(array $data): self` and `toArray(): array`.
- Public properties use PHP 7.4-compatible typed properties; nullable remote IDs use `?int`.
- Money remains string decimal data in DTOs to avoid binary-float mutation.
- `ProgrammazioneCard::fromRow(array $row): self` is the only raw-row boundary for the public joined projection.

- [ ] **Step 1: Write failing hydration tests**

Create one data-provider case per model and a projection case for `ProgrammazioneCard`. For example:

```php
public function test_titolo_round_trip_preserves_remote_identity_and_source(): void
{
    $input = [
        'id' => 9,
        'idtitolo' => 491,
        'titolo' => 'DONNE & UOMINI',
        'tipoevento_codice' => '45',
        'source' => 'api',
    ];

    $model = Titolo::fromArray($input);

    self::assertSame(491, $model->idtitolo);
    self::assertSame('45', $model->tipoeventoCodice);
    self::assertSame('api', $model->source);
    self::assertSame('DONNE & UOMINI', $model->toArray()['titolo']);
}
```

- [ ] **Step 2: Run and observe missing classes**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
```

Expected: class-not-found failure.

- [ ] **Step 3: Implement DTOs**

Map snake_case database keys to explicit camelCase properties. `Titolo` includes nullable `frontendId`. Normalize booleans to `int` because MySQL stores flags as tinyints. Set defaults: `source='manual'`, `syncActive=1`, `lastSeenSync=null`, arrays to `[]`, nullable strings/IDs to `null`.

Do not add validation or persistence to models; validation belongs to page/services and persistence belongs to repositories.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
rtk git add includes/Models includes/ReadModels tests/Unit/ModelsTest.php
rtk git commit -m "feat: add cinebot domain models"
```

### Task 4: Implement event-type and venue repositories

**Files:**
- Create: `includes/Repositories/TipologiaRepository.php`
- Create: `includes/Repositories/LocaleRepository.php`
- Create: `tests/Integration/TipologiaRepositoryTest.php`
- Create: `tests/Integration/LocaleRepositoryTest.php`

**Interfaces:**
- `TipologiaRepository::findByCode(string $code): ?TipologiaEvento`
- `TipologiaRepository::findAll(bool $activeOnly = false): array`
- `TipologiaRepository::save(TipologiaEvento $type): int`
- `TipologiaRepository::setActive(int $id, bool $active): void`
- `TipologiaRepository::deleteCustom(int $id): bool`
- `LocaleRepository::find(int $id): ?Locale`
- `LocaleRepository::findByRemoteId(int $remoteId): ?Locale`
- `LocaleRepository::save(Locale $locale): int`
- `LocaleRepository::upsertApi(array $data): int`
- `LocaleRepository::search(array $filters, int $page, int $perPage): array`
- `LocaleRepository::count(array $filters = []): int`

- [ ] **Step 1: Write failing repository tests**

Required cases:

- Code `01` remains a string and resolves to CINEMA.
- Predefined event types can be disabled but not deleted by `deleteCustom()`.
- A custom event type can be inserted, updated, disabled, and deleted.
- `upsertApi()` creates `source=api`, updates an API venue, and does not overwrite a matching `source=manual` venue.
- Venue search filters by comune/provincia and paginates deterministically by name then ID.

- [ ] **Step 2: Run and observe repository failures**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"
```

- [ ] **Step 3: Implement repositories**

Inject `wpdb`; never use a hidden global inside repository methods. Return DTOs, not raw database rows. Use `%d`, `%s`, and explicit allowlists for order/filter fields. `LocaleRepository::upsertApi()` follows:

```php
$existing = $this->findByRemoteId((int) $data['localeId']);
if ($existing && $existing->source === 'manual') {
    return $existing->id;
}
// map embedded API fields, source=api, then insert/update
```

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter "TipologiaRepositoryTest|LocaleRepositoryTest"
rtk git add includes/Repositories/TipologiaRepository.php includes/Repositories/LocaleRepository.php tests/Integration
rtk git commit -m "feat: persist event types and venues"
```

### Task 5: Implement the title hierarchy repositories

**Files:**
- Create: `includes/Repositories/TitoloRepository.php`
- Create: `includes/Repositories/EventoRepository.php`
- Create: `includes/Repositories/SettoreRepository.php`
- Create: `includes/Repositories/PrezzoRepository.php`
- Create: `tests/Integration/ScheduleRepositoryTest.php`

**Interfaces:**
- `TitoloRepository::find(int $id): ?Titolo`
- `TitoloRepository::findByRemoteId(int $remoteId): ?Titolo`
- `TitoloRepository::save(Titolo $title): int`
- `TitoloRepository::search(array $filters, int $page, int $perPage): array`
- `TitoloRepository::count(array $filters = []): int`
- `TitoloRepository::statistics(): array{titoli_totali:int,titoli_manuali:int,eventi_totali:int,locali_totali:int,tipologie_attive:int}`
- `TitoloRepository::countByTypeCode(string $code): int`
- `TitoloRepository::findPublicSchedule(array $filters): array<ProgrammazioneCard>`
- `TitoloRepository::countPublicSchedule(array $filters): int`
- `TitoloRepository::deactivateUnseenApi(int $frontendId, string $syncToken): array<int>` returning affected title IDs
- `TitoloRepository::delete(int $id): bool`
- `EventoRepository::findByRemoteId(int $remoteId): ?Evento`
- `EventoRepository::save(Evento $event): int`
- `EventoRepository::findByTitoloId(int $titleId): array`
- `EventoRepository::belongsToTitolo(int $eventId, int $titleId): bool`
- `EventoRepository::countByTitoloId(int $titleId): int`
- `EventoRepository::countByLocaleId(int $localeId): int`
- `EventoRepository::deleteByTitoloId(int $titleId): int`
- `EventoRepository::deactivateUnseenApi(int $titleId, string $syncToken): array<int>` returning affected event IDs
- `EventoRepository::deactivateByTitoloIds(array $titleIds): array<int>` returning affected event IDs
- `SettoreRepository::findByRemoteId(int $eventId, int $remoteId): ?Settore`
- `SettoreRepository::save(Settore $sector): int`
- `SettoreRepository::findByEventoId(int $eventId): array`
- `SettoreRepository::belongsToEvento(int $sectorId, int $eventId): bool`
- `SettoreRepository::deleteByEventoId(int $eventId): int`
- `SettoreRepository::deactivateUnseenApi(int $eventId, string $syncToken): array<int>` returning affected sector IDs
- `SettoreRepository::deactivateByEventoIds(array $eventIds): array<int>` returning affected sector IDs
- `PrezzoRepository::findByRemoteId(int $sectorId, int $remoteId): ?Prezzo`
- `PrezzoRepository::save(Prezzo $price): int`
- `PrezzoRepository::findBySettoreId(int $sectorId): array`
- `PrezzoRepository::belongsToSettore(int $priceId, int $sectorId): bool`
- `PrezzoRepository::deleteBySettoreId(int $sectorId): int`
- `PrezzoRepository::deactivateUnseenApi(int $sectorId, string $syncToken): array<int>` returning affected price IDs
- `PrezzoRepository::deactivateBySettoreIds(array $sectorIds): int`
- Event, sector, and price repositories also produce `delete(int $id): bool`; title hierarchy deletion remains an explicit service/editor transaction.

- [ ] **Step 1: Write failing hierarchy tests**

Create one manual and one API hierarchy. Assert:

- Nullable remote IDs allow multiple manual titles/events/sectors/prices.
- API identities are unique in their required scope.
- `findPublicSchedule()` returns one `ProgrammazioneCard` per future event, joined with title, type, venue, and min/max active price.
- Filters `tipo`, `locale`, `comune`, `from`, `to` work together.
- Allowed sorting is limited to `inizio` and `titolo`; unrecognized values fall back to `inizio ASC`.
- Past events, `sync_active=0` hierarchy rows, events whose API `stato` is not `3`, and inactive prices do not affect default public output.
- Parent lookup, ownership checks, delete-by-parent, counts, and each reconciliation method affect only the supplied parent/frontend scope. Cascading deactivation marks every descendant inactive when a title, event, or sector disappears.

- [ ] **Step 2: Run and observe failures**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ScheduleRepositoryTest
```

- [ ] **Step 3: Implement title/event/sector/price persistence**

Use targeted insert/update methods rather than a generic dynamic repository. Map every allowed field explicitly. `findPublicSchedule()` hydrates `ProgrammazioneCard` instances from this internal row projection:

```php
[
    'evento_id' => int,
    'inizio' => string,
    'titolo_id' => int,
    'titolo' => string,
    'descrizione' => string,
    'locandina_url' => ?string,
    'tipo_codice' => ?string,
    'tipo_descrizione' => ?string,
    'locale_id' => int,
    'locale_nome' => string,
    'comune' => ?string,
    'prezzo_min' => ?string,
    'prezzo_max' => ?string,
]
```

No repository other than this named projection boundary returns undocumented raw rows.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ScheduleRepositoryTest
rtk git add includes/Repositories tests/Integration/ScheduleRepositoryTest.php
rtk git commit -m "feat: persist cinebot schedule hierarchy"
```

### Task 6: Implement synchronization log persistence

**Files:**
- Create: `includes/Repositories/SyncLogRepository.php`
- Create: `tests/Integration/SyncLogRepositoryTest.php`

**Interfaces:**
- `start(string $payloadHash = ''): int`
- `finish(int $id, string $status, array $stats, ?string $error = null): void`
- `latest(): ?SyncLog`
- `recent(int $limit = 5): array`
- `search(array $filters, int $page, int $perPage): array`
- `deleteOlderThan(DateTimeImmutable $cutoff): int`

- [ ] **Step 1: Write failing log lifecycle tests**

Assert a started row has `status=running`; successful finish stores all four counters; error finish stores a sanitized message; recent rows are newest first; retention removes only rows older than the cutoff.

- [ ] **Step 2: Run, implement, and rerun**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter SyncLogRepositoryTest
```

Implement with repository-injected clock callback defaulting to `current_time('mysql', true)` so tests can be deterministic. Run the command again; expected: PASS.

- [ ] **Step 3: Commit**

```powershell
rtk git add includes/Repositories/SyncLogRepository.php tests/Integration/SyncLogRepositoryTest.php
rtk git commit -m "feat: persist synchronization history"
```

---

## Phase 3: API, Settings, Sync, and Cron

### Task 7: Encrypt and validate API settings

**Files:**
- Create: `includes/Services/SettingsService.php`
- Create: `tests/Unit/SettingsServiceTest.php`

**Interfaces:**
- `SettingsService::get(): array`
- `SettingsService::save(array $input): array`
- `SettingsService::username(): string`
- `SettingsService::password(): string`
- `SettingsService::frontend(): ?int`
- `SettingsService::frequency(): string`
- `SettingsService::enabled(): bool`
- `SettingsService::baseUrl(): string`

- [ ] **Step 1: Write failing settings tests**

Test defaults, accepted frequencies (`hourly`, `twicedaily`, `daily`, `weekly`), invalid-frequency fallback to `daily`, optional positive numeric frontend, forced HTTPS base URL, and password round-trip. Assert the stored option does not contain the plaintext password and that submitting an empty password preserves the existing one.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter SettingsServiceTest
```

- [ ] **Step 3: Implement settings and encryption**

Use AES-256-CBC with a random IV prepended to ciphertext and authenticated integrity via HMAC-SHA256. Derive separate encryption/HMAC keys from `AUTH_SALT` plus `cinebot_wp_encryption_salt`; generate the latter with `random_bytes(32)` if absent. Store Base64 of `iv || ciphertext || hmac`. Throw `RuntimeException` on malformed/tampered ciphertext.

Only expose the decrypted password through `password()`; `get()` returns `has_password` rather than plaintext.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter SettingsServiceTest
rtk git add includes/Services/SettingsService.php tests/Unit/SettingsServiceTest.php
rtk git commit -m "feat: secure cinebot api settings"
```

### Task 8: Fetch and validate Cinebot API responses

**Files:**
- Create: `includes/Services/ApiClient.php`
- Create: `includes/Services/ApiException.php`
- Create: `includes/Services/LocandinaService.php`
- Create: `tests/Unit/ApiClientTest.php`
- Create: `tests/Unit/LocandinaServiceTest.php`

**Interfaces:**
- `ApiClient::__construct(SettingsService $settings, ?callable $httpGet = null)`
- `ApiClient::fetchProgrammazione(): array`
- `LocandinaService::build(string $host, string $path, int $titleId, int $flag): ?string`

- [ ] **Step 1: Write failing API and poster tests**

Required API cases:

- URL is `/v1/programmazione/50` when frontend is 50 and `/v1/programmazione` when null.
- Header equals `Basic ` plus Base64 of `username:password`; timeout is 60; Accept is JSON.
- `WP_Error`, HTTP 401, HTTP 500, malformed JSON, non-200 payload status, and absent `programmazione` throw `ApiException` with safe messages.
- A valid JSON body returns an associative array.

Required poster cases:

- Flag 0 returns null.
- Valid data returns `https://ticket.cinebot.it/martinovich/titolo/491/locandina`.
- Host/path are normalized without allowing schemes or `..` path traversal.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter "ApiClientTest|LocandinaServiceTest"
```

- [ ] **Step 3: Implement the services**

Default `$httpGet` calls `wp_remote_get`; the injected callback makes unit tests independent. Never include password or Authorization content in exceptions/logs. Validate top-level API `status === 200` and `error === null` when those keys are present.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:unit -- --filter "ApiClientTest|LocandinaServiceTest"
rtk git add includes/Services tests/Unit/ApiClientTest.php tests/Unit/LocandinaServiceTest.php
rtk git commit -m "feat: fetch cinebot programming api"
```

### Task 9: Synchronize the complete API hierarchy

**Files:**
- Create: `includes/Services/SyncService.php`
- Create: `includes/Services/SyncResult.php`
- Create: `includes/Services/SyncLock.php`
- Create: `tests/fixtures/cinebot-sample.json`
- Create: `tests/Integration/SyncServiceTest.php`
- Create: `tests/Integration/SyncLockTest.php`

**Interfaces:**
- `SyncService::sync(): SyncResult`
- `SyncService::syncPayload(array $payload): SyncResult`
- `SyncResult::isSuccess(): bool`
- `SyncResult::status(): string`
- `SyncResult::stats(): array{titoli_added:int,titoli_updated:int,eventi_added:int,eventi_updated:int}`
- `SyncResult::message(): string`
- `SyncLock::acquire(int $ttl = 300): ?string` returning an owner token or null
- `SyncLock::release(string $token): bool`

- [ ] **Step 1: Add the approved fixture and failing import test**

Store the supplied `cinebot.json` as valid UTF-8 JSON. Test the first import counts and verify title 491, event 2920, its venue, sector, price, type code, generated poster URL, and `source=api` values.

- [ ] **Step 2: Add failing idempotence and ownership tests**

Required cases:

- Importing identical payload twice does not duplicate any row.
- Changed API fields update `source=api` rows.
- A title and venue changed to `source=manual` remain unchanged on the next import.
- Missing optional arrays (`tag`, `cast`, `eventi`, `settori`, `prezzi`) are treated as empty.
- A malformed title rolls back that full synchronization and logs `error`; no partial hierarchy remains.
- Removing an API price, sector, event, or title marks that row `sync_active=0` without deleting it; reintroducing it marks it active again without changing its local primary key.
- Reconciliation is scoped by `frontend_id`; importing one frontend does not deactivate another frontend.
- A sync lock prevents concurrent import and returns a non-success result without calling the API.
- Two competing `SyncLock::acquire()` calls produce exactly one token; an expired lock can be reclaimed; a non-owner token cannot release the lock.
- Successful import deletes `cinebot_prog_*` transients.

- [ ] **Step 3: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter "SyncServiceTest|SyncLockTest"
```

- [ ] **Step 4: Implement transactional synchronization**

Algorithm:

1. `SyncLock::acquire()` atomically creates non-autoloaded option `cinebot_wp_sync_lock` with JSON `{token,expires_at}` by using `add_option()`. If it exists and is expired, delete exactly the observed option value through prepared SQL and retry `add_option()` once.
2. Fetch payload (or accept it through `syncPayload()`), canonicalize and hash it. Reject a payload that does not contain the requested frontend envelope; never reconcile an unknown scope.
3. Generate one UUID sync token and start the log.
4. Begin one InnoDB transaction.
5. For each frontend envelope, upsert API venues before events and skip matching manual venues.
6. Upsert each title with its `frontend_id`, `sync_active=1`, and `last_seen_sync=token`; do the same for its events, sectors, and prices, always skipping manual rows.
7. After each seen sector, deactivate unseen prices. After each seen event, deactivate unseen sectors and cascade to their prices. After each seen title, deactivate unseen events and cascade to sectors/prices. After the envelope, deactivate unseen titles and cascade through all descendants using the `deactivateBy*Ids()` contracts. Empty ID arrays are no-ops and never generate `IN ()` SQL.
8. Commit, delete public cache transients with prepared option-name patterns, and finish the success log.
9. On `Throwable`, roll back and finish the error log; do not alter prior reconciliation state.
10. In `finally`, `SyncLock::release(token)` deletes the option only when its stored token matches the caller.

Use `sync_hash` to avoid incrementing update counters for unchanged titles, but still traverse children to catch child-only changes. Manual rows always retain `sync_active=1` and are excluded from all unseen updates.

- [ ] **Step 5: Run focused and full tests**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter "SyncServiceTest|SyncLockTest"
rtk docker compose run --rm php composer check
```

- [ ] **Step 6: Commit**

```powershell
rtk git add includes/Services/SyncService.php includes/Services/SyncResult.php includes/Services/SyncLock.php tests/fixtures tests/Integration/SyncServiceTest.php tests/Integration/SyncLockTest.php
rtk git commit -m "feat: synchronize cinebot schedules"
```

### Task 10: Schedule and reschedule WordPress cron

**Files:**
- Create: `includes/Services/CronScheduler.php`
- Create: `tests/Integration/CronSchedulerTest.php`

**Interfaces:**
- `CronScheduler::register(): void`
- `CronScheduler::schedule(): void`
- `CronScheduler::reschedule(array $old, array $new): void`
- `CronScheduler::clear(): void`
- Hook: `cinebot_wp_sync_event`.
- Schedule: `cinebot_weekly`, interval `WEEK_IN_SECONDS`.

- [ ] **Step 1: Write failing cron tests**

Assert weekly interval registration, disabled settings create no event, enabled settings create exactly one event with selected recurrence, frequency changes replace rather than duplicate, disabling clears, and cron hook invokes `SyncService::sync()`.

- [ ] **Step 2: Run, implement, and rerun**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter CronSchedulerTest
```

Use `wp_next_scheduled`, `wp_schedule_event`, and `wp_clear_scheduled_hook`. Register `update_option_cinebot_wp_settings` with three accepted arguments and compare only `sync_enabled`/`sync_frequency`.

- [ ] **Step 3: Commit**

```powershell
rtk git add includes/Services/CronScheduler.php tests/Integration/CronSchedulerTest.php
rtk git commit -m "feat: schedule cinebot synchronization"
```

---

## Phase 4: WordPress Administration

### Task 11: Add admin menu, API settings, and synchronization controls

**Files:**
- Create: `includes/Admin/AdminMenu.php`
- Create: `includes/Admin/Pages/DashboardPage.php`
- Create: `includes/Admin/Pages/ApiPage.php`
- Create: `assets/js/cinebot-admin.js`
- Create: `assets/css/cinebot-admin.css`
- Create: `tests/Integration/ApiAdminPageTest.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- `AdminMenu::register(): void`
- `DashboardPage::render(): void` initially renders synchronization status plus a link to API settings.
- `ApiPage::render(): void`
- `ApiPage::save(): void`
- `ApiPage::testConnection(): void`
- `ApiPage::syncNow(): void`
- AJAX actions: `wp_ajax_cinebot_wp_test_connection`, `wp_ajax_cinebot_wp_sync_now`.

- [ ] **Step 1: Write failing authorization and rendering tests**

Assert administrator access, non-admin rejection, nonce rejection, settings form field names, hidden existing password, sanitized saves, connection-test title count, manual sync stats, and JSON error responses without credentials.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ApiAdminPageTest
```

- [ ] **Step 3: Implement menu shell and API page**

Register only the top-level `Cinebot` Dashboard and API submenu in this task. `DashboardPage` is a concrete minimal page, not a placeholder callback. Later tasks add their submenu only after their page class exists. Apply `apply_filters('cinebot_wp_capability', 'manage_options')` consistently.

The page uses `settings_fields`-equivalent nonce handling but saves through `SettingsService`, then calls `CronScheduler::reschedule()`. AJAX handlers call `check_ajax_referer`, `current_user_can`, and `wp_send_json_success/error`.

Enqueue admin assets only on Cinebot screens. The JS posts form data with `fetch()` and shows an ARIA live status region.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ApiAdminPageTest
rtk git add includes/Admin assets includes/Plugin.php tests/Integration/ApiAdminPageTest.php
rtk git commit -m "feat: add cinebot api administration"
```

### Task 12: Add the searchable Programs list

**Files:**
- Create: `includes/Admin/Pages/TitoliListPage.php`
- Create: `tests/Integration/TitoliListPageTest.php`
- Modify: `includes/Admin/AdminMenu.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- `TitoliListPage::render(): void`
- Internal table extends `WP_List_Table` and accepts `TitoloRepository`.
- Row actions use page slug `cinebot-programmazioni` and actions `edit`, `delete`.

- [ ] **Step 1: Write failing table tests**

Assert columns, 50-row pagination, title/author search, type/source filters, escaped poster thumbnail, event count, nonce-protected delete, and bulk deletion. New/edit actions are deliberately absent until Task 13 creates their concrete handler. A title must not be deleted until children are deleted within the same transaction.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter TitoliListPageTest
```

- [ ] **Step 3: Implement list and deletion transaction**

Load `WP_List_Table` only when needed. Normalize request values with `wp_unslash`; allowlist filter values. Use repository count/search methods and set pagination args. Deletion order is prices → sectors → events → title through the Task 5 delete-by-parent interfaces. Register the Programmazioni submenu and compose this page in the same task, without routing to the not-yet-created editor.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter TitoliListPageTest
rtk git add includes/Admin/Pages/TitoliListPage.php includes/Admin/AdminMenu.php includes/Plugin.php tests/Integration/TitoliListPageTest.php
rtk git commit -m "feat: manage cinebot program list"
```

### Task 13: Add the nested title/event editor

**Files:**
- Create: `includes/Admin/Pages/TitoloEditPage.php`
- Create: `tests/Integration/TitoloEditPageTest.php`
- Modify: `assets/js/cinebot-admin.js`
- Modify: `assets/css/cinebot-admin.css`
- Modify: `includes/Admin/Pages/TitoliListPage.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- `TitoloEditPage::render(?int $id = null): void`
- `TitoloEditPage::save(): void`
- POST structure: `titolo[...]`, `eventi[{rowKey}][...]`, nested `settori[{rowKey}]`, nested `prezzi[{rowKey}]`.

- [ ] **Step 1: Write failing editor tests**

Required cases:

- Render new and existing forms with nonce and all approved title fields.
- Add the concrete “Nuovo titolo” and row “Modifica” actions to `TitoliListPage`, routing only after this editor exists.
- New title/event/sector/price rows receive `source=manual` and null remote IDs.
- Editing an imported hierarchy keeps its existing `source=api` and remote IDs.
- Required title, event date, venue, sector name, and non-negative prices are validated before persistence.
- Saving a hierarchy is atomic; an invalid child leaves the previous hierarchy unchanged.
- Removed children are deleted, but only under the edited title.
- Description is sanitized with `wp_kses_post`; URLs with `esc_url_raw`; tags become a unique JSON string array.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter TitoloEditPageTest
```

- [ ] **Step 3: Implement hierarchical render and save**

Load the hierarchy with `EventoRepository::findByTitoloId()`, `SettoreRepository::findByEventoId()`, and `PrezzoRepository::findBySettoreId()`. Render one accessible `<fieldset>` per event and nested repeated rows with `<template>` elements. `cinebot-admin.js` clones templates, replaces `__INDEX__` tokens with monotonically increasing keys, and supports add/remove without reusing keys.

Server-side save performs full validation first, then opens one transaction and writes title → events → sectors → prices. Existing IDs are accepted only after proving parent ownership through the Task 5 `belongsTo*` methods, preventing cross-title tampering. Compare submitted IDs to loaded IDs and remove missing children through `deleteBy*` in price → sector → event order. Compose and inject the editor into the list-page router in this task.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter TitoloEditPageTest
rtk git add includes/Admin/Pages/TitoloEditPage.php includes/Admin/Pages/TitoliListPage.php includes/Plugin.php assets tests/Integration/TitoloEditPageTest.php
rtk git commit -m "feat: edit nested cinebot programs"
```

### Task 14: Add venue and event-type CRUD pages

**Files:**
- Create: `includes/Admin/Pages/LocaliListPage.php`
- Create: `includes/Admin/Pages/LocaleEditPage.php`
- Create: `includes/Admin/Pages/TipologieListPage.php`
- Create: `includes/Admin/Pages/TipologiaEditPage.php`
- Create: `tests/Integration/ReferenceAdminPagesTest.php`
- Modify: `includes/Admin/AdminMenu.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- Each list page provides `render(): void`; each editor provides `render(?int $id): void` and `save(): void`.
- Event-type toggle action: `cinebot_toggle_tipologia` with ID, desired state, and nonce.

- [ ] **Step 1: Write failing CRUD tests**

Assert venue filtering by comune/provincia, venue creation as manual, API venue editing without changing source, prevention of deleting referenced venues, event-type filters, immutable predefined code, custom code uniqueness, predefined delete rejection, and active toggle behavior.

- [ ] **Step 2: Run, implement, and rerun**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ReferenceAdminPagesTest
```

Implement native tables/forms with capability, nonce, sanitation, escaping, row counts, and admin notices. Add Locali and Tipologie submenus and compose all four pages in this same task. Run again; expected: PASS.

- [ ] **Step 3: Commit**

```powershell
rtk git add includes/Admin/Pages includes/Admin/AdminMenu.php includes/Plugin.php tests/Integration/ReferenceAdminPagesTest.php
rtk git commit -m "feat: manage cinebot venues and event types"
```

### Task 15: Add Dashboard and Sync Log pages

**Files:**
- Modify: `includes/Admin/Pages/DashboardPage.php`
- Create: `includes/Admin/Pages/SyncLogPage.php`
- Create: `tests/Integration/MonitoringAdminPagesTest.php`
- Modify: `includes/Admin/AdminMenu.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- `DashboardPage::render(): void`
- `SyncLogPage::render(): void`
- `SyncLogPage::deleteOld(): void`

- [ ] **Step 1: Write failing monitoring tests**

Dashboard assertions: last and next sync, five counters, five recent logs, status badges, links, manual-sync button. Log assertions: pagination, status filter, duration, counters, escaped full error detail, bulk delete, and `>30 days` cleanup using UTC cutoff.

- [ ] **Step 2: Run, implement, and rerun**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter MonitoringAdminPagesTest
```

Enhance the concrete Dashboard shell from Task 11 using repository statistics, `wp_next_scheduled`, `human_time_diff`, and semantic status labels. Add the Log submenu and compose `SyncLogPage` in this same task. Never display raw exception stack traces or API response bodies. Run again; expected: PASS.

- [ ] **Step 3: Commit**

```powershell
rtk git add includes/Admin/Pages includes/Admin/AdminMenu.php includes/Plugin.php tests/Integration/MonitoringAdminPagesTest.php
rtk git commit -m "feat: monitor cinebot synchronization"
```

---

## Phase 5: Public Shortcodes

### Task 16: Query, cache, and render public schedules

**Files:**
- Create: `includes/Frontend/ShortcodeHandler.php`
- Create: `includes/Frontend/TemplateRenderer.php`
- Create: `templates/programmazione-cards.php`
- Create: `templates/titolo-card.php`
- Create: `templates/dettaglio-titolo.php`
- Create: `tests/Integration/ShortcodeHandlerTest.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- `ShortcodeHandler::register(): void`
- `ShortcodeHandler::renderProgrammazione(array $attributes = []): string`
- `ShortcodeHandler::renderTitolo(array $attributes = []): string`
- `TemplateRenderer::render(string $template, array $context): string`
- Shortcodes: `[cinebot_programmazione]`, `[cinebot_titolo id="123"]`.
- Consumes: `TitoloRepository::findPublicSchedule()` returning `ProgrammazioneCard` and `countPublicSchedule()` for pagination.

- [ ] **Step 1: Write failing shortcode tests**

Assert defaults (`from=today`, `limit=50`, `orderby=inizio`, `order=ASC`, filters shown), every approved attribute, invalid input fallback, no-results output, active-type filter options, escaped card content, min/max price output, lazy poster, unique shortcode instance IDs, theme override lookup, and transient cache hit/miss. Fixtures with `sync_active=0` or `evento.stato != 3` must never render.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ShortcodeHandlerTest
```

- [ ] **Step 3: Implement renderer and templates**

Sanitize attributes through explicit allowlists and clamp limit to 1–100. Cache key is `cinebot_prog_` plus SHA-256 of normalized attributes and page. TTL defaults to 900 seconds through `cinebot_wp_cache_ttl`.

Register both shortcodes through `Plugin` in this task so the server-rendered feature is available before AJAX enhancement. Template lookup order:

1. `get_stylesheet_directory()/cinebot-wp/{template}.php`
2. plugin `templates/{template}.php`

Pass a fixed context array; do not `extract()` untrusted keys. The renderer starts output buffering, includes the selected file, and always cleans the buffer on failure.

When the `templates/` directory is created, add it to `parameters.paths` in `phpstan.neon.dist`.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter ShortcodeHandlerTest
rtk git add includes/Frontend templates includes/Plugin.php tests/Integration/ShortcodeHandlerTest.php
rtk git commit -m "feat: render cinebot schedule shortcodes"
```

### Task 17: Add public AJAX filters, load-more, and responsive styles

**Files:**
- Create: `assets/js/cinebot-frontend.js`
- Create: `assets/css/cinebot-frontend.css`
- Create: `tests/Integration/FrontendAjaxTest.php`
- Modify: `includes/Frontend/ShortcodeHandler.php`
- Modify: `includes/Plugin.php`

**Interfaces:**
- AJAX actions: `wp_ajax_cinebot_wp_filter`, `wp_ajax_nopriv_cinebot_wp_filter`.
- Response: `{html:string,total:int,has_more:bool,page:int}`.

- [ ] **Step 1: Write failing AJAX tests**

Assert public access requires a valid public nonce, normalized filters are identical to shortcode filters, page offset is correct, `has_more` is true only when more rows exist, and malformed/oversized page values return a 400 JSON error.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter FrontendAjaxTest
```

- [ ] **Step 3: Implement progressive enhancement**

Register/enqueue assets only when a registered shortcode is rendered. Localize AJAX URL, nonce, and translated labels under `cinebotWpFrontend`.

JavaScript requirements:

- Attach independently to every `.cinebot-programmazione` instance.
- Use `FormData`, `URLSearchParams`, and `fetch`.
- Disable submit/load-more while pending and restore on error.
- Replace cards on filter; append on load-more.
- Announce result count in an `aria-live="polite"` region.
- Leave server-rendered content usable when JavaScript is disabled.

CSS requirements: names scoped under `.cinebot-programmazione`, responsive grid, keyboard-visible focus, CSS variables, no theme-wide selectors.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter FrontendAjaxTest
rtk git add assets includes/Frontend/ShortcodeHandler.php includes/Plugin.php tests/Integration/FrontendAjaxTest.php
rtk git commit -m "feat: filter cinebot schedules on frontend"
```

---

## Phase 6: Composition, Compatibility, and Release Readiness

### Task 18: Complete the composition root and lifecycle integration

**Files:**
- Modify: `includes/Plugin.php`
- Modify: `cinebot-wp.php`
- Create: `tests/Integration/PluginIntegrationTest.php`

**Interfaces:**
- `Plugin::boot()` wires exactly one instance of every repository/service/page.
- Consumes the static `Plugin::activate(): void` and `Plugin::deactivate(): void` lifecycle entry points implemented in Task 2; no second lifecycle contract is introduced.

- [ ] **Step 1: Write failing composition tests**

Assert registered admin menu hook, cron hook, settings update hook, both shortcodes, public/private AJAX hooks, admin AJAX hooks, activation schema, and deactivation cron cleanup. Assert the main file registers only the two `Plugin` lifecycle callbacks. Calling `boot()` twice must not duplicate callbacks.

- [ ] **Step 2: Run and observe failure**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter PluginIntegrationTest
```

- [ ] **Step 3: Wire the complete plugin**

Construct dependencies in this order: `$wpdb` → repositories → settings/API/poster/log → sync/cron → admin pages/menu → frontend handlers. Keep WordPress hook registration in page/service `register()` methods; `Plugin` only composes and calls them.

- [ ] **Step 4: Run tests and commit**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter PluginIntegrationTest
rtk docker compose run --rm php composer check
rtk git add cinebot-wp.php includes/Plugin.php tests/Integration/PluginIntegrationTest.php
rtk git commit -m "feat: compose cinebot wordpress plugin"
```

### Task 19: Add CI, translations, and final end-to-end verification

**Files:**
- Create: `.github/workflows/ci.yml`
- Create: `languages/cinebot-wp.pot`
- Create: `tests/Integration/CinebotEndToEndTest.php`
- Modify: `composer.json`
- Create: `README.md`

**Interfaces:**
- Composer scripts: `test`, `test:unit`, `test:integration`, `check`.
- CI matrix uses exact pairs: PHP 7.4 / WordPress 6.0.12, PHP 8.0 / WordPress 6.4.8, PHP 8.1 / WordPress 6.8.6, and PHP 8.2 / WordPress 6.9.5.

- [ ] **Step 1: Write the failing end-to-end test**

The test must:

1. Activate the plugin and assert seven tables plus 62 event types.
2. Save encrypted API settings.
3. Import `cinebot-sample.json` through `SyncService::syncPayload()`.
4. Assert Dashboard counters and successful latest log.
5. Render `[cinebot_programmazione tipo="45" comune="Bassano del Grappa"]`.
6. Assert `DONNE & UOMINI`, venue, formatted date, and poster URL are present.
7. Create a manual title, re-import, and assert its title remains unchanged.
8. Remove one API event, re-import, and assert it is retained with `sync_active=0` and absent from shortcode output.
9. Restore that event, re-import, and assert the same local ID is active and visible again.
10. Add one future event with `stato != 3` and assert it is never public.
11. Deactivate and assert data remains while cron is removed.

- [ ] **Step 2: Run and observe any integration gaps**

```powershell
rtk docker compose run --rm php composer test:integration -- --filter CinebotEndToEndTest
```

Expected: PASS because all behavior was implemented in prior red/green tasks. This is a cross-component regression gate, not a new behavior task.

- [ ] **Step 3: Close only the identified gaps**

If this regression gate fails, return to the task that owns the behavior and fix it there before continuing. Generate `languages/cinebot-wp.pot` with WP-CLI i18n tooling. Document installation, Docker commands, shortcode attributes, reconciliation semantics, state-3 visibility, single-site scope, cron caveat (WP-Cron is traffic driven), and uninstall data deletion in `README.md`.

Create one GitHub Actions job with the four explicit matrix entries. Each entry sets `PHP_VERSION` and `WP_VERSION`, runs `docker compose build`, `docker compose run --rm php composer install`, and `docker compose run --rm php composer check`. The PHP 8.2 / WordPress 6.9.5 entry uploads `dist/cinebot-wp.zip`. Cache Docker layers and Composer downloads only; never cache MySQL data or `/tmp/wordpress-develop` across matrix entries.

- [ ] **Step 4: Run release verification**

```powershell
rtk docker compose run --rm php composer validate --strict
rtk docker compose run --rm php composer check
rtk docker compose run --rm php php -r "$z=new ZipArchive(); $z->open('dist/cinebot-wp.zip'); exit($z->locateName('cinebot-wp/vendor/autoload.php')===false?0:1);"
rtk git status
rtk git diff
```

Expected: Composer valid, WPCS/PHPStan/PHPUnit/build pass, the ZIP contains no runtime Composer dependency, and diff contains only intended plugin/docs files.

- [ ] **Step 5: Commit release readiness**

```powershell
rtk git add .github languages tests/Integration/CinebotEndToEndTest.php composer.json README.md
rtk git commit -m "test: verify cinebot plugin end to end"
```

---

## Manual Acceptance Checklist

- [ ] Install and activate on a clean WordPress 6.x site; no PHP warning appears.
- [ ] Open Cinebot → API, save credentials, and confirm the password never reappears in HTML/source.
- [ ] Test connection with valid and invalid credentials; both outcomes are actionable and safe.
- [ ] Run manual sync and confirm Dashboard/Log counters match imported data.
- [ ] Confirm a second sync is idempotent.
- [ ] Remove an API event from the fixture, sync, and confirm it remains stored but inactive and hidden; restore it and confirm the same row reactivates.
- [ ] Edit an API title, sync again, and confirm API-owned fields refresh.
- [ ] Create a manual title and venue, sync again, and confirm both remain unchanged.
- [ ] Create/edit/delete nested events, sectors, and prices; invalid child input saves nothing.
- [ ] Disable a predefined type; reactivate the plugin and confirm it stays disabled.
- [ ] Render `[cinebot_programmazione]`; verify responsive cards, lazy images, prices, and no-JS usability.
- [ ] Confirm future events with `stato != 3` and every `sync_active=0` hierarchy are excluded from public output.
- [ ] Filter by type/date/venue, then load more; verify independent behavior with two shortcodes on one page.
- [ ] Deactivate the plugin; data remains and cron is removed.
- [ ] Uninstall from WordPress; all seven tables, options, transients, and scheduled events are gone.
- [ ] Run `composer check` in Docker and install `dist/cinebot-wp.zip` on a clean site without `vendor/`.

## Plan Self-Review

- **Spec coverage:** All seven InnoDB tables, 62 defaults, encryption, BASIC AUTH, optional frontend, reconciliation/deactivation, public state-3 filtering, atomic locking, cron frequencies, import ownership, six admin sections, shortcodes, AJAX filters, caching, security, i18n, Docker tests, WPCS/PHPStan, build artifact, activation/deactivation, and uninstall map to explicit tasks.
- **Placeholder scan:** No red-flag placeholders remain. Future purchase links and alternative layouts are intentionally excluded from version 1 because the approved design supplied no source URL or required behavior.
- **Type consistency:** Repository DTO returns, `ProgrammazioneCard` projection, hierarchy lookup/delete contracts, `source`/`sync_active` values, sync stats, shortcode filters, lifecycle hooks, option names, and table names are consistent across tasks.
- **Clarification applied:** The approved design’s contradictory phrase “jQuery (vanilla)” is resolved as vanilla JavaScript everywhere, matching the no-build-tool architecture.
- **Review corrections:** Docker provisioning, production autoload, complete repository APIs, one lifecycle entry point, reconciliation cascades, InnoDB, atomic lock, state-3 visibility, incremental admin wiring, named projection, explicit CI matrix, scope boundaries, and project conventions are incorporated.
