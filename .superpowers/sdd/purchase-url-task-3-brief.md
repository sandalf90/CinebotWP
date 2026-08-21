### Task 3: Upgrade Existing Schemas Before Plugin Composition

**Files:**
- Modify: `tests/Integration/SchemaInstallerTest.php:145-225,299-365`
- Modify: `tests/Integration/PluginBootstrapTest.php:8-95`
- Modify: `includes/Database/SchemaInstaller.php:27-53`
- Modify: `includes/Plugin.php:8-93`

**Interfaces:**
- Consumes: `SchemaInstaller::DB_VERSION = '1.1.0'` and idempotent `install()` from Task 2.
- Produces: `SchemaInstaller::upgradeIfNeeded(): void`.
- Produces: `Plugin::render_schema_upgrade_error(): void` as a safe `admin_notices` callback.
- Produces: Boot invariant that repositories, cron, admin pages, and shortcodes are composed only after a successful/current schema check.

- [ ] **Step 1: Add a failing existing-database upgrade test**

Add to `SchemaInstallerTest`:

```php
/** An old schema gains the purchase column without changing its event rows. */
public function test_upgrade_if_needed_preserves_existing_events_and_adds_purchase_url(): void {
	$installer = new SchemaInstaller( self::$db );
	$installer->install();
	$table = self::$db->prefix . 'cinebot_eventi';

	self::$db->insert(
		$table,
		array(
			'idevento'  => 777,
			'titolo_id' => 11,
			'inizio'    => '2026-10-08 21:00:00',
			'locale_id' => 22,
			'source'    => 'api',
		),
		array( '%d', '%d', '%s', '%d', '%s' )
	);
	$event_id = (int) self::$db->insert_id;
	self::$db->query( "ALTER TABLE {$table} DROP COLUMN url_acquisto" );
	update_option( 'cinebot_wp_db_version', '1.0.0', false );

	$installer->upgradeIfNeeded();

	$this->assert_nullable_column( 'eventi', 'url_acquisto' );
	$this->assert_column_type( 'eventi', 'url_acquisto', 'varchar(500)' );
	$row = self::$db->get_row( self::$db->prepare( "SELECT * FROM {$table} WHERE id = %d", $event_id ) );
	self::assertIsObject( $row );
	self::assertSame( '777', (string) $row->idevento );
	self::assertSame( '2026-10-08 21:00:00', $row->inizio );
	self::assertNull( $row->url_acquisto );
	self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
}
```

- [ ] **Step 2: Add a failing no-op and no-downgrade test**

Add to `SchemaInstallerTest`:

```php
/** Current or newer schema versions never invoke installation or downgrade. */
public function test_upgrade_if_needed_is_a_no_op_for_current_or_newer_versions(): void {
	$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
		/** @var int */
		public $engine_checks = 0;

		public function get_results( $query = null, $output = OBJECT ) {
			if ( 'SHOW ENGINES' === $query ) {
				++$this->engine_checks;
			}
			return parent::get_results( $query, $output );
		}
	};
	$db->set_prefix( self::$db->prefix );

	try {
		$installer = new SchemaInstaller( $db );
		update_option( 'cinebot_wp_db_version', SchemaInstaller::DB_VERSION, false );
		$installer->upgradeIfNeeded();
		update_option( 'cinebot_wp_db_version', '9.0.0', false );
		$installer->upgradeIfNeeded();
		self::assertSame( 0, $db->engine_checks );
		self::assertSame( '9.0.0', get_option( 'cinebot_wp_db_version' ) );
	} finally {
		$db->close();
	}
}
```

- [ ] **Step 3: Add a failing plugin fail-safe test**

Add both imports to `PluginBootstrapTest`, then add:

```php
use CinebotWp\Database\SchemaInstaller;
use wpdb;
```

Then add:

```php
/** A failed automatic upgrade stops Cinebot composition without breaking WordPress. */
public function test_failed_schema_upgrade_stops_boot_and_renders_safe_admin_notice(): void {
	global $wpdb;
	$original_db = $wpdb;
	update_option( 'cinebot_wp_db_version', '1.0.0', false );
	wp_set_current_user( 1 );

	$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
		public function get_results( $query = null, $output = OBJECT ) {
			if ( 'SHOW ENGINES' === $query ) {
				return array();
			}
			return parent::get_results( $query, $output );
		}
	};
	$failing_db->set_prefix( $original_db->prefix );
	$wpdb = $failing_db;
	$plugin = ( new ReflectionClass( Plugin::class ) )->newInstanceWithoutConstructor();
	$boot_count = 0;
	$observer = static function () use ( &$boot_count ): void {
		++$boot_count;
	};
	add_action( 'cinebot_wp_booted', $observer );

	try {
		$plugin->boot();
		self::assertSame( 0, $boot_count );
		ob_start();
		do_action( 'admin_notices' );
		$notice = (string) ob_get_clean();
		self::assertStringContainsString( 'could not update its database', $notice );
		self::assertStringNotContainsString( 'InnoDB', $notice );
		self::assertSame( '1.0.0', get_option( 'cinebot_wp_db_version' ) );
	} finally {
		remove_action( 'cinebot_wp_booted', $observer );
		remove_action( 'admin_notices', array( $plugin, 'render_schema_upgrade_error' ) );
		$wpdb = $original_db;
		$failing_db->close();
		update_option( 'cinebot_wp_db_version', SchemaInstaller::DB_VERSION, false );
		wp_set_current_user( 0 );
	}
}
```

- [ ] **Step 4: Run the upgrade tests and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "SchemaInstallerTest|PluginBootstrapTest"
```

Expected: FAIL because `upgradeIfNeeded()` and `render_schema_upgrade_error()` do not exist and `Plugin::boot()` still fires `cinebot_wp_booted` after no migration attempt.

- [ ] **Step 5: Implement the idempotent schema-version gate**

Add to `SchemaInstaller` before `install()`:

```php
/** Install only when the stored schema is older than the current schema. */
public function upgradeIfNeeded(): void {
	$installed = get_option( 'cinebot_wp_db_version', '' );
	if ( is_string( $installed ) && version_compare( $installed, self::DB_VERSION, '>=' ) ) {
		return;
	}

	$this->install();
}
```

Do not call the API, scheduler, or synchronization service from this method.

- [ ] **Step 6: Gate Plugin boot on the schema upgrade**

Add `use Throwable;` to `Plugin.php` and replace `boot()` with:

```php
/** Boot the plugin once, after confirming its schema is current. */
public function boot(): void {
	if ( $this->booted ) {
		return;
	}

	$this->booted = true;
	if ( ! $this->upgrade_schema() ) {
		return;
	}

	self::scheduler()->register();
	self::admin_menu()->register();
	self::shortcodes()->register();
	do_action( 'cinebot_wp_booted' );
}
```

Add these methods after `boot()`:

```php
/** Upgrade the schema or contain the failure to this plugin's boot. */
private function upgrade_schema(): bool {
	global $wpdb;

	try {
		( new SchemaInstaller( $wpdb ) )->upgradeIfNeeded();
		return true;
	} catch ( Throwable $ignored ) {
		add_action( 'admin_notices', array( $this, 'render_schema_upgrade_error' ) );
		return false;
	}
}

/** Render a safe upgrade failure notice to administrators only. */
public function render_schema_upgrade_error(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-error"><p>'
		. esc_html__( 'Cinebot WP could not update its database. The plugin will retry automatically on the next request.', 'cinebot-wp' )
		. '</p></div>';
}
```

Do not log or render `$ignored`; catching it only prevents a site-wide failure.

- [ ] **Step 7: Run schema, bootstrap, and composition tests**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "SchemaInstallerTest|PluginBootstrapTest|PluginIntegrationTest|CronSchedulerTest"
```

Expected: PASS; an old schema is upgraded with row preservation, current/newer versions are no-ops, migration failure suppresses Cinebot composition and exposes only the safe notice, and normal boot remains idempotent.

- [ ] **Step 8: Commit automatic schema upgrades**

```bash
rtk git add -- includes/Database/SchemaInstaller.php includes/Plugin.php tests/Integration/SchemaInstallerTest.php tests/Integration/PluginBootstrapTest.php
rtk git commit -m "feat: upgrade Cinebot schema automatically"
```

---
