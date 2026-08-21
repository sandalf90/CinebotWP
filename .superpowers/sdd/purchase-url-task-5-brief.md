### Task 5: Preserve Purchase URLs Through Admin Event Edits

**Files:**
- Modify: `tests/Integration/TitoloEditPageTest.php:382-449`
- Modify: `includes/Admin/Pages/TitoloEditPage.php:692-725`

**Interfaces:**
- Consumes: `Evento::$urlAcquisto` and repository persistence from Task 2.
- Produces: Existing API and future manually populated purchase URLs survive admin edits while the field remains absent from the form.
- Preserves: New manual events have `idevento=NULL`, `urlAcquisto=NULL`, `source=manual`, `syncActive=1`, and `lastSeenSync=NULL`.

- [ ] **Step 1: Strengthen the existing admin ownership tests**

In `test_new_event_gets_source_manual()`, add:

```php
self::assertNull( $events[0]->urlAcquisto );
```

Rename `test_edit_api_event_keeps_source_api()` to `test_edit_api_event_keeps_source_identity_and_purchase_url()`.

Replace its event setup with:

```php
$event = $this->event( 999, $title_id, $venue, 'api' );
$event->urlAcquisto = 'https://ticket.cinebot.it/martinovich/evento/999/acquista';
$event_id = $this->events->save( $event );
```

Add after its remote-ID assertion:

```php
self::assertSame(
	'https://ticket.cinebot.it/martinovich/evento/999/acquista',
	$events[0]->urlAcquisto
);
```

- [ ] **Step 2: Run the focused admin test and verify RED**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter "test_new_event_gets_source_manual|test_edit_api_event_keeps_source_identity_and_purchase_url"
```

Expected: FAIL because `build_events()` creates a fresh DTO and `persist_hierarchy()` does not copy the stored purchase URL before repository update.

- [ ] **Step 3: Preserve the stored URL for existing events and make the manual default explicit**

In the existing-event branch of `persist_hierarchy()`, add the purchase URL copy with the other stored ownership fields:

```php
$stored                = $existing_events[ $event_id ];
$event->source         = $stored->source;
$event->idevento       = $stored->idevento;
$event->urlAcquisto    = $stored->urlAcquisto;
$event->syncActive     = $stored->syncActive;
$event->lastSeenSync   = $stored->lastSeenSync;
```

In the new-event branch, set the field explicitly:

```php
$event->source         = 'manual';
$event->idevento       = null;
$event->urlAcquisto    = null;
$event->syncActive     = 1;
$event->lastSeenSync   = null;
```

Do not add hidden fields, URL inputs, labels, read-only output, JavaScript templates, or sanitization for `url_acquisto`.

- [ ] **Step 4: Run the complete title editor integration test**

Run:

```bash
rtk docker compose run --rm php composer test:integration -- --filter TitoloEditPageTest
```

Expected: PASS; existing API identity and URL survive, new manual events remain null, and all hierarchy ownership/security tests stay green.

- [ ] **Step 5: Commit admin preservation**

```bash
rtk git add -- includes/Admin/Pages/TitoloEditPage.php tests/Integration/TitoloEditPageTest.php
rtk git commit -m "fix: preserve event purchase URLs in admin edits"
```

---
