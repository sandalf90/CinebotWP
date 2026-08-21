# Task 9 Reviewer Handoff Package

## Scope

Complete Task 9 range from parent `d583f53ca3e96359f518fc53c505c6762bc41bc6` through commits `65d15b9f42842db16831f329b77d33a1ca01c7ab`, `090018558e40aedbdfec7a04934ed53ba8a6cc02`, and `9e40ff84b4f814faed3e0cb9d3d81e34bfb3b523`. Task 9 adds atomic schedule synchronization, locking/results, fixture data, and integration contracts; follow-ups harden behavior and complete failure coverage.

The updated report is `NEEDS_RUNTIME`: the authoritative fixture is valid JSON and staged unchanged, while Docker/PHP/Composer dynamic verification remains unavailable. It additionally records independent manual-venue preservation, no-fetch lock contention, and event-insert failure rollback/logging coverage.

## Commit Metadata

```text
commit 65d15b9f42842db16831f329b77d33a1ca01c7ab
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:05:32 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:05:32 2026 +0200

    feat: synchronize cinebot schedules

commit 090018558e40aedbdfec7a04934ed53ba8a6cc02
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:45:04 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:45:04 2026 +0200

    fix: harden schedule synchronization

commit 9e40ff84b4f814faed3e0cb9d3d81e34bfb3b523
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:50:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:50:47 2026 +0200

    test: complete synchronization failure coverage
```

## Full Stat

Command: `git show --stat --format=fuller 65d15b9 0900185 9e40ff8`

```text
commit 65d15b9f42842db16831f329b77d33a1ca01c7ab
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:05:32 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:05:32 2026 +0200

    feat: synchronize cinebot schedules

 .superpowers/sdd/task-9-report.md     |  23 ++
 includes/Services/SyncLock.php        |  90 ++++++++
 includes/Services/SyncResult.php      |  52 +++++
 includes/Services/SyncService.php     | 423 ++++++++++++++++++++++++++++++++++
 tests/Integration/SyncLockTest.php    |  52 +++++
 tests/Integration/SyncServiceTest.php | 112 +++++++++
 tests/fixtures/cinebot-sample.json    |  62 +++++
 7 files changed, 814 insertions(+)

commit 090018558e40aedbdfec7a04934ed53ba8a6cc02
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:45:04 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:45:04 2026 +0200

    fix: harden schedule synchronization

 .superpowers/sdd/task-9-report.md     |   9 +-
 includes/Services/SyncLock.php        |  19 +++-
 includes/Services/SyncService.php     |  20 ++--
 tests/Integration/SyncLockTest.php    |  18 ++-
 tests/Integration/SyncServiceTest.php | 200 +++++++++++++++++++++++++++++++---
 tests/fixtures/cinebot-sample.json    |  63 +----------
 6 files changed, 233 insertions(+), 96 deletions(-)

commit 9e40ff84b4f814faed3e0cb9d3d81e34bfb3b523
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 18:50:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 18:50:47 2026 +0200

    test: complete synchronization failure coverage

 .superpowers/sdd/task-9-report.md     |  1 +
 tests/Integration/SyncServiceTest.php | 57 +++++++++++++++++++++++++++++++++--
 2 files changed, 55 insertions(+), 3 deletions(-)
```

The committed Task 9 report is coordination evidence represented in the stat and summarized above; it is excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 9 implementation, tests, and authoritative fixture across all three commits.

Command: `git diff --unified=10 d583f53 9e40ff8 -- includes/Services/SyncLock.php includes/Services/SyncResult.php includes/Services/SyncService.php tests/Integration/SyncLockTest.php tests/Integration/SyncServiceTest.php tests/fixtures/cinebot-sample.json`

```diff
diff --git a/includes/Services/SyncLock.php b/includes/Services/SyncLock.php
new file mode 100644
index 0000000..835f859
--- /dev/null
+++ b/includes/Services/SyncLock.php
@@ -0,0 +1,101 @@
+<?php
+/**
+ * Atomic option-backed synchronization lock.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use InvalidArgumentException;
+use wpdb;
+
+/** Coordinates one schedule synchronization owner at a time. */
+final class SyncLock {
+	private const OPTION = 'cinebot_wp_sync_lock';
+
+	/** @var wpdb */
+	private $db;
+
+	/** @var callable():int */
+	private $clock;
+
+	/** Create the lock using the site database connection. */
+	public function __construct( ?wpdb $db = null, ?callable $clock = null ) {
+		global $wpdb;
+		$this->db    = $db ?? $wpdb;
+		$this->clock = $clock ?? static function (): int {
+			return time();
+		};
+	}
+
+	/** Acquire ownership or return null when another valid owner holds it. */
+	public function acquire( int $ttl = 300 ): ?string {
+		if ( $ttl < 1 || $ttl > 3600 ) {
+			throw new InvalidArgumentException( 'Synchronization lock TTL must be between 1 and 3600 seconds.' );
+		}
+
+		$token = bin2hex( random_bytes( 32 ) );
+		$value = wp_json_encode( array( 'token' => $token, 'expires_at' => $this->now() + $ttl ) );
+		if ( is_string( $value ) && add_option( self::OPTION, $value, '', false ) ) {
+			return $token;
+		}
+
+		$stored = $this->stored_value();
+		if ( null === $stored || ! $this->expired( $stored ) || ! $this->delete_exact( $stored ) ) {
+			return null;
+		}
+
+		return is_string( $value ) && add_option( self::OPTION, $value, '', false ) ? $token : null;
+	}
+
+	/** Release ownership only when the exact stored lock belongs to this caller. */
+	public function release( string $token ): bool {
+		$stored = $this->stored_value();
+		if ( null === $stored ) {
+			return false;
+		}
+		$data = json_decode( $stored, true );
+		if ( ! is_array( $data ) || ! isset( $data['token'] ) || ! is_string( $data['token'] ) || ! hash_equals( $data['token'], $token ) ) {
+			return false;
+		}
+
+		return $this->delete_exact( $stored );
+	}
+
+	/** Read the unfiltered option value so compare-and-delete is race safe. */
+	private function stored_value(): ?string {
+		// The table and option name are fixed; the option name is still prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$value = $this->db->get_var( $this->db->prepare( "SELECT option_value FROM {$this->db->options} WHERE option_name = %s", self::OPTION ) );
+		return is_string( $value ) ? $value : null;
+	}
+
+	/** Return whether a correctly formed lock has expired. */
+	private function expired( string $stored ): bool {
+		$data = json_decode( $stored, true );
+		return is_array( $data )
+			&& isset( $data['token'], $data['expires_at'] )
+			&& is_string( $data['token'] )
+			&& is_int( $data['expires_at'] )
+			&& $data['expires_at'] <= $this->now();
+	}
+
+	/** Return the injected UTC epoch for deterministic expiry checks. */
+	private function now(): int {
+		return (int) call_user_func( $this->clock );
+	}
+
+	/** Delete a lock only if no owner changed its exact serialized value. */
+	private function delete_exact( string $stored ): bool {
+		// The table is trusted and both option values are prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return 1 === $this->db->query(
+			$this->db->prepare(
+				"DELETE FROM {$this->db->options} WHERE option_name = %s AND option_value = %s",
+				self::OPTION,
+				$stored
+			)
+		);
+	}
+}
diff --git a/includes/Services/SyncResult.php b/includes/Services/SyncResult.php
new file mode 100644
index 0000000..a216170
--- /dev/null
+++ b/includes/Services/SyncResult.php
@@ -0,0 +1,52 @@
+<?php
+/**
+ * Synchronization outcome value object.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+/** Represents the safe public outcome of one synchronization attempt. */
+final class SyncResult {
+	/** @var string */
+	private $status;
+
+	/** @var array<string,int> */
+	private $stats;
+
+	/** @var string */
+	private $message;
+
+	/** Create a synchronization outcome. */
+	public function __construct( string $status, array $stats = array(), string $message = '' ) {
+		$this->status  = $status;
+		$this->stats   = array(
+			'titoli_added'   => max( 0, (int) ( $stats['titoli_added'] ?? 0 ) ),
+			'titoli_updated' => max( 0, (int) ( $stats['titoli_updated'] ?? 0 ) ),
+			'eventi_added'   => max( 0, (int) ( $stats['eventi_added'] ?? 0 ) ),
+			'eventi_updated' => max( 0, (int) ( $stats['eventi_updated'] ?? 0 ) ),
+		);
+		$this->message = $message;
+	}
+
+	/** Return whether this synchronization completed successfully. */
+	public function isSuccess(): bool {
+		return 'success' === $this->status;
+	}
+
+	/** Return the machine-readable outcome status. */
+	public function status(): string {
+		return $this->status;
+	}
+
+	/** Return the documented synchronization counters. */
+	public function stats(): array {
+		return $this->stats;
+	}
+
+	/** Return a safe human-readable outcome message. */
+	public function message(): string {
+		return $this->message;
+	}
+}
diff --git a/includes/Services/SyncService.php b/includes/Services/SyncService.php
new file mode 100644
index 0000000..e2d799d
--- /dev/null
+++ b/includes/Services/SyncService.php
@@ -0,0 +1,429 @@
+<?php
+/**
+ * Transactional Cinebot schedule synchronization.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Services;
+
+use CinebotWp\Models\Evento;
+use CinebotWp\Models\Prezzo;
+use CinebotWp\Models\Settore;
+use CinebotWp\Models\Titolo;
+use CinebotWp\Repositories\EventoRepository;
+use CinebotWp\Repositories\LocaleRepository;
+use CinebotWp\Repositories\PrezzoRepository;
+use CinebotWp\Repositories\SettoreRepository;
+use CinebotWp\Repositories\SyncLogRepository;
+use CinebotWp\Repositories\TitoloRepository;
+use InvalidArgumentException;
+use RuntimeException;
+use Throwable;
+use wpdb;
+
+/** Imports the full API hierarchy and reconciles inactive API-owned rows. */
+final class SyncService {
+	/** @var wpdb */
+	private $db;
+	/** @var ApiClient|null */
+	private $api;
+	/** @var TitoloRepository */
+	private $titles;
+	/** @var EventoRepository */
+	private $events;
+	/** @var SettoreRepository */
+	private $sectors;
+	/** @var PrezzoRepository */
+	private $prices;
+	/** @var LocaleRepository */
+	private $venues;
+	/** @var SyncLogRepository */
+	private $logs;
+	/** @var SyncLock */
+	private $lock;
+	/** @var LocandinaService */
+	private $posters;
+
+	/** Create the service with concrete defaults and injectable test collaborators. */
+	public function __construct(
+		wpdb $db,
+		?ApiClient $api = null,
+		?TitoloRepository $titles = null,
+		?EventoRepository $events = null,
+		?SettoreRepository $sectors = null,
+		?PrezzoRepository $prices = null,
+		?LocaleRepository $venues = null,
+		?SyncLogRepository $logs = null,
+		?SyncLock $lock = null,
+		?LocandinaService $posters = null
+	) {
+		$this->db      = $db;
+		$this->api     = $api;
+		$this->titles  = $titles ?? new TitoloRepository( $db );
+		$this->events  = $events ?? new EventoRepository( $db );
+		$this->sectors = $sectors ?? new SettoreRepository( $db );
+		$this->prices  = $prices ?? new PrezzoRepository( $db );
+		$this->venues  = $venues ?? new LocaleRepository( $db );
+		$this->logs    = $logs ?? new SyncLogRepository( $db );
+		$this->lock    = $lock ?? new SyncLock( $db );
+		$this->posters = $posters ?? new LocandinaService();
+	}
+
+	/** Fetch and synchronize the configured remote programming payload. */
+	public function sync(): SyncResult {
+		$owner = $this->lock->acquire();
+		if ( null === $owner ) {
+			return new SyncResult( 'locked', array(), 'A schedule synchronization is already running.' );
+		}
+		try {
+			if ( null === $this->api ) {
+				throw new RuntimeException( 'Synchronization API client is unavailable.' );
+			}
+			return $this->synchronize( $this->api->fetchProgrammazione() );
+		} catch ( Throwable $exception ) {
+			$log_id = null;
+			try {
+				$log_id = $this->logs->start();
+			} catch ( Throwable $ignored ) {
+				// Return the safe API outcome even when history is unavailable.
+			}
+			return $this->record_failure( $log_id, array() );
+		} finally {
+			$this->lock->release( $owner );
+		}
+	}
+
+	/** Synchronize an already decoded API payload. */
+	public function syncPayload( array $payload ): SyncResult {
+		$owner = $this->lock->acquire();
+		if ( null === $owner ) {
+			return new SyncResult( 'locked', array(), 'A schedule synchronization is already running.' );
+		}
+		try {
+			return $this->synchronize( $payload );
+		} finally {
+			$this->lock->release( $owner );
+		}
+	}
+
+	/** Validate, persist, reconcile, commit, and record one owned payload. */
+	private function synchronize( array $payload ): SyncResult {
+		$stats = array( 'titoli_added' => 0, 'titoli_updated' => 0, 'eventi_added' => 0, 'eventi_updated' => 0 );
+		$log_id = null;
+		$started = false;
+		try {
+			$this->validate_payload( $payload );
+			$log_id = $this->logs->start( $this->canonical_hash( $payload ) );
+			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
+				throw new RuntimeException( 'Unable to start synchronization transaction.' );
+			}
+			$started = true;
+			$token = $this->sync_token();
+			foreach ( $payload['programmazione'] as $envelope ) {
+				$this->sync_frontend( $envelope, $token, $stats );
+			}
+			if ( false === $this->db->query( 'COMMIT' ) ) {
+				throw new RuntimeException( 'Unable to commit synchronization transaction.' );
+			}
+			$started = false;
+			$this->clear_cache();
+			$this->logs->finish( $log_id, 'success', $stats );
+			return new SyncResult( 'success', $stats, 'Schedule synchronization completed.' );
+		} catch ( Throwable $exception ) {
+			$rollback_failed = false;
+			if ( $started ) {
+				try {
+					$rollback_failed = false === $this->db->query( 'ROLLBACK' );
+				} catch ( Throwable $ignored ) {
+					$rollback_failed = true;
+				}
+			}
+			return $this->record_failure( $log_id, $stats, $rollback_failed );
+		}
+	}
+
+	/** Persist one frontend and deactivate API rows no longer represented by it. */
+	private function sync_frontend( array $envelope, string $token, array &$stats ): void {
+		$frontend = $this->positive_int( $envelope['frontend'] ?? null, 'frontend' );
+		foreach ( $this->child_array( $envelope, 'titoli' ) as $data ) {
+			if ( ! is_array( $data ) ) {
+				throw new InvalidArgumentException( 'Invalid title data.' );
+			}
+			$this->sync_title( $data, $envelope, $frontend, $token, $stats );
+		}
+		$gone_titles = $this->titles->deactivateUnseenApi( $frontend, $token );
+		$gone_events = $this->events->deactivateByTitoloIds( $gone_titles );
+		$gone_sectors = $this->sectors->deactivateByEventoIds( $gone_events );
+		$this->prices->deactivateBySettoreIds( $gone_sectors );
+	}
+
+	/** Persist one API title and its entire descendant hierarchy. */
+	private function sync_title( array $data, array $envelope, int $frontend, string $token, array &$stats ): void {
+		$remote = $this->positive_int( $data['idtitolo'] ?? null, 'title' );
+		$existing = $this->titles->findByRemoteId( $remote );
+		if ( null !== $existing && 'manual' === $existing->source ) {
+			return;
+		}
+		$title = null !== $existing ? $existing : new Titolo();
+		$this->map_title( $title, $data, $envelope, $frontend, $token );
+		$changed = null === $existing || ! hash_equals( (string) $existing->syncHash, (string) $title->syncHash ) || 1 !== $existing->syncActive;
+		$title_id = $this->titles->save( $title );
+		if ( null === $existing ) {
+			++$stats['titoli_added'];
+		} elseif ( $changed ) {
+			++$stats['titoli_updated'];
+		}
+		foreach ( $this->child_array( $data, 'eventi' ) as $event_data ) {
+			if ( ! is_array( $event_data ) ) {
+				throw new InvalidArgumentException( 'Invalid event data.' );
+			}
+			$this->sync_event( $event_data, $title_id, $token, $stats );
+		}
+		$gone_events = $this->events->deactivateUnseenApi( $title_id, $token );
+		$gone_sectors = $this->sectors->deactivateByEventoIds( $gone_events );
+		$this->prices->deactivateBySettoreIds( $gone_sectors );
+	}
+
+	/** Persist one event, its venue, sectors, and prices. */
+	private function sync_event( array $data, int $title_id, string $token, array &$stats ): void {
+		$remote = $this->positive_int( $data['idevento'] ?? null, 'event' );
+		$existing = $this->events->findByRemoteId( $remote );
+		if ( null !== $existing && 'manual' === $existing->source ) {
+			return;
+		}
+		$event = null !== $existing ? $existing : new Evento();
+		$event->idevento = $remote;
+		$event->titoloId = $title_id;
+		$event->inizio = $this->required_string( $data, 'inizio' );
+		$event->organizzatoreId = $this->nullable_int( $data, 'organizzatoreId' );
+		$event->organizzatoreCf = $this->nullable_string( $data, 'organizzatoreCf' );
+		$event->localeId = $this->venues->upsertApi( $data );
+		$event->stato = $this->nullable_int( $data, 'stato' );
+		$event->otp = $this->nullable_int( $data, 'otp' );
+		$event->controlloaccessi = $this->nullable_int( $data, 'controlloaccessi' );
+		$event->mappa = $this->nullable_int( $data, 'mappa' );
+		$event->source = 'api';
+		$event->syncActive = 1;
+		$event->lastSeenSync = $token;
+		$changed = null === $existing || $this->event_changed( $existing, $event );
+		$event_id = $this->events->save( $event );
+		if ( null === $existing ) {
+			++$stats['eventi_added'];
+		} elseif ( $changed ) {
+			++$stats['eventi_updated'];
+		}
+		foreach ( $this->child_array( $data, 'settori' ) as $sector_data ) {
+			if ( ! is_array( $sector_data ) ) {
+				throw new InvalidArgumentException( 'Invalid sector data.' );
+			}
+			$this->sync_sector( $sector_data, $event_id, $token );
+		}
+		$gone_sectors = $this->sectors->deactivateUnseenApi( $event_id, $token );
+		$this->prices->deactivateBySettoreIds( $gone_sectors );
+	}
+
+	/** Persist one sector and reconcile its prices. */
+	private function sync_sector( array $data, int $event_id, string $token ): void {
+		$remote = $this->positive_int( $data['idsettore'] ?? null, 'sector' );
+		$existing = $this->sectors->findByRemoteId( $event_id, $remote );
+		if ( null !== $existing && 'manual' === $existing->source ) {
+			return;
+		}
+		$sector = null !== $existing ? $existing : new Settore();
+		$sector->idsettore = $remote;
+		$sector->eventoId = $event_id;
+		$sector->nome = $this->nullable_string( $data, 'settore' );
+		$sector->source = 'api';
+		$sector->syncActive = 1;
+		$sector->lastSeenSync = $token;
+		$sector_id = $this->sectors->save( $sector );
+		foreach ( $this->child_array( $data, 'prezzi' ) as $price_data ) {
+			if ( ! is_array( $price_data ) ) {
+				throw new InvalidArgumentException( 'Invalid price data.' );
+			}
+			$this->sync_price( $price_data, $sector_id, $token );
+		}
+		$this->prices->deactivateUnseenApi( $sector_id, $token );
+	}
+
+	/** Persist one API price. */
+	private function sync_price( array $data, int $sector_id, string $token ): void {
+		$remote = $this->positive_int( $data['idprezzo'] ?? null, 'price' );
+		$existing = $this->prices->findByRemoteId( $sector_id, $remote );
+		if ( null !== $existing && 'manual' === $existing->source ) {
+			return;
+		}
+		$price = null !== $existing ? $existing : new Prezzo();
+		$price->idprezzo = $remote;
+		$price->settoreId = $sector_id;
+		$price->nome = $this->nullable_string( $data, 'prezzo' );
+		$price->tipo = $this->nullable_string( $data, 'tipo' );
+		$price->importo = $this->nullable_string( $data, 'importo' );
+		$price->prevendita = $this->nullable_string( $data, 'prevendita' );
+		$price->stato = $this->nullable_int( $data, 'stato' );
+		$price->source = 'api';
+		$price->syncActive = 1;
+		$price->lastSeenSync = $token;
+		$this->prices->save( $price );
+	}
+
+	/** Map all title DTO fields from its API representation. */
+	private function map_title( Titolo $title, array $data, array $envelope, int $frontend, string $token ): void {
+		$title->idtitolo = $this->positive_int( $data['idtitolo'] ?? null, 'title' );
+		$title->frontendId = $frontend;
+		$title->titolo = $this->required_string( $data, 'titolo' );
+		$title->autore = $this->nullable_string( $data, 'autore' );
+		$title->esecutore = $this->nullable_string( $data, 'esecutore' );
+		$title->durata = $this->nullable_int( $data, 'durata' );
+		$title->scadenza = $this->nullable_int( $data, 'scadenza' );
+		$title->descrizione = $this->nullable_string( $data, 'descrizione' );
+		$title->tipoeventoCodice = $this->nullable_string( $data, 'tipoevento' );
+		$title->locandinaFlag = $this->nullable_int( $data, 'locandina' );
+		$title->locandinaUrl = $this->posters->build( $this->required_string( $envelope, 'host' ), $this->required_string( $envelope, 'path' ), $title->idtitolo, (int) $title->locandinaFlag );
+		$title->cinetel = $this->nullable_string( $data, 'cinetel' );
+		$title->tmdb = $this->nullable_string( $data, 'tmdb' );
+		$title->trailer = $this->nullable_string( $data, 'trailer' );
+		$title->cast = $this->nullable_string( $data, 'cast' );
+		$title->tag = $this->child_array( $data, 'tag' );
+		$title->source = 'api';
+		$title->syncHash = $this->canonical_hash( $this->title_hash_data( $data ) );
+		$title->syncActive = 1;
+		$title->lastSeenSync = $token;
+	}
+
+	/** Reject an invalid top-level payload before any persistence is attempted. */
+	private function validate_payload( array $payload ): void {
+		if ( ! isset( $payload['programmazione'] ) || ! is_array( $payload['programmazione'] ) ) {
+			throw new InvalidArgumentException( 'Invalid programming payload.' );
+		}
+		foreach ( $payload['programmazione'] as $envelope ) {
+			if ( ! is_array( $envelope ) ) {
+				throw new InvalidArgumentException( 'Invalid programming payload.' );
+			}
+			$this->positive_int( $envelope['frontend'] ?? null, 'frontend' );
+			$this->child_array( $envelope, 'titoli' );
+		}
+	}
+
+	/** Return an optional child array or reject a malformed present child. */
+	private function child_array( array $data, string $key ): array {
+		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
+			return array();
+		}
+		if ( ! is_array( $data[ $key ] ) ) {
+			throw new InvalidArgumentException( 'Invalid programming payload.' );
+		}
+		return $data[ $key ];
+	}
+
+	/** Return a positive integer ID without accepting lossy coercion. */
+	private function positive_int( $value, string $field ): int {
+		if ( is_int( $value ) && $value > 0 ) {
+			return $value;
+		}
+		if ( is_string( $value ) && 1 === preg_match( '/^[1-9][0-9]*$/D', $value ) && (string) (int) $value === $value ) {
+			return (int) $value;
+		}
+		throw new InvalidArgumentException( 'Invalid ' . $field . ' identifier.' );
+	}
+
+	/** Return a required non-empty scalar string. */
+	private function required_string( array $data, string $key ): string {
+		$value = $this->nullable_string( $data, $key );
+		if ( null === $value || '' === $value ) {
+			throw new InvalidArgumentException( 'Invalid programming payload.' );
+		}
+		return $value;
+	}
+
+	/** Return a nullable scalar string. */
+	private function nullable_string( array $data, string $key ): ?string {
+		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
+			return null;
+		}
+		if ( ! is_scalar( $data[ $key ] ) ) {
+			throw new InvalidArgumentException( 'Invalid programming payload.' );
+		}
+		return (string) $data[ $key ];
+	}
+
+	/** Return a nullable integer from a native integer or decimal digit string. */
+	private function nullable_int( array $data, string $key ): ?int {
+		if ( ! array_key_exists( $key, $data ) || null === $data[ $key ] ) {
+			return null;
+		}
+		$value = $data[ $key ];
+		if ( is_int( $value ) || ( is_string( $value ) && 1 === preg_match( '/^-?[0-9]+$/D', $value ) ) ) {
+			return (int) $value;
+		}
+		throw new InvalidArgumentException( 'Invalid programming payload.' );
+	}
+
+	/** Compare stored and mapped event values before counting an update. */
+	private function event_changed( Evento $old, Evento $new ): bool {
+		return $old->titoloId !== $new->titoloId || $old->inizio !== $new->inizio || $old->organizzatoreId !== $new->organizzatoreId || $old->organizzatoreCf !== $new->organizzatoreCf || $old->localeId !== $new->localeId || $old->stato !== $new->stato || $old->otp !== $new->otp || $old->controlloaccessi !== $new->controlloaccessi || $old->mappa !== $new->mappa || 1 !== $old->syncActive;
+	}
+
+	/** Produce a key-order-independent SHA-256 payload hash. */
+	private function canonical_hash( array $data ): string {
+		$this->sort_recursive( $data );
+		$json = wp_json_encode( $data );
+		if ( false === $json ) {
+			throw new RuntimeException( 'Unable to encode synchronization payload.' );
+		}
+		return hash( 'sha256', $json );
+	}
+
+	/** Sort associative keys recursively without changing JSON list order. */
+	private function sort_recursive( array &$data ): void {
+		foreach ( $data as &$value ) {
+			if ( is_array( $value ) ) {
+				$this->sort_recursive( $value );
+			}
+		}
+		unset( $value );
+		if ( array_keys( $data ) !== range( 0, count( $data ) - 1 ) ) {
+			ksort( $data );
+		}
+	}
+
+	/** Exclude children so child-only changes do not count as title updates. */
+	private function title_hash_data( array $data ): array {
+		unset( $data['eventi'] );
+		return $data;
+	}
+
+	/** Generate a 36-character reconciliation token. */
+	private function sync_token(): string {
+		$hex = bin2hex( random_bytes( 16 ) );
+		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20 );
+	}
+
+	/** Delete matching normal and timeout public cache options after a committed sync. */
+	private function clear_cache(): void {
+		// The table is trusted and the two wildcard patterns are prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$result = $this->db->query( $this->db->prepare( "DELETE FROM {$this->db->options} WHERE option_name LIKE %s OR option_name LIKE %s", '_transient_cinebot_prog_%', '_transient_timeout_cinebot_prog_%' ) );
+		if ( false === $result ) {
+			throw new RuntimeException( 'Unable to clear schedule cache.' );
+		}
+	}
+
+	/** Finish an existing log if possible and return an intentionally generic error. */
+	private function record_failure( ?int $log_id, array $stats, bool $rollback_failed = false ): SyncResult {
+		if ( null !== $log_id ) {
+			try {
+				$this->logs->finish(
+					$log_id,
+					'error',
+					$stats,
+					$rollback_failed ? 'Schedule synchronization rollback could not be confirmed.' : 'Schedule synchronization failed.'
+				);
+			} catch ( Throwable $ignored ) {
+				// Returning a safe error is more important than a secondary log failure.
+			}
+		}
+		return new SyncResult( 'error', $stats, 'Schedule synchronization failed.' );
+	}
+}
diff --git a/tests/Integration/SyncLockTest.php b/tests/Integration/SyncLockTest.php
new file mode 100644
index 0000000..f946af6
--- /dev/null
+++ b/tests/Integration/SyncLockTest.php
@@ -0,0 +1,62 @@
+<?php
+/**
+ * Synchronization lock integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+use CinebotWp\Services\SyncLock;
+use WP_UnitTestCase;
+
+/** Verifies atomic lock ownership and expiry recovery. */
+final class SyncLockTest extends WP_UnitTestCase {
+	/** Remove the lock before each test. */
+	public function set_up(): void {
+		parent::set_up();
+		delete_option( 'cinebot_wp_sync_lock' );
+	}
+
+	/** The first owner wins and non-owners cannot release the lock. */
+	public function test_acquire_is_exclusive_and_release_requires_owner_token(): void {
+		$lock = new SyncLock();
+		$token = $lock->acquire();
+
+		self::assertIsString( $token );
+		self::assertSame( 64, strlen( $token ) );
+		self::assertNull( ( new SyncLock() )->acquire() );
+		self::assertFalse( $lock->release( str_repeat( 'a', 64 ) ) );
+		self::assertTrue( $lock->release( $token ) );
+		self::assertFalse( $lock->release( $token ) );
+	}
+
+	/** An expired exact stored value may be reclaimed at the expiry boundary. */
+	public function test_expired_lock_is_reclaimed_at_or_after_expiry(): void {
+		$now = 1700000000;
+		add_option(
+			'cinebot_wp_sync_lock',
+			wp_json_encode( array( 'token' => str_repeat( 'b', 64 ), 'expires_at' => $now ) ),
+			'',
+			false
+		);
+
+		$lock = new SyncLock(
+			null,
+			static function () use ( $now ): int {
+				return $now;
+			}
+		);
+		$token = $lock->acquire( 1 );
+		self::assertIsString( $token );
+		self::assertNotSame( str_repeat( 'b', 64 ), $token );
+		self::assertTrue( $lock->release( $token ) );
+	}
+
+	/** Invalid TTL values fail before an option is created. */
+	public function test_invalid_ttl_is_rejected(): void {
+		$lock = new SyncLock();
+		$this->expectException( \InvalidArgumentException::class );
+		$lock->acquire( 0 );
+	}
+}
diff --git a/tests/Integration/SyncServiceTest.php b/tests/Integration/SyncServiceTest.php
new file mode 100644
index 0000000..c9a4dbf
--- /dev/null
+++ b/tests/Integration/SyncServiceTest.php
@@ -0,0 +1,333 @@
+<?php
+/**
+ * Schedule synchronization integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Fixtures use trusted, fixed plugin table identifiers.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Repositories\EventoRepository;
+use CinebotWp\Repositories\LocaleRepository;
+use CinebotWp\Repositories\PrezzoRepository;
+use CinebotWp\Repositories\SettoreRepository;
+use CinebotWp\Repositories\SyncLogRepository;
+use CinebotWp\Repositories\TitoloRepository;
+use CinebotWp\Services\ApiClient;
+use CinebotWp\Services\SettingsService;
+use CinebotWp\Services\SyncService;
+use WP_UnitTestCase;
+use wpdb;
+
+/** Verifies a complete transactional import and its visible state. */
+final class SyncServiceTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** Install a clean schedule schema for every test. */
+	public function set_up(): void {
+		parent::set_up();
+		global $wpdb;
+		self::$db = $wpdb;
+		( new SchemaInstaller( self::$db ) )->install();
+		foreach ( array( 'prezzi', 'settori', 'eventi', 'titoli', 'locali', 'sync_log' ) as $suffix ) {
+			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
+		}
+		delete_option( 'cinebot_wp_sync_lock' );
+	}
+
+	/** Imports every authoritative hierarchy row, mapped source fields, and success log. */
+	public function test_imports_complete_fixture_and_records_success(): void {
+		$result = $this->service()->syncPayload( $this->fixture() );
+
+		self::assertTrue( $result->isSuccess() );
+		self::assertSame(
+			array( 'titoli_added' => 17, 'titoli_updated' => 0, 'eventi_added' => 19, 'eventi_updated' => 0 ),
+			$result->stats()
+		);
+		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
+		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
+		self::assertSame( 'DONNE & UOMINI', $title->titolo );
+		self::assertSame( 'api', $title->source );
+		self::assertSame( 50, $title->frontendId );
+		self::assertSame( 'https://ticket.cinebot.it/martinovich/titolo/491/locandina', $title->locandinaUrl );
+		self::assertSame( 'api', $event->source );
+		self::assertSame( 3, $event->stato );
+		self::assertSame( 'Cinema Martinovich', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
+		$sector = ( new SettoreRepository( self::$db ) )->findByEventoId( $event->id )[0];
+		self::assertSame( 'Posto unico', $sector->nome );
+		$price = ( new PrezzoRepository( self::$db ) )->findBySettoreId( $sector->id )[0];
+		self::assertSame( 'Donne & Uomini INT ON', $price->nome );
+		self::assertSame( '22.00', $price->importo );
+		self::assertSame( 'success', ( new SyncLogRepository( self::$db ) )->latest()->status );
+	}
+
+	/** Re-importing canonical-equivalent input is idempotent and tracks no title update. */
+	public function test_identical_payload_is_idempotent_and_hash_is_key_order_invariant(): void {
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		$first_hash = ( new SyncLogRepository( self::$db ) )->latest()->payloadHash;
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0] = array_reverse( $payload['programmazione'][0]['titoli'][0], true );
+		$result = $this->service()->syncPayload( $payload );
+
+		self::assertTrue( $result->isSuccess() );
+		self::assertSame( 0, $result->stats()['titoli_updated'] );
+		self::assertSame( 17, ( new TitoloRepository( self::$db ) )->count() );
+		self::assertSame( $first_hash, ( new SyncLogRepository( self::$db ) )->latest()->payloadHash );
+	}
+
+	/** Invalid input creates no hierarchy rows and reports a safe error. */
+	public function test_malformed_payload_rolls_back_without_leaking_payload_data(): void {
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;
+		$result = $this->service()->syncPayload( $payload );
+
+		self::assertFalse( $result->isSuccess() );
+		self::assertSame( 'error', $result->status() );
+		self::assertStringNotContainsString( 'idevento', $result->message() );
+		self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
+		self::assertSame( 'error', ( new SyncLogRepository( self::$db ) )->latest()->status );
+	}
+
+	/** Top-level validation fails safely before creating a synchronization log. */
+	public function test_invalid_top_level_payload_returns_safe_error_without_log(): void {
+		foreach ( array( array( 'programmazione' => 'invalid' ), array( 'programmazione' => array( 'invalid-envelope' ) ) ) as $payload ) {
+			$result = $this->service()->syncPayload( $payload );
+
+			self::assertSame( 'error', $result->status() );
+			self::assertSame( 'Schedule synchronization failed.', $result->message() );
+			self::assertNull( ( new SyncLogRepository( self::$db ) )->latest() );
+		}
+	}
+
+	/** Changed API-owned title and event fields update exactly once. */
+	public function test_changed_api_rows_update_and_increment_stats(): void {
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0]['titolo'] = 'Updated API title';
+		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['stato'] = 2;
+
+		$result = $this->service()->syncPayload( $payload );
+		self::assertSame( 1, $result->stats()['titoli_updated'] );
+		self::assertSame( 1, $result->stats()['eventi_updated'] );
+		self::assertSame( 'Updated API title', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->titolo );
+		self::assertSame( 2, ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 )->stato );
+	}
+
+	/** API synchronization never overwrites a matching manual title or venue. */
+	public function test_manual_title_and_venue_remain_untouched(): void {
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
+		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'source' => 'manual', 'titolo' => 'Manual title' ), array( 'id' => $title->id ) );
+		self::$db->update( self::$db->prefix . 'cinebot_locali', array( 'source' => 'manual', 'nome' => 'Manual venue' ), array( 'id' => $event->localeId ) );
+
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		self::assertSame( 'Manual title', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->titolo );
+		self::assertSame( 'Manual venue', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
+	}
+
+	/** A manual venue remains unchanged while its API-owned title reaches venue upsert. */
+	public function test_manual_venue_remains_untouched_for_api_owned_title(): void {
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		$title = ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 );
+		$event = ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 );
+		self::$db->update( self::$db->prefix . 'cinebot_locali', array( 'source' => 'manual', 'nome' => 'Manual venue' ), array( 'id' => $event->localeId ) );
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['locale'] = 'Changed API venue';
+
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( 'api', ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->source );
+		self::assertSame( 'Manual venue', ( new LocaleRepository( self::$db ) )->find( $event->localeId )->nome );
+	}
+
+	/** Missing optional title and hierarchy arrays are accepted as empty arrays. */
+	public function test_missing_optional_arrays_are_treated_as_empty(): void {
+		$payload = $this->fixture();
+		$title = &$payload['programmazione'][0]['titoli'][0];
+		unset( $title['tag'], $title['cast'], $title['eventi'] );
+		unset( $payload['programmazione'][0]['titoli'][1]['eventi'][0]['settori'] );
+		unset( $payload['programmazione'][0]['titoli'][2]['eventi'][0]['settori'][0]['prezzi'] );
+		unset( $title );
+
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( array(), ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->tag );
+	}
+
+	/** Each disappearing API hierarchy level is deactivated and returns with its local ID. */
+	public function test_reconciliation_deactivates_and_reactivates_each_hierarchy_level(): void {
+		$payload = $this->fixture();
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		$title_repo = new TitoloRepository( self::$db );
+		$event_repo = new EventoRepository( self::$db );
+		$sector_repo = new SettoreRepository( self::$db );
+		$price_repo = new PrezzoRepository( self::$db );
+		$title = $title_repo->findByRemoteId( 491 );
+		$event = $event_repo->findByRemoteId( 2920 );
+		$sector = $sector_repo->findByEventoId( $event->id )[0];
+		$price = $price_repo->findBySettoreId( $sector->id )[0];
+
+		$without_price = $payload;
+		$without_price['programmazione'][0]['titoli'][0]['eventi'][0]['settori'][0]['prezzi'] = array();
+		self::assertTrue( $this->service()->syncPayload( $without_price )->isSuccess() );
+		self::assertSame( 0, $price_repo->findBySettoreId( $sector->id )[0]->syncActive );
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( $price->id, $price_repo->findBySettoreId( $sector->id )[0]->id );
+		self::assertSame( 1, $price_repo->findBySettoreId( $sector->id )[0]->syncActive );
+
+		$without_sector = $payload;
+		$without_sector['programmazione'][0]['titoli'][0]['eventi'][0]['settori'] = array();
+		self::assertTrue( $this->service()->syncPayload( $without_sector )->isSuccess() );
+		self::assertSame( 0, $sector_repo->findByEventoId( $event->id )[0]->syncActive );
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( $sector->id, $sector_repo->findByEventoId( $event->id )[0]->id );
+
+		$without_event = $payload;
+		$without_event['programmazione'][0]['titoli'][0]['eventi'] = array();
+		self::assertTrue( $this->service()->syncPayload( $without_event )->isSuccess() );
+		self::assertSame( 0, $event_repo->findByRemoteId( 2920 )->syncActive );
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( $event->id, $event_repo->findByRemoteId( 2920 )->id );
+
+		$without_title = $payload;
+		array_shift( $without_title['programmazione'][0]['titoli'] );
+		self::assertTrue( $this->service()->syncPayload( $without_title )->isSuccess() );
+		self::assertSame( 0, $title_repo->findByRemoteId( 491 )->syncActive );
+		self::assertTrue( $this->service()->syncPayload( $payload )->isSuccess() );
+		self::assertSame( $title->id, $title_repo->findByRemoteId( 491 )->id );
+	}
+
+	/** A payload for another frontend cannot deactivate the first frontend's titles. */
+	public function test_frontend_reconciliation_is_isolated(): void {
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		$other = $this->fixture();
+		$other['programmazione'][0]['frontend'] = 51;
+		$other['programmazione'][0]['titoli'] = array( $other['programmazione'][0]['titoli'][0] );
+		$other['programmazione'][0]['titoli'][0]['idtitolo'] = 900491;
+		$other['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 902920;
+		$other['programmazione'][0]['titoli'][0]['eventi'][0]['localeId'] = 900001;
+
+		self::assertTrue( $this->service()->syncPayload( $other )->isSuccess() );
+		self::assertSame( 1, ( new TitoloRepository( self::$db ) )->findByRemoteId( 491 )->syncActive );
+	}
+
+	/** Cache options survive a failed transaction and are removed only after commit. */
+	public function test_cache_is_deleted_only_after_successful_commit(): void {
+		set_transient( 'cinebot_prog_test', 'cached', HOUR_IN_SECONDS );
+		$invalid = $this->fixture();
+		$invalid['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;
+		self::assertSame( 'error', $this->service()->syncPayload( $invalid )->status() );
+		self::assertSame( 'cached', get_transient( 'cinebot_prog_test' ) );
+
+		self::assertTrue( $this->service()->syncPayload( $this->fixture() )->isSuccess() );
+		self::assertFalse( get_transient( 'cinebot_prog_test' ) );
+	}
+
+	/** Lock contention returns before a real API client's transport is invoked. */
+	public function test_lock_contention_returns_locked_without_transport_call(): void {
+		$lock = new \CinebotWp\Services\SyncLock( self::$db );
+		$token = $lock->acquire();
+		$calls = 0;
+		$client = new ApiClient(
+			new SettingsService(),
+			static function ( string $url, array $args ) use ( &$calls ): array {
+				++$calls;
+				return array();
+			}
+		);
+		$result = ( new SyncService( self::$db, $client ) )->sync();
+		self::assertSame( 'locked', $result->status() );
+		self::assertSame( 0, $calls );
+		self::assertTrue( $lock->release( $token ) );
+	}
+
+	/** An event persistence failure rolls back earlier title and venue writes. */
+	public function test_event_insert_failure_rolls_back_partial_hierarchy_and_logs_safely(): void {
+		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			public function query( $query ) {
+				if ( is_string( $query ) && false !== strpos( $query, 'cinebot_eventi' ) && 1 === preg_match( '/^INSERT /i', $query ) ) {
+					return false;
+				}
+				return parent::query( $query );
+			}
+		};
+		$failing_db->set_prefix( self::$db->prefix );
+
+		try {
+			$result = $this->service( $failing_db )->syncPayload( $this->fixture() );
+			self::assertSame( 'error', $result->status() );
+			self::assertSame( 0, ( new TitoloRepository( self::$db ) )->count() );
+			self::assertNull( ( new EventoRepository( self::$db ) )->findByRemoteId( 2920 ) );
+			self::assertSame( 0, ( new LocaleRepository( self::$db ) )->count() );
+			$log = ( new SyncLogRepository( self::$db ) )->latest();
+			self::assertSame( 'error', $log->status );
+			self::assertSame( 'Schedule synchronization failed.', $log->errorMessage );
+		} finally {
+			$failing_db->close();
+		}
+	}
+
+	/** A reported rollback failure remains safe and is recorded without database detail. */
+	public function test_rollback_query_failure_is_recorded_as_safe_error(): void {
+		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			public function query( $query ) {
+				$result = parent::query( $query );
+				return 'ROLLBACK' === $query ? false : $result;
+			}
+		};
+		$failing_db->set_prefix( self::$db->prefix );
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 0;
+
+		try {
+			$result = $this->service( $failing_db )->syncPayload( $payload );
+			self::assertSame( 'error', $result->status() );
+			self::assertSame( 'Schedule synchronization failed.', $result->message() );
+			$log = ( new SyncLogRepository( self::$db ) )->latest();
+			self::assertSame( 'error', $log->status );
+			self::assertSame( 'Schedule synchronization rollback could not be confirmed.', $log->errorMessage );
+			self::assertStringNotContainsString( 'ROLLBACK', $log->errorMessage );
+		} finally {
+			$failing_db->close();
+		}
+	}
+
+	/** Safe result and history errors never expose caller-provided secret content. */
+	public function test_error_result_and_log_do_not_expose_payload_secrets(): void {
+		$payload = $this->fixture();
+		$payload['programmazione'][0]['titoli'][0]['eventi'][0]['idevento'] = 'secret-api-password';
+		$result = $this->service()->syncPayload( $payload );
+		$log = ( new SyncLogRepository( self::$db ) )->latest();
+
+		self::assertStringNotContainsString( 'secret-api-password', $result->message() );
+		self::assertStringNotContainsString( 'secret-api-password', $log->errorMessage );
+	}
+
+	/** Build the service with its concrete repository collaborators. */
+	private function service( ?wpdb $db = null ): SyncService {
+		$db = $db ?? self::$db;
+		return new SyncService(
+			$db,
+			null,
+			new TitoloRepository( $db ),
+			new EventoRepository( $db ),
+			new SettoreRepository( $db ),
+			new PrezzoRepository( $db ),
+			new LocaleRepository( $db ),
+			new SyncLogRepository( $db )
+		);
+	}
+
+	/** Load the approved static fixture. */
+	private function fixture(): array {
+		$decoded = json_decode( (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/cinebot-sample.json' ), true );
+		self::assertIsArray( $decoded );
+		return $decoded;
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
diff --git a/tests/fixtures/cinebot-sample.json b/tests/fixtures/cinebot-sample.json
new file mode 100644
index 0000000..f77e601
--- /dev/null
+++ b/tests/fixtures/cinebot-sample.json
@@ -0,0 +1 @@
+{"programmazione":[{"frontend":50,"host":"ticket.cinebot.it","path":"martinovich","step":2239,"ready":1,"timestampready":"2026-08-01 10:18:00","titoli":[{"idtitolo":491,"titolo":"DONNE & UOMINI","autore":"Andrea Tosatto","esecutore":"Andrea Tosatto","durata":120,"scadenza":0,"descrizione":"Vi sveler\u00f2 tutti i segreti per riuscire a coesistere nonostante l'indubbia, clamorosa diversit\u00e0.","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":2920,"inizio":"2026-10-08 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":1,"locale":"Cinema Martinovich","localeCodice":"0250120220822","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":1,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":1,"settore":"Posto unico","prezzi":[{"idprezzo":171,"prezzo":"Donne & Uomini INT ON","tipo":"I","importo":22,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":511,"titolo":"IO MARTE TU MERCOLE R- EVOLUTION","autore":"D'AMBROSIO FRATONI FUBELLI PAR","esecutore":"FEDERICA G. GIANLUCA S. F.","durata":120,"scadenza":0,"descrizione":"\u201cIo Marte, tu Mercole\u201d \u00e8 una brillante commedia di coppia che porta sul palco, con ironia e ritmo travolgente, il confronto eterno tra universo maschile e femminile. Federica Camba e Gianluca \"Scintilla\" Fubelli danno vita a uno spettacolo vivace e autentico, fatto di sketch, battute pungenti e momenti di irresistibile complicit\u00e0.\r\nAttraverso situazioni quotidiane, dialoghi serrati e aneddoti esilaranti, lo spettacolo esplora le differenze di linguaggio, pensiero e comportamento tra uomini e donne, trasformando incomprensioni e clich\u00e9 in pura comicit\u00e0. Il risultato \u00e8 un viaggio divertente e sorprendentemente veritiero nelle dinamiche di coppia, in cui il pubblico non pu\u00f2 fare a meno di riconoscersi.\r\n\u201cIo Marte, tu Mercole\u201d \u00e8 uno spettacolo leggero ma intelligente, capace di far ridere, riflettere e  soprattutto, celebrare con affetto le infinite sfumature dell\u2019amore e delle relazioni.","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3008,"inizio":"2026-12-05 21:01:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":199,"prezzo":"IO MARTE TU MERCOLE ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":199,"prezzo":"IO MARTE TU MERCOLE ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":200,"prezzo":"IO MARTE TU MERCOLE ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":517,"titolo":"La Valanga","autore":"Tina Merlin","esecutore":"Teatro Bresci","durata":120,"scadenza":0,"descrizione":"La Valanga. Tina Merlin e il disastro del Vajont\r\n\r\nIl 9 ottobre 1963 una frana di 270 milioni di m\u00b3 dal monte Toc precipit\u00f2 nel bacino del Vajont, generando un\u2019onda che caus\u00f2 quasi 2000 vittime e distrusse diversi comuni. Una tragedia prevedibile, denunciata anni prima da Tina Merlin, giornalista coraggiosa che svel\u00f2 con rigore menzogne, interessi, speculazioni e responsabilit\u00e0.","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3009,"inizio":"2026-10-10 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":2,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":518,"titolo":"La Lunga Strada Tour","autore":null,"esecutore":"Massimo Bubola e OF New Trolls","durata":120,"scadenza":0,"descrizione":"La Lunga Strada Tour unisce Massimo Bubola e OF New Trolls in un racconto musicale che intreccia canzoni, memorie e il comune legame con Fabrizio De Andr\u00e9. Un viaggio tra grandi classici della canzone d\u2019autore italiana, interpretati dai protagonisti che li hanno creati e vissuti, in un intenso dialogo con il pubblico. \r\n","tipoevento":"53","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3010,"inizio":"2026-10-23 21:00:00","organizzatoreId":743,"organizzatoreCf":"VSCNRC56L09L736Q","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":212,"prezzo":"Stagione eventi 26\/27 OF","tipo":"I","importo":37,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":519,"titolo":"Aladino e la lampada meravigliosa","autore":"A Lanzillotti  e L lovato","esecutore":"La Compagnia del Villaggio","durata":120,"scadenza":0,"descrizione":"Una principessa superba viene imprigionata da un Mago in un anello magico finch\u00e9 non comprender\u00e0 una preziosa lezione. Secoli dopo, a Baghdad, Aladino trova l\u2019anello e la Lampada Meravigliosa, affronta un perfido Mago e, con l\u2019aiuto dei Geni, salva la principessa risolvendo l\u2019antico enigma che rompe l\u2019incantesimo. \r\n","tipoevento":"51","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3011,"inizio":"2026-10-18 15:00:00","organizzatoreId":275,"organizzatoreCf":"91000010248","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]},{"idevento":3012,"inizio":"2026-10-18 18:00:00","organizzatoreId":275,"organizzatoreCf":"91000010248","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":520,"titolo":"Pino e gli A.I.nticorpi","autore":"Pino e gli Anticorpi","esecutore":"Pino e gli Anticorpi","durata":120,"scadenza":0,"descrizione":"L\u2019Intelligenza Artificiale ci sostituir\u00e0? Tra timori e paradossi, \u201cPino e gli I.A.nticorpi\u201d affronta con ironia il rapporto tra stupidit\u00e0 naturale e tecnologia. I surreali personaggi del duo comico sardo incontrano l\u2019IA in uno spettacolo scritto insieme a lei: una commedia che diverte, provoca e fa riflettere.\r\n\r\n\r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3013,"inizio":"2026-10-31 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":521,"titolo":"Sai la gente \u00e8 strana.","autore":"Monica Bassi","esecutore":"M Bassi  M Santacatterina","durata":120,"scadenza":0,"descrizione":"Un intenso recital per voce e pianoforte dedicato a Mia Martini, una delle interpreti pi\u00f9 amate della musica italiana. Attraverso racconti, emozioni e i suoi brani pi\u00f9 celebri, lo spettacolo ripercorre la vita artistica e umana di un\u2019artista straordinaria, tra successi, fragilit\u00e0 e una passione assoluta per la musica.\r\n","tipoevento":"51","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3014,"inizio":"2026-10-16 21:00:00","organizzatoreId":275,"organizzatoreCf":"91000010248","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":216,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":13,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":522,"titolo":"La scuola dei mariti e delle mogli","autore":"Piergiorgio Piccoli","esecutore":"Theama Teatro","durata":120,"scadenza":0,"descrizione":"Ispirato a La scuola dei mariti e La scuola delle mogli di Moli\u00e8re, lo spettacolo racconta due uomini che educano diversamente le proprie future spose: fiducia e libert\u00e0 da un lato, controllo e rigidit\u00e0 dall\u2019altro. Con ironia affronta relazioni, genere, tradimento e fragilit\u00e0 umane, offrendo una riflessione attuale sulle dinamiche di coppia. \r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3015,"inizio":"2026-11-05 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":523,"titolo":"Viva le donne","autore":"Debora Villa","esecutore":"Debora Villa","durata":120,"scadenza":0,"descrizione":"Debora Villa, con ironia e comicit\u00e0, ripercorre la storia del rapporto tra donna e uomo per indagare origini e conseguenze della misoginia. Tra riferimenti culturali, sociali e storici, lo spettacolo mette in luce le fragilit\u00e0 maschili e le loro ricadute, alternando risate e riflessioni fino a un forte messaggio contro la violenza di genere. ","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3016,"inizio":"2026-11-12 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":524,"titolo":"Non hanno un  dubbio","autore":"Ugo Ripamonti","esecutore":"Luca Bizzarri","durata":120,"scadenza":0,"descrizione":"Nato come podcast sulla campagna elettorale, questo spettacolo \u00e8 diventato un affilato osservatorio satirico della realt\u00e0 contemporanea. Con intelligenza, ironia e continui aggiornamenti all\u2019attualit\u00e0, racconta politica, social network, costume e malcostume del nostro tempo, offrendo uno specchio pungente e mai banale dei cambiamenti della societ\u00e0.","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3017,"inizio":"2026-11-27 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":214,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":35,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":525,"titolo":"Il sequestro. 831 giorni Carlo Celadon","autore":" Marco Gnaccolini ","esecutore":"Teatro Bresci","durata":120,"scadenza":0,"descrizione":"Uno spettacolo intenso che ripercorre il drammatico sequestro di Carlo Celadon, il pi\u00f9 lungo nella storia italiana: 831 giorni di prigionia nelle mani della \u2019Ndrangheta. Un racconto che intreccia memoria, cronaca e storia civile, illuminando una delle pagine pi\u00f9 oscure del Paese e la vicenda umana di un giovane strappato alla libert\u00e0. ","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3018,"inizio":"2027-01-14 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":526,"titolo":"Foibe","autore":"Teatro Bresci","esecutore":"Teatro Bresci","durata":120,"scadenza":0,"descrizione":"Attraverso testimonianze, documenti e racconti, Foibe. Il ricordo ripercorre una delle pagine pi\u00f9 dolorose e controverse della storia italiana del Novecento. Uno spettacolo di memoria civile dedicato alle vittime delle foibe e all\u2019esodo giuliano-dalmata, per riflettere sul valore del ricordo e della conoscenza storica. \r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3019,"inizio":"2027-02-09 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]},{"idtitolo":527,"titolo":"Il ripetente","autore":"Dado","esecutore":"Dado","durata":120,"scadenza":0,"descrizione":"Per il suo nuovo spettacolo, Dado trasforma la vita in una lunga e irresistibile interrogazione. Tra ironia, musica e comicit\u00e0 surreale, racconta errori ripetuti, occasioni mancate e lezioni mai imparate, in un viaggio esilarante tra relazioni, lavoro e quotidianit\u00e0. Perch\u00e9, in fondo, siamo tutti un po\u2019 ripetenti.\r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3020,"inizio":"2027-02-19 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":528,"titolo":"Alieni in Laguna","autore":"Andrea Pennacchi","esecutore":"Andrea Pojana Pennacchi","durata":120,"scadenza":0,"descrizione":"Alieni in Laguna \u00e8 uno spettacolo musicale tra ironia, racconto e riflessione ecologica che esplora il rapporto tra uomo e natura. Attraverso storie di animali, memorie personali, scienza e folklore veneto, invita il pubblico a interrogarsi sul concetto di biodiversit\u00e0 e sul significato stesso di sentirsi \u201calieni\u201d nel mondo contemporaneo. \r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3021,"inizio":"2027-03-13 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":212,"prezzo":"Stagione eventi 26\/27 OF","tipo":"I","importo":37,"prevendita":"2.00","stato":1}]},{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":212,"prezzo":"Stagione eventi 26\/27 OF","tipo":"I","importo":37,"prevendita":"2.00","stato":0}]}]}]},{"idtitolo":529,"titolo":"Corto circuito","autore":"Leonardo Manera","esecutore":"Leonardo Manera","durata":120,"scadenza":0,"descrizione":"In Corto Circuito, Leonardo Manera affronta con ironia le contraddizioni della vita contemporanea, sospesa tra desideri opposti e scelte sempre pi\u00f9 complesse. Attraverso monologhi e personaggi esilaranti, lo spettacolo riflette sul caos del nostro tempo, trasformando dubbi e paradossi quotidiani in irresistibile comicit\u00e0.\r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3022,"inizio":"2027-01-29 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":530,"titolo":"In tutti i sensi","autore":"Roberto Pozzi e M. Pia Timo ","esecutore":"Maria Pia Timo","durata":120,"scadenza":0,"descrizione":"Nel suo nuovo monologo, Maria Pia Timo esplora con ironia e leggerezza il mondo dei sensi e delle percezioni. Tra curiosit\u00e0 scientifiche, ricordi, emozioni e situazioni quotidiane, coinvolge il pubblico in un viaggio divertente e sorprendente alla scoperta di ci\u00f2 che ci lega al mondo e agli altri, sempre guidati dal senso dell\u2019umorismo. \r\n","tipoevento":"45","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3023,"inizio":"2027-03-19 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":6,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":7,"settore":"Platea 1","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":8,"settore":"Platea 2","prezzi":[{"idprezzo":209,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":32,"prevendita":"2.00","stato":1}]},{"idsettore":9,"settore":"Platea 3","prezzi":[{"idprezzo":208,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":27,"prevendita":"2.00","stato":1}]}]}]},{"idtitolo":531,"titolo":"Encanto","autore":null,"esecutore":"Artisti dal Mondo","durata":120,"scadenza":0,"descrizione":"\u00c8 uno spettacolo di circo contemporaneo che unisce acrobatica africana, danza, musica e physical theatre. Il progetto intreccia culture diverse, offrendo ai giovani talenti provenienti da Tanzania e Brasile vere opportunit\u00e0 professionali. Acrobazie spettacolari, piramidi umane vertiginose, salti, equilibrio, immagini potenti, danze dinamiche e coreografie travolgenti rendono l'esperienza memorabile. \r\n","tipoevento":"75","locandina":1,"cinetel":null,"tmdb":null,"tag":[],"trailer":null,"cast":null,"eventi":[{"idevento":3024,"inizio":"2027-04-24 21:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]},{"idevento":3025,"inizio":"2027-04-24 17:00:00","organizzatoreId":277,"organizzatoreCf":"82002690244","localeId":2,"locale":"Sala Da Ponte","localeCodice":"0250120220808","indirizzo":"","cap":"","comune":"Bassano del Grappa","provincia":"VI","mappa":5,"stato":3,"otp":0,"controlloaccessi":1,"settori":[{"idsettore":2,"settore":"Posto unico","prezzi":[{"idprezzo":205,"prezzo":"Stagione eventi 26\/27 ON","tipo":"I","importo":21,"prevendita":"1.00","stato":1}]}]}]}],"tipiabbonamenti":null}],"error":null,"status":200}
\ No newline at end of file
```

## Current Uncommitted Status

Command: `git status --short --branch --untracked-files=all`

```text
## feat/cinebot-wp
 M specs/execution-status.yaml
 M specs/state.yaml
?? .superpowers/sdd/progress.md
?? .superpowers/sdd/task-1-review-package.md
?? .superpowers/sdd/task-1-review.md
?? .superpowers/sdd/task-2-brief.md
?? .superpowers/sdd/task-2-review-package.md
?? .superpowers/sdd/task-2-review.md
?? .superpowers/sdd/task-3-brief.md
?? .superpowers/sdd/task-3-review-package.md
?? .superpowers/sdd/task-3-review.md
?? .superpowers/sdd/task-4-brief.md
?? .superpowers/sdd/task-4-review-package.md
?? .superpowers/sdd/task-4-review.md
?? .superpowers/sdd/task-5-brief.md
?? .superpowers/sdd/task-5-review-package.md
?? .superpowers/sdd/task-5-review.md
?? .superpowers/sdd/task-6-brief.md
?? .superpowers/sdd/task-6-review-package.md
?? .superpowers/sdd/task-6-review.md
?? .superpowers/sdd/task-7-brief.md
?? .superpowers/sdd/task-7-review-package.md
?? .superpowers/sdd/task-7-review.md
?? .superpowers/sdd/task-8-brief.md
?? .superpowers/sdd/task-8-review-package.md
?? .superpowers/sdd/task-8-review.md
?? .superpowers/sdd/task-9-brief.md
?? .superpowers/sdd/task-9-review-package.md
?? .superpowers/sdd/task-9-review.md
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 9 commits. No Task 9 implementation file is currently modified or untracked.
