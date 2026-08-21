### Task 4: Generate Purchase URLs During Transactional Synchronization

**Files:**
- Modify: `tests/Integration/SyncServiceTest.php:43-159`
- Modify: `includes/Services/SyncService.php:161-224,271-293,363-366`

**Interfaces:**
- Consumes: `CinebotUrlService::buildAcquisto(string, string, int): string` from Task 1.
- Consumes: `Evento::$urlAcquisto` and repository persistence from Task 2.
- Produces: Every successfully synchronized API event has a canonical `urlAcquisto`.
- Produces: A host/path change is an event update and increments `eventi_updated`.
- Preserves: Existing transaction rollback, safe result/log messages, cache timing, reconciliation, and manual ownership.

- [ ] **Step 1: Add the exact fixture URL assertion and strengthen idempotence**

In `test_imports_complete_fixture_and_records_success()`, add after the event source assertion:

```php
self::assertSame(
	'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
	$event->urlAcquisto
);
```

In `test_identical_payload_is_idempotent_and_hash_is_key_order_invariant()`, add:

```php
self::assertSame( 0, $result->stats()['eventi_updated'] );
```

- [ ] **Step 2: Add a failing host/path update test**

Add to `SyncServiceTest`:

```php
/** A changed envelope path refreshes the event purchase URL exactly once. */
public function test_changed_path_updates_purchase_url_and_event_stats(): void {
	$payload = $this->fixture();
	$payload['programmazione'][0]['titoli'] = array( $payload['programmazione'][0]['titoli'][0] );
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );

	$payload['programmazione'][0]['path'] = 'sala nuova';
	$result = $this->service()->syncPayload( $payload );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 1, $result->stats()['eventi_updated'] );
	self::assertSame(
		'https://ticket.cinebot.it/sala%20nuova/evento/2920/acquista',
		$event->urlAcquisto
	);
}
```

- [ ] **Step 3: Add failing existing-row backfill and manual-ownership tests**

Add to `SyncServiceTest`:

```php
/** The first sync after upgrade backfills an existing null purchase URL. */
public function test_next_sync_backfills_existing_null_purchase_url(): void {
	$payload = $this->fixture();
	$payload['programmazione'][0]['titoli'] = array( $payload['programmazione'][0]['titoli'][0] );
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
	self::$db->update(
		self::$db->prefix . 'cinebot_eventi',
		array( 'url_acquisto' => null ),
		array( 'id' => $event->id ),
		array( '%s' ),
		array( '%d' )
	);

	$result = $this->service()->syncPayload( $payload );
	$stored = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 1, $result->stats()['eventi_updated'] );
	self::assertSame(
		'https://ticket.cinebot.it/martinovich/evento/2920/acquista',
		$stored->urlAcquisto
	);
}

/** Synchronization never replaces a purchase URL owned by a manual event. */
public function test_manual_event_purchase_url_remains_untouched(): void {
	$payload = $this->fixture();
	self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
	$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
	self::$db->update(
		self::$db->prefix . 'cinebot_eventi',
		array(
			'source'        => 'manual',
			'url_acquisto' => 'https://manual.example.test/acquista',
		),
		array( 'id' => $event->id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	$result = $this->service()->syncPayload( $payload );
	$stored = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );

	self::assertTrue( $result->isSuccess() );
	self::assertSame( 'manual', $stored->source );
	self::assertSame( 'https://manual.example.test/acquista', $stored->urlAcquisto );
	self::assertSame( 0, $result->stats()['eventi_updated'] );
}
```

- [ ] **Step 4: Add a failing invalid-base rollback test**

Add to `SyncServiceTest`:

```php
/** Missing or hostile purchase URL fields roll back without leaking payload data. */
public function test_invalid_purchase_url_base_rolls_back_safely(): void {
	$missing_host = $this->fixture();
	$missing_host['programmazione'][0]['titoli'] = array( $missing_host['programmazione'][0]['titoli'][0] );
	$missing_host['programmazione'][0]['titoli'][0]['locandina'] = 0;
	unset( $missing_host['programmazione'][0]['host'] );

	$hostile_path = $this->fixture();
	$hostile_path['programmazione'][0]['titoli'] = array( $hostile_path['programmazione'][0]['titoli'][0] );
	$hostile_path['programmazione'][0]['titoli'][0]['locandina'] = 0;
	$hostile_path['programmazione'][0]['path'] = '../secret-api-password';

	foreach ( array( $missing_host, $hostile_path ) as $payload ) {
		$result = $this->service()->syncPayload( $payload );
		self::assertSame( 'error', $result->status() );
		self::assertSame( 'Schedule synchronization failed.', $result->message() );
		self::assertStringNotContainsString( 'secret-api-password', $result->message() );
		self::assertNull( ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 ) );
		self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
		self::assertSame( 'Schedule synchronization failed.', ( new SyncLogRepository( self::$db ) )->latest()->errorMessage );
	}
}
```

- [ ] **Step 5: Run SyncServiceTest and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter SyncServiceTest
```

Expected: FAIL because imported events still have `urlAcquisto=NULL`, existing null values are not backfilled, path changes do not increment `eventi_updated`, and invalid purchase-only paths are not yet validated. The manual-ownership assertion already remains green.

- [ ] **Step 6: Propagate the envelope base and generate the event URL**

In `sync_title()`, preserve the manual-title early return, then extract and pass the base:

```php
$existing = $this->titles->findByRemoteId( $remote );
if ( null !== $existing && 'manual' === $existing->source ) {
	return;
}
$host = $this->required_string( $envelope, 'host' );
$path = $this->required_string( $envelope, 'path' );
$title = null !== $existing ? $existing : new Titolo();
$this->map_title( $title, $data, $host, $path, $frontend, $token );
```

Change the event call to:

```php
$this->sync_event( $event_data, $title_id, $host, $path, $token, $stats );
```

Change the event signature and add URL generation after the existing manual-event early return:

```php
private function sync_event( array $data, int $title_id, string $host, string $path, string $token, array &$stats ): void {
	$remote = $this->positive_int( $data['idevento'] ?? null, 'event' );
	$existing = $this->events->findByRemoteId( $remote );
	if ( null !== $existing && 'manual' === $existing->source ) {
		return;
	}
	$event = null !== $existing ? $existing : new Evento();
	$event->idevento = $remote;
	$event->urlAcquisto = $this->urls->buildAcquisto( $host, $path, $remote );
```

Keep all remaining event mapping, persistence, statistics, sectors, and reconciliation code unchanged.

- [ ] **Step 7: Pass the validated base into poster mapping**

Change `map_title()` to this signature:

```php
private function map_title( Titolo $title, array $data, string $host, string $path, int $frontend, string $token ): void {
```

Replace its poster call with:

```php
$title->locandinaUrl = $this->urls->buildLocandina(
	$host,
	$path,
	$title->idtitolo,
	(int) $title->locandinaFlag
);
```

- [ ] **Step 8: Include the purchase URL in event change detection**

Replace `event_changed()` with:

```php
/** Compare stored and mapped event values before counting an update. */
private function event_changed( Evento $old, Evento $new ): bool {
	return $old->titoloId !== $new->titoloId
		|| $old->urlAcquisto !== $new->urlAcquisto
		|| $old->inizio !== $new->inizio
		|| $old->organizzatoreId !== $new->organizzatoreId
		|| $old->organizzatoreCf !== $new->organizzatoreCf
		|| $old->localeId !== $new->localeId
		|| $old->stato !== $new->stato
		|| $old->otp !== $new->otp
		|| $old->controlloaccessi !== $new->controlloaccessi
		|| $old->mappa !== $new->mappa
		|| 1 !== $old->syncActive;
}
```

- [ ] **Step 9: Run the complete synchronization integration test**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter SyncServiceTest
```

Expected: PASS; the fixture URL is exact, repeat import reports zero event updates, an existing null value is backfilled, manual ownership is preserved, a changed path reports one update, and invalid base data produces full rollback with safe messages.

- [ ] **Step 10: Commit transactional URL generation**

```bash
rtk git add -- includes/Services/SyncService.php tests/Integration/SyncServiceTest.php
rtk git commit -m "feat: generate imported event purchase URLs"
```

---
