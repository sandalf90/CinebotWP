### Task 2: Persist The Nullable Event Purchase URL

**Files:**
- Modify: `tests/Unit/ModelsTest.php:59-78,185-210`
- Modify: `tests/Integration/ScheduleRepositoryTest.php:79-173`
- Modify: `tests/Integration/SchemaInstallerTest.php:70-129,145-225,238-258,334-350`
- Modify: `includes/Models/Evento.php:13-83`
- Modify: `includes/Repositories/EventoRepository.php:47-86`
- Modify: `includes/Database/SchemaInstaller.php:16-53,79-140,223-293`

**Interfaces:**
- Consumes: `CinebotUrlService::buildAcquisto()` output, introduced in Task 1.
- Produces: `Evento::$urlAcquisto` with type `?string` and default `null`.
- Produces: Database key `url_acquisto` in `Evento::fromArray()` and `Evento::toArray()`.
- Produces: `SchemaInstaller::DB_VERSION` with exact value `1.1.0`.
- Produces: Nullable `cinebot_eventi.url_acquisto VARCHAR(500)` with no index.

- [ ] **Step 1: Add failing DTO, repository, and schema assertions**

In the `evento` row returned by `ModelsTest::model_rows()`, add the key directly after `idevento`:

```php
'url_acquisto'      => 'https://ticket.cinebot.it/martinovich/evento/501/acquista',
```

In `test_defaults_preserve_nulls_and_own_manual_reconciliation_state()`, add:

```php
self::assertNull( $evento->urlAcquisto );
```

In `ScheduleRepositoryTest::test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state()`, set the API event URL before save and assert the manual event remains null:

```php
$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/601/acquista';
```

```php
self::assertNull( $stored_manual_event->urlAcquisto );
```

In `SchemaInstallerTest::test_install_creates_approved_schema_and_defaults()`, replace the expected version and add column assertions:

```php
self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
$this->assert_nullable_column( 'eventi', 'url_acquisto' );
$this->assert_column_type( 'eventi', 'url_acquisto', 'varchar(500)' );
```

Add this helper beside `assert_nullable_column()`:

```php
/** Assert the normalized SQL type for one column. */
private function assert_column_type( string $suffix, string $column, string $type ): void {
	$table  = self::$db->prefix . 'cinebot_' . $suffix;
	$result = self::$db->get_row( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	self::assertIsObject( $result );
	self::assertSame( $type, strtolower( (string) $result->Type ) );
}
```

In `test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults()`, add these assertions after the failed and successful attempts respectively:

```php
self::assertFalse( get_option( 'cinebot_wp_db_version' ) );
```

```php
self::assertSame( SchemaInstaller::DB_VERSION, get_option( 'cinebot_wp_db_version' ) );
```

Replace the remaining deactivation assertion of literal `'1.0.0'` with `SchemaInstaller::DB_VERSION`.

- [ ] **Step 2: Run the focused tests and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
rtk docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest|SchemaInstallerTest"
```

Expected: FAIL because `Evento::$urlAcquisto`, `SchemaInstaller::DB_VERSION`, and the database column do not exist; the seed-failure test also exposes that the old installer stores its version too early.

- [ ] **Step 3: Add the event DTO field and exact database mapping**

In `Evento`, add the property after `idevento`:

```php
public ?string $urlAcquisto = null;
```

Add hydration after the `idevento` assignment:

```php
$model->urlAcquisto       = isset( $data['url_acquisto'] ) ? (string) $data['url_acquisto'] : null;
```

Add serialization after the `idevento` key:

```php
'url_acquisto'      => $this->urlAcquisto,
```

- [ ] **Step 4: Persist the field in EventoRepository**

Replace the repository save data and formats with the same existing fields plus `url_acquisto` directly after `idevento`:

```php
$data = array(
	'idevento'          => $event->idevento,
	'url_acquisto'      => $event->urlAcquisto,
	'titolo_id'         => $event->titoloId,
	'inizio'            => $event->inizio,
	'organizzatore_id'  => $event->organizzatoreId,
	'organizzatore_cf'  => $event->organizzatoreCf,
	'locale_id'         => $event->localeId,
	'stato'             => $event->stato,
	'otp'               => $event->otp,
	'controlloaccessi'  => $event->controlloaccessi,
	'mappa'              => $event->mappa,
	'source'             => $event->source,
	'sync_active'        => $manual ? 1 : $event->syncActive,
	'last_seen_sync'     => $manual ? null : $event->lastSeenSync,
	'updated_at'         => $now,
);
$formats = array( '%d', '%s', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );
```

Keep the existing `created_at` append and insert/update branches unchanged.

- [ ] **Step 5: Version the schema and add the nullable column**

At the top of `SchemaInstaller`, add:

```php
public const DB_VERSION = '1.1.0';
```

In the `cinebot_eventi` statement, add directly after `idevento`:

```sql
url_acquisto varchar(500) NULL,
```

Move version persistence from before `seed_event_types()` to after it:

```php
foreach ( $this->schema_statements() as $statement ) {
	dbDelta( $statement );
}

$this->seed_event_types();
$this->store_version();
```

Add this method before `seed_event_types()`:

```php
/** Store the completed schema version without making the option autoload. */
private function store_version(): void {
	if ( ! add_option( 'cinebot_wp_db_version', self::DB_VERSION, '', false ) ) {
		update_option( 'cinebot_wp_db_version', self::DB_VERSION, false );
	}

	if ( self::DB_VERSION !== get_option( 'cinebot_wp_db_version' ) ) {
		throw new RuntimeException(
			esc_html__( 'Cinebot WP could not record its database version.', 'cinebot-wp' )
		);
	}
}
```

- [ ] **Step 6: Run focused model, repository, and schema tests**

Run:

```bash
rtk docker compose run --rm php composer test:unit -- --filter ModelsTest
rtk docker compose run --rm php composer test:integration -- --filter "ScheduleRepositoryTest|SchemaInstallerTest"
```

Expected: PASS; the schema reports nullable `varchar(500)`, API values round-trip exactly, manual values remain null, and failed seeding leaves the DB version unset.

- [ ] **Step 7: Commit event persistence and schema versioning**

```bash
rtk git add -- includes/Models/Evento.php includes/Repositories/EventoRepository.php includes/Database/SchemaInstaller.php tests/Unit/ModelsTest.php tests/Integration/ScheduleRepositoryTest.php tests/Integration/SchemaInstallerTest.php
rtk git commit -m "feat: persist event purchase URLs"
```

---
