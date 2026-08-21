# Task 5 Reviewer Handoff Package

## Scope

Complete Task 5 range from parent `89db1bf8d071489442ebe431fb141831be3aaacf` through implementation commit `1c3e3b1d90e58198a338911b35de4e0b140014d6` and fix commit `7bb4fa03eae778b97eff9692a002d7cfac40958e`. Task 5 adds four schedule-hierarchy repositories and their integration contract; the fix enforces conditional reconciliation updates and expands behavioral coverage.

The updated report records blocked Docker/PHP dynamic gates. Static follow-up evidence covers four conditional reconciliation updates, false/one-row result handling, stale-candidate omission, safe failures, complete DTO fields, valid alternate-parent ownership checks, non-recursive deletes, all 13 public projection fields, filtered count/page parity, formatting, and scope.

## Commit Metadata

```text
commit 1c3e3b1d90e58198a338911b35de4e0b140014d6
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:01:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:01:47 2026 +0200

    feat: persist cinebot schedule hierarchy

commit 7bb4fa03eae778b97eff9692a002d7cfac40958e
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:14:18 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:14:18 2026 +0200

    fix: enforce hierarchy repository contracts
```

## Full Stat

Command: `git show --stat --format=fuller 1c3e3b1 7bb4fa0`

```text
commit 1c3e3b1d90e58198a338911b35de4e0b140014d6
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:01:47 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:01:47 2026 +0200

    feat: persist cinebot schedule hierarchy

 .superpowers/sdd/task-5-report.md            |  63 +++++
 includes/Repositories/EventoRepository.php   | 240 ++++++++++++++++
 includes/Repositories/PrezzoRepository.php   | 213 +++++++++++++++
 includes/Repositories/SettoreRepository.php  | 210 ++++++++++++++
 includes/Repositories/TitoloRepository.php   | 320 ++++++++++++++++++++++
 tests/Integration/ScheduleRepositoryTest.php | 394 +++++++++++++++++++++++++++
 6 files changed, 1440 insertions(+)

commit 7bb4fa03eae778b97eff9692a002d7cfac40958e
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 01:14:18 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 01:14:18 2026 +0200

    fix: enforce hierarchy repository contracts

 .superpowers/sdd/task-5-report.md            |  37 +++
 includes/Repositories/EventoRepository.php   |  25 +-
 includes/Repositories/PrezzoRepository.php   |  24 +-
 includes/Repositories/SettoreRepository.php  |  24 +-
 includes/Repositories/TitoloRepository.php   |  24 +-
 tests/Integration/ScheduleRepositoryTest.php | 376 +++++++++++++++++++++++----
 6 files changed, 446 insertions(+), 64 deletions(-)
```

The committed `.superpowers/sdd/task-5-report.md` is coordination evidence represented in the stat and summarized above; it is excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 5 implementation and tests after both commits.

Command: `git diff --unified=10 89db1bf 7bb4fa0 -- includes/Repositories/EventoRepository.php includes/Repositories/PrezzoRepository.php includes/Repositories/SettoreRepository.php includes/Repositories/TitoloRepository.php tests/Integration/ScheduleRepositoryTest.php`

```diff
diff --git a/includes/Repositories/EventoRepository.php b/includes/Repositories/EventoRepository.php
new file mode 100644
index 0000000..aec9f83
--- /dev/null
+++ b/includes/Repositories/EventoRepository.php
@@ -0,0 +1,255 @@
+<?php
+/**
+ * Event persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\Evento;
+use InvalidArgumentException;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists events and exposes title-scoped reconciliation operations.
+ */
+final class EventoRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/** Store the injected database connection. */
+	public function __construct( wpdb $db ) {
+		$this->db = $db;
+		$this->table = $db->prefix . 'cinebot_eventi';
+	}
+
+	/** Find an event by its globally unique API identity. */
+	public function findByRemoteId( int $remoteId ): ?Evento {
+		if ( $remoteId <= 0 ) {
+			return null;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE idevento = %d", $remoteId ), ARRAY_A );
+		return is_array( $row ) ? Evento::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Insert or update an event.
+	 *
+	 * @throws InvalidArgumentException When source is invalid.
+	 * @throws RuntimeException When persistence fails.
+	 */
+	public function save( Evento $event ): int {
+		$this->assert_source( $event->source );
+		$manual = 'manual' === $event->source;
+		$now = current_time( 'mysql', true );
+		$data = array(
+			'idevento' => $event->idevento,
+			'titolo_id' => $event->titoloId,
+			'inizio' => $event->inizio,
+			'organizzatore_id' => $event->organizzatoreId,
+			'organizzatore_cf' => $event->organizzatoreCf,
+			'locale_id' => $event->localeId,
+			'stato' => $event->stato,
+			'otp' => $event->otp,
+			'controlloaccessi' => $event->controlloaccessi,
+			'mappa' => $event->mappa,
+			'source' => $event->source,
+			'sync_active' => $manual ? 1 : $event->syncActive,
+			'last_seen_sync' => $manual ? null : $event->lastSeenSync,
+			'updated_at' => $now,
+		);
+		$formats = array( '%d', '%d', '%s', '%d', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s' );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $event->id ) {
+			$data['created_at'] = $now;
+			$formats[] = '%s';
+			$result = $this->db->insert( $this->table, $data, $formats );
+			$id = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $event->id;
+			if ( $id <= 0 || $event->source !== $this->source_for_id( $id ) ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+		return $id;
+	}
+
+	/**
+	 * Return all events for a title.
+	 *
+	 * @return array<int,Evento>
+	 */
+	public function findByTitoloId( int $titleId ): array {
+		if ( $titleId <= 0 ) {
+			return array();
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE titolo_id = %d ORDER BY inizio ASC, id ASC", $titleId ), ARRAY_A );
+		return array_map(
+			static function ( array $row ): Evento {
+				return Evento::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/** Return whether a positive event belongs to the exact positive title. */
+	public function belongsToTitolo( int $eventId, int $titleId ): bool {
+		if ( $eventId <= 0 || $titleId <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return $eventId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND titolo_id = %d", $eventId, $titleId ) );
+	}
+
+	/** Count events directly owned by a title. */
+	public function countByTitoloId( int $titleId ): int {
+		return $this->count_by( 'titolo_id', $titleId );
+	}
+
+	/** Count events directly assigned to a venue. */
+	public function countByLocaleId( int $localeId ): int {
+		return $this->count_by( 'locale_id', $localeId );
+	}
+
+	/** Delete all events directly owned by a title. */
+	public function deleteByTitoloId( int $titleId ): int {
+		if ( $titleId <= 0 ) {
+			return 0;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->delete( $this->table, array( 'titolo_id' => $titleId ), array( '%d' ) );
+		return false === $result ? 0 : (int) $result;
+	}
+
+	/**
+	 * Deactivate unseen API events under one title.
+	 *
+	 * @return array<int,int> Affected event IDs.
+	 */
+	public function deactivateUnseenApi( int $titleId, string $syncToken ): array {
+		if ( $titleId <= 0 ) {
+			return array();
+		}
+		$where = 'titolo_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)';
+		return $this->deactivate_where( $where, array( $titleId, 'api', 1, $syncToken ) );
+	}
+
+	/**
+	 * Deactivate direct API events for title IDs.
+	 *
+	 * @param array<int,int> $titleIds Parent title IDs.
+	 * @return array<int,int> Affected event IDs.
+	 */
+	public function deactivateByTitoloIds( array $titleIds ): array {
+		$ids = $this->positive_ids( $titleIds );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
+		return $this->deactivate_where( "titolo_id IN ({$placeholders}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) );
+	}
+
+	/** Delete exactly one event by local ID. */
+	public function delete( int $id ): bool {
+		if ( $id <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
+	}
+
+	/** Return the stored ownership source for update validation. */
+	private function source_for_id( int $id ): ?string {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$source = $this->db->get_var( $this->db->prepare( "SELECT source FROM {$this->table} WHERE id = %d", $id ) );
+		return is_string( $source ) ? $source : null;
+	}
+
+	/** Count rows by one fixed internal parent column. */
+	private function count_by( string $column, int $id ): int {
+		if ( $id <= 0 ) {
+			return 0;
+		}
+		// Column is selected only by the two fixed public callers.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE {$column} = %d", $id ) );
+	}
+
+	/**
+	 * Deactivate rows selected by one fixed internal predicate.
+	 *
+	 * @param array<int,mixed> $values Prepared values.
+	 * @return array<int,int> Affected local IDs.
+	 */
+	private function deactivate_where( string $where, array $values ): array {
+		// The where fragment is assembled only by fixed internal callers.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$affected = array();
+		$now = current_time( 'mysql', true );
+		foreach ( $ids as $id ) {
+			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
+			// The predicate is fixed internally and every dynamic value is prepared.
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+			$result = $this->db->query( $this->db->prepare( $sql, array_merge( array( $now, $id ), $values ) ) );
+			if ( false === $result ) {
+				throw $this->reconciliation_exception();
+			}
+			if ( 1 === $result ) {
+				$affected[] = $id;
+			}
+		}
+		return $affected;
+	}
+
+	/**
+	 * Normalize parent IDs for a prepared IN predicate.
+	 *
+	 * @param array<int,mixed> $ids Raw IDs.
+	 * @return array<int,int> Positive unique IDs.
+	 */
+	private function positive_ids( array $ids ): array {
+		return array_values(
+			array_unique(
+				array_filter(
+					array_map( 'intval', $ids ),
+					static function ( int $id ): bool {
+						return $id > 0;
+					}
+				)
+			)
+		);
+	}
+
+	/** Validate the persisted ownership source. */
+	private function assert_source( string $source ): void {
+		if ( ! in_array( $source, array( 'api', 'manual' ), true ) ) {
+			throw new InvalidArgumentException( esc_html__( 'An event source must be api or manual.', 'cinebot-wp' ) );
+		}
+	}
+
+	/** Build a safe persistence exception. */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not save the event. Verify its identifiers and try again.', 'cinebot-wp' ) );
+	}
+
+	/** Build a safe reconciliation exception. */
+	private function reconciliation_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule events. Try again.', 'cinebot-wp' ) );
+	}
+}
diff --git a/includes/Repositories/PrezzoRepository.php b/includes/Repositories/PrezzoRepository.php
new file mode 100644
index 0000000..f68bc53
--- /dev/null
+++ b/includes/Repositories/PrezzoRepository.php
@@ -0,0 +1,229 @@
+<?php
+/**
+ * Price persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\Prezzo;
+use InvalidArgumentException;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists sector prices and reconciliation state.
+ */
+final class PrezzoRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/** Store the injected database connection. */
+	public function __construct( wpdb $db ) {
+		$this->db = $db;
+		$this->table = $db->prefix . 'cinebot_prezzi';
+	}
+
+	/** Find a price by its sector-scoped API identity. */
+	public function findByRemoteId( int $sectorId, int $remoteId ): ?Prezzo {
+		if ( $sectorId <= 0 || $remoteId <= 0 ) {
+			return null;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE settore_id = %d AND idprezzo = %d", $sectorId, $remoteId ), ARRAY_A );
+		return is_array( $row ) ? Prezzo::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Insert or update a price.
+	 *
+	 * @throws InvalidArgumentException When source is invalid.
+	 * @throws RuntimeException When persistence fails.
+	 */
+	public function save( Prezzo $price ): int {
+		$this->assert_source( $price->source );
+		$manual = 'manual' === $price->source;
+		$now = current_time( 'mysql', true );
+		$data = array(
+			'idprezzo' => $price->idprezzo,
+			'settore_id' => $price->settoreId,
+			'nome' => $price->nome,
+			'tipo' => $price->tipo,
+			'importo' => $price->importo,
+			'prevendita' => $price->prevendita,
+			'stato' => $price->stato,
+			'source' => $price->source,
+			'sync_active' => $manual ? 1 : $price->syncActive,
+			'last_seen_sync' => $manual ? null : $price->lastSeenSync,
+			'updated_at' => $now,
+		);
+		$formats = array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $price->id ) {
+			$data['created_at'] = $now;
+			$formats[] = '%s';
+			$result = $this->db->insert( $this->table, $data, $formats );
+			$id = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $price->id;
+			if ( $id <= 0 || $price->source !== $this->source_for_id( $id ) ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+		return $id;
+	}
+
+	/**
+	 * Return all prices for a sector.
+	 *
+	 * @return array<int,Prezzo>
+	 */
+	public function findBySettoreId( int $sectorId ): array {
+		if ( $sectorId <= 0 ) {
+			return array();
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE settore_id = %d ORDER BY id ASC", $sectorId ), ARRAY_A );
+		return array_map(
+			static function ( array $row ): Prezzo {
+				return Prezzo::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/** Return whether a positive price belongs to the exact positive sector. */
+	public function belongsToSettore( int $priceId, int $sectorId ): bool {
+		if ( $priceId <= 0 || $sectorId <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return $priceId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND settore_id = %d", $priceId, $sectorId ) );
+	}
+
+	/** Delete all prices directly owned by a sector. */
+	public function deleteBySettoreId( int $sectorId ): int {
+		if ( $sectorId <= 0 ) {
+			return 0;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->delete( $this->table, array( 'settore_id' => $sectorId ), array( '%d' ) );
+		return false === $result ? 0 : (int) $result;
+	}
+
+	/**
+	 * Deactivate unseen API prices under one sector.
+	 *
+	 * @return array<int,int> Affected price IDs.
+	 */
+	public function deactivateUnseenApi( int $sectorId, string $syncToken ): array {
+		if ( $sectorId <= 0 ) {
+			return array();
+		}
+		return $this->deactivate_where( 'settore_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)', array( $sectorId, 'api', 1, $syncToken ) );
+	}
+
+	/**
+	 * Deactivate direct API prices for sector IDs.
+	 *
+	 * @param array<int,int> $sectorIds Parent sector IDs.
+	 */
+	public function deactivateBySettoreIds( array $sectorIds ): int {
+		$ids = $this->positive_ids( $sectorIds );
+		if ( array() === $ids ) {
+			return 0;
+		}
+		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
+		return count( $this->deactivate_where( "settore_id IN ({$marks}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) ) );
+	}
+
+	/** Delete exactly one price by local ID. */
+	public function delete( int $id ): bool {
+		if ( $id <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
+	}
+
+	/** Return the stored ownership source for update validation. */
+	private function source_for_id( int $id ): ?string {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$source = $this->db->get_var( $this->db->prepare( "SELECT source FROM {$this->table} WHERE id = %d", $id ) );
+		return is_string( $source ) ? $source : null;
+	}
+
+	/**
+	 * Deactivate rows selected by one fixed internal predicate.
+	 *
+	 * @param array<int,mixed> $values Prepared values.
+	 * @return array<int,int> Affected local IDs.
+	 */
+	private function deactivate_where( string $where, array $values ): array {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$affected = array();
+		$now = current_time( 'mysql', true );
+		foreach ( $ids as $id ) {
+			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
+			// The predicate is fixed internally and every dynamic value is prepared.
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+			$result = $this->db->query( $this->db->prepare( $sql, array_merge( array( $now, $id ), $values ) ) );
+			if ( false === $result ) {
+				throw $this->reconciliation_exception();
+			}
+			if ( 1 === $result ) {
+				$affected[] = $id;
+			}
+		}
+		return $affected;
+	}
+
+	/**
+	 * Normalize parent IDs for a prepared IN predicate.
+	 *
+	 * @param array<int,mixed> $ids Raw IDs.
+	 * @return array<int,int> Positive unique IDs.
+	 */
+	private function positive_ids( array $ids ): array {
+		return array_values(
+			array_unique(
+				array_filter(
+					array_map( 'intval', $ids ),
+					static function ( int $id ): bool {
+						return $id > 0;
+					}
+				)
+			)
+		);
+	}
+
+	/** Validate the persisted ownership source. */
+	private function assert_source( string $source ): void {
+		if ( ! in_array( $source, array( 'api', 'manual' ), true ) ) {
+			throw new InvalidArgumentException( esc_html__( 'A price source must be api or manual.', 'cinebot-wp' ) );
+		}
+	}
+
+	/** Build a safe persistence exception. */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not save the price. Verify its identifiers and try again.', 'cinebot-wp' ) );
+	}
+
+	/** Build a safe reconciliation exception. */
+	private function reconciliation_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule prices. Try again.', 'cinebot-wp' ) );
+	}
+}
diff --git a/includes/Repositories/SettoreRepository.php b/includes/Repositories/SettoreRepository.php
new file mode 100644
index 0000000..361d236
--- /dev/null
+++ b/includes/Repositories/SettoreRepository.php
@@ -0,0 +1,226 @@
+<?php
+/**
+ * Sector persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\Settore;
+use InvalidArgumentException;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists event sectors and reconciliation state.
+ */
+final class SettoreRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/** Store the injected database connection. */
+	public function __construct( wpdb $db ) {
+		$this->db = $db;
+		$this->table = $db->prefix . 'cinebot_settori';
+	}
+
+	/** Find a sector by its event-scoped API identity. */
+	public function findByRemoteId( int $eventId, int $remoteId ): ?Settore {
+		if ( $eventId <= 0 || $remoteId <= 0 ) {
+			return null;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE evento_id = %d AND idsettore = %d", $eventId, $remoteId ), ARRAY_A );
+		return is_array( $row ) ? Settore::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Insert or update a sector.
+	 *
+	 * @throws InvalidArgumentException When source is invalid.
+	 * @throws RuntimeException When persistence fails.
+	 */
+	public function save( Settore $sector ): int {
+		$this->assert_source( $sector->source );
+		$manual = 'manual' === $sector->source;
+		$now = current_time( 'mysql', true );
+		$data = array(
+			'idsettore' => $sector->idsettore,
+			'evento_id' => $sector->eventoId,
+			'nome' => $sector->nome,
+			'source' => $sector->source,
+			'sync_active' => $manual ? 1 : $sector->syncActive,
+			'last_seen_sync' => $manual ? null : $sector->lastSeenSync,
+			'updated_at' => $now,
+		);
+		$formats = array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $sector->id ) {
+			$data['created_at'] = $now;
+			$formats[] = '%s';
+			$result = $this->db->insert( $this->table, $data, $formats );
+			$id = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $sector->id;
+			if ( $id <= 0 || $sector->source !== $this->source_for_id( $id ) ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+		return $id;
+	}
+
+	/**
+	 * Return all sectors for an event.
+	 *
+	 * @return array<int,Settore>
+	 */
+	public function findByEventoId( int $eventId ): array {
+		if ( $eventId <= 0 ) {
+			return array();
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE evento_id = %d ORDER BY id ASC", $eventId ), ARRAY_A );
+		return array_map(
+			static function ( array $row ): Settore {
+				return Settore::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/** Return whether a positive sector belongs to the exact positive event. */
+	public function belongsToEvento( int $sectorId, int $eventId ): bool {
+		if ( $sectorId <= 0 || $eventId <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return $sectorId === (int) $this->db->get_var( $this->db->prepare( "SELECT id FROM {$this->table} WHERE id = %d AND evento_id = %d", $sectorId, $eventId ) );
+	}
+
+	/** Delete all sectors directly owned by an event. */
+	public function deleteByEventoId( int $eventId ): int {
+		if ( $eventId <= 0 ) {
+			return 0;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->delete( $this->table, array( 'evento_id' => $eventId ), array( '%d' ) );
+		return false === $result ? 0 : (int) $result;
+	}
+
+	/**
+	 * Deactivate unseen API sectors under one event.
+	 *
+	 * @return array<int,int> Affected sector IDs.
+	 */
+	public function deactivateUnseenApi( int $eventId, string $syncToken ): array {
+		if ( $eventId <= 0 ) {
+			return array();
+		}
+		return $this->deactivate_where( 'evento_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)', array( $eventId, 'api', 1, $syncToken ) );
+	}
+
+	/**
+	 * Deactivate direct API sectors for event IDs.
+	 *
+	 * @param array<int,int> $eventIds Parent event IDs.
+	 * @return array<int,int> Affected sector IDs.
+	 */
+	public function deactivateByEventoIds( array $eventIds ): array {
+		$ids = $this->positive_ids( $eventIds );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$marks = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
+		return $this->deactivate_where( "evento_id IN ({$marks}) AND source = %s AND sync_active = %d", array_merge( $ids, array( 'api', 1 ) ) );
+	}
+
+	/** Delete exactly one sector by local ID. */
+	public function delete( int $id ): bool {
+		if ( $id <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
+	}
+
+	/** Return the stored ownership source for update validation. */
+	private function source_for_id( int $id ): ?string {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$source = $this->db->get_var( $this->db->prepare( "SELECT source FROM {$this->table} WHERE id = %d", $id ) );
+		return is_string( $source ) ? $source : null;
+	}
+
+	/**
+	 * Deactivate rows selected by one fixed internal predicate.
+	 *
+	 * @param array<int,mixed> $values Prepared values.
+	 * @return array<int,int> Affected local IDs.
+	 */
+	private function deactivate_where( string $where, array $values ): array {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$affected = array();
+		$now = current_time( 'mysql', true );
+		foreach ( $ids as $id ) {
+			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
+			// The predicate is fixed internally and every dynamic value is prepared.
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+			$result = $this->db->query( $this->db->prepare( $sql, array_merge( array( $now, $id ), $values ) ) );
+			if ( false === $result ) {
+				throw $this->reconciliation_exception();
+			}
+			if ( 1 === $result ) {
+				$affected[] = $id;
+			}
+		}
+		return $affected;
+	}
+
+	/**
+	 * Normalize parent IDs for a prepared IN predicate.
+	 *
+	 * @param array<int,mixed> $ids Raw IDs.
+	 * @return array<int,int> Positive unique IDs.
+	 */
+	private function positive_ids( array $ids ): array {
+		return array_values(
+			array_unique(
+				array_filter(
+					array_map( 'intval', $ids ),
+					static function ( int $id ): bool {
+						return $id > 0;
+					}
+				)
+			)
+		);
+	}
+
+	/** Validate the persisted ownership source. */
+	private function assert_source( string $source ): void {
+		if ( ! in_array( $source, array( 'api', 'manual' ), true ) ) {
+			throw new InvalidArgumentException( esc_html__( 'A sector source must be api or manual.', 'cinebot-wp' ) );
+		}
+	}
+
+	/** Build a safe persistence exception. */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not save the sector. Verify its identifiers and try again.', 'cinebot-wp' ) );
+	}
+
+	/** Build a safe reconciliation exception. */
+	private function reconciliation_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule sectors. Try again.', 'cinebot-wp' ) );
+	}
+}
diff --git a/includes/Repositories/TitoloRepository.php b/includes/Repositories/TitoloRepository.php
new file mode 100644
index 0000000..43d6af8
--- /dev/null
+++ b/includes/Repositories/TitoloRepository.php
@@ -0,0 +1,336 @@
+<?php
+/**
+ * Title persistence and schedule reads.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\Titolo;
+use CinebotWp\ReadModels\ProgrammazioneCard;
+use InvalidArgumentException;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists titles and owns the public joined schedule projection.
+ */
+final class TitoloRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/** Store the injected database connection. */
+	public function __construct( wpdb $db ) {
+		$this->db = $db;
+		$this->table = $db->prefix . 'cinebot_titoli';
+	}
+
+	/** Find a title by local ID. */
+	public function find( int $id ): ?Titolo {
+		if ( $id <= 0 ) {
+			return null;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
+		return is_array( $row ) ? $this->hydrate( $row ) : null;
+	}
+
+	/** Find a title by its globally unique API identity. */
+	public function findByRemoteId( int $remoteId ): ?Titolo {
+		if ( $remoteId <= 0 ) {
+			return null;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE idtitolo = %d", $remoteId ), ARRAY_A );
+		return is_array( $row ) ? $this->hydrate( $row ) : null;
+	}
+
+	/**
+	 * Insert or update a title.
+	 *
+	 * @throws InvalidArgumentException When source is invalid.
+	 * @throws RuntimeException When JSON encoding or persistence fails.
+	 */
+	public function save( Titolo $title ): int {
+		if ( ! in_array( $title->source, array( 'api', 'manual' ), true ) ) {
+			throw new InvalidArgumentException( esc_html__( 'A title source must be api or manual.', 'cinebot-wp' ) );
+		}
+		$tag = wp_json_encode( $title->tag );
+		if ( false === $tag ) {
+			throw new RuntimeException( esc_html__( 'Cinebot WP could not encode the title tags.', 'cinebot-wp' ) );
+		}
+		$manual = 'manual' === $title->source;
+		$now = current_time( 'mysql', true );
+		$data = array(
+			'idtitolo' => $title->idtitolo,
+			'frontend_id' => $title->frontendId,
+			'titolo' => $title->titolo,
+			'autore' => $title->autore,
+			'esecutore' => $title->esecutore,
+			'durata' => $title->durata,
+			'scadenza' => $title->scadenza,
+			'descrizione' => $title->descrizione,
+			'tipoevento_codice' => $title->tipoeventoCodice,
+			'locandina_flag' => $title->locandinaFlag,
+			'locandina_url' => $title->locandinaUrl,
+			'cinetel' => $title->cinetel,
+			'tmdb' => $title->tmdb,
+			'trailer' => $title->trailer,
+			'cast' => $title->cast,
+			'tag' => $tag,
+			'source' => $title->source,
+			'sync_hash' => $title->syncHash,
+			'sync_active' => $manual ? 1 : $title->syncActive,
+			'last_seen_sync' => $manual ? null : $title->lastSeenSync,
+			'updated_at' => $now,
+		);
+		$formats = array( '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $title->id ) {
+			$data['created_at'] = $now;
+			$formats[] = '%s';
+			$result = $this->db->insert( $this->table, $data, $formats );
+			$id = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $title->id;
+			$stored = $id > 0 ? $this->find( $id ) : null;
+			if ( null === $stored || $stored->source !== $title->source ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+		return $id;
+	}
+
+	/**
+	 * Search admin titles using fixed ordering.
+	 *
+	 * @param array<string,mixed> $filters Supported type, source, and search filters.
+	 * @return array<int,Titolo>
+	 */
+	public function search( array $filters, int $page, int $perPage ): array {
+		$page = max( 1, $page );
+		$perPage = max( 1, $perPage );
+		$predicate = $this->admin_predicate( $filters );
+		$sql = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY titolo ASC, id ASC LIMIT %d OFFSET %d";
+		$values = array_merge( $predicate['values'], array( $perPage, ( $page - 1 ) * $perPage ) );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
+		return array_map( array( $this, 'hydrate' ), $rows );
+	}
+
+	/**
+	 * Count titles using the same predicates as search.
+	 *
+	 * @param array<string,mixed> $filters Admin filters.
+	 */
+	public function count( array $filters = array() ): int {
+		$predicate = $this->admin_predicate( $filters );
+		$sql = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";
+		if ( array() !== $predicate['values'] ) {
+			$sql = $this->db->prepare( $sql, $predicate['values'] );
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
+		return (int) $this->db->get_var( $sql );
+	}
+
+	/**
+	 * Return dashboard counters.
+	 *
+	 * @return array{titoli_totali:int,titoli_manuali:int,eventi_totali:int,locali_totali:int,tipologie_attive:int}
+	 */
+	public function statistics(): array {
+		$base = $this->db->prefix . 'cinebot_';
+		// All identifiers and scalar predicates are fixed internal fragments.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return array(
+			'titoli_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}titoli" ),
+			'titoli_manuali' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$base}titoli WHERE source = %s", 'manual' ) ),
+			'eventi_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}eventi" ),
+			'locali_totali' => (int) $this->db->get_var( "SELECT COUNT(*) FROM {$base}locali" ),
+			'tipologie_attive' => (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$base}tipologie_eventi WHERE attivo = %d", 1 ) ),
+		);
+		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+	}
+
+	/** Count titles assigned to an exact string type code. */
+	public function countByTypeCode( string $code ): int {
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return (int) $this->db->get_var( $this->db->prepare( "SELECT COUNT(*) FROM {$this->table} WHERE tipoevento_codice = %s", $code ) );
+	}
+
+	/**
+	 * Return one public read model per visible event.
+	 *
+	 * @param array<string,mixed> $filters Public filters and pagination.
+	 * @return array<int,ProgrammazioneCard>
+	 */
+	public function findPublicSchedule( array $filters ): array {
+		$query = $this->public_query( $filters );
+		$orderby = isset( $filters['orderby'] ) && 'titolo' === $filters['orderby'] ? 't.titolo' : 'e.inizio';
+		$order = isset( $filters['order'] ) && in_array( strtoupper( (string) $filters['order'] ), array( 'ASC', 'DESC' ), true ) ? strtoupper( (string) $filters['order'] ) : 'ASC';
+		$limit = isset( $filters['limit'] ) ? max( 1, min( 100, (int) $filters['limit'] ) ) : 50;
+		$offset = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
+		$sql = $this->public_projection_sql() . $query['joins'] . $query['where'] . " ORDER BY {$orderby} {$order}, e.id ASC LIMIT %d OFFSET %d";
+		$values = array_merge( $query['values'], array( $limit, $offset ) );
+		// Projection, joins, and ordering are fixed or allowlisted; all values are prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
+		return array_map(
+			static function ( array $row ): ProgrammazioneCard {
+				return ProgrammazioneCard::fromRow( $row );
+			},
+			$rows
+		);
+	}
+
+	/**
+	 * Count public events using the projection's visibility predicates.
+	 *
+	 * @param array<string,mixed> $filters Public filters.
+	 */
+	public function countPublicSchedule( array $filters ): int {
+		$query = $this->public_query( $filters );
+		$base = $this->db->prefix . 'cinebot_';
+		$sql = "SELECT COUNT(*) FROM {$base}eventi e" . $query['joins'] . $query['where'];
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		return (int) $this->db->get_var( $this->db->prepare( $sql, $query['values'] ) );
+	}
+
+	/**
+	 * Deactivate unseen API titles in one frontend scope.
+	 *
+	 * @return array<int,int> Affected title IDs.
+	 */
+	public function deactivateUnseenApi( int $frontendId, string $syncToken ): array {
+		if ( $frontendId <= 0 ) {
+			return array();
+		}
+		$where = 'frontend_id = %d AND source = %s AND sync_active = %d AND (last_seen_sync IS NULL OR last_seen_sync <> %s)';
+		$values = array( $frontendId, 'api', 1, $syncToken );
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$ids = array_map( 'intval', $this->db->get_col( $this->db->prepare( "SELECT id FROM {$this->table} WHERE {$where} ORDER BY id ASC", $values ) ) );
+		if ( array() === $ids ) {
+			return array();
+		}
+		$affected = array();
+		$now = current_time( 'mysql', true );
+		foreach ( $ids as $id ) {
+			$sql = "UPDATE {$this->table} SET sync_active = 0, updated_at = %s WHERE id = %d AND {$where}";
+			// The predicate is fixed and every dynamic value is prepared.
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+			$result = $this->db->query( $this->db->prepare( $sql, array_merge( array( $now, $id ), $values ) ) );
+			if ( false === $result ) {
+				throw $this->reconciliation_exception();
+			}
+			if ( 1 === $result ) {
+				$affected[] = $id;
+			}
+		}
+		return $affected;
+	}
+
+	/** Delete exactly one title by local ID. */
+	public function delete( int $id ): bool {
+		if ( $id <= 0 ) {
+			return false;
+		}
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		return 1 === $this->db->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
+	}
+
+	/**
+	 * @param array<string,mixed> $filters Admin filters.
+	 * @return array{sql:string,values:array<int,string>}
+	 */
+	private function admin_predicate( array $filters ): array {
+		$clauses = array();
+		$values = array();
+		$type = isset( $filters['tipoevento_codice'] ) ? trim( sanitize_text_field( (string) $filters['tipoevento_codice'] ) ) : '';
+		if ( '' !== $type ) {
+			$clauses[] = 'tipoevento_codice = %s';
+			$values[] = $type;
+		}
+		$source = isset( $filters['source'] ) ? trim( sanitize_text_field( (string) $filters['source'] ) ) : '';
+		if ( in_array( $source, array( 'api', 'manual' ), true ) ) {
+			$clauses[] = 'source = %s';
+			$values[] = $source;
+		}
+		$search = isset( $filters['search'] ) ? trim( sanitize_text_field( (string) $filters['search'] ) ) : '';
+		if ( '' !== $search ) {
+			$like = '%' . $this->db->esc_like( $search ) . '%';
+			$clauses[] = '(LOWER(titolo) LIKE LOWER(%s) OR LOWER(autore) LIKE LOWER(%s))';
+			$values[] = $like;
+			$values[] = $like;
+		}
+		return array( 'sql' => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ), 'values' => $values );
+	}
+
+	/**
+	 * Build public joins and shared visibility predicates.
+	 *
+	 * @param array<string,mixed> $filters Public filters.
+	 * @return array{joins:string,where:string,values:array<int,mixed>}
+	 */
+	private function public_query( array $filters ): array {
+		$base = $this->db->prefix . 'cinebot_';
+		$joins = " INNER JOIN {$base}titoli t ON t.id = e.titolo_id INNER JOIN {$base}tipologie_eventi ty ON ty.codice = t.tipoevento_codice AND ty.attivo = 1 INNER JOIN {$base}locali l ON l.id = e.locale_id";
+		$clauses = array( 't.sync_active = %d', 'e.sync_active = %d', 'e.stato = %d', 'e.inizio >= %s' );
+		$from = isset( $filters['from'] ) && '' !== trim( (string) $filters['from'] ) ? sanitize_text_field( (string) $filters['from'] ) : current_time( 'Y-m-d', true );
+		$values = array( 1, 1, 3, $from );
+		if ( isset( $filters['to'] ) && '' !== trim( (string) $filters['to'] ) ) {
+			$clauses[] = "e.inizio < DATE_ADD(%s, INTERVAL 1 DAY)";
+			$values[] = sanitize_text_field( (string) $filters['to'] );
+		}
+		if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
+			$clauses[] = 'ty.codice = %s';
+			$values[] = sanitize_text_field( (string) $filters['tipo'] );
+		}
+		$locale = isset( $filters['locale'] ) ? (int) $filters['locale'] : 0;
+		if ( $locale > 0 ) {
+			$clauses[] = 'l.id = %d';
+			$values[] = $locale;
+		}
+		if ( isset( $filters['comune'] ) && '' !== trim( (string) $filters['comune'] ) ) {
+			$clauses[] = 'l.comune = %s';
+			$values[] = sanitize_text_field( (string) $filters['comune'] );
+		}
+		return array( 'joins' => $joins, 'where' => ' WHERE ' . implode( ' AND ', $clauses ), 'values' => $values );
+	}
+
+	/** Return the fixed public projection and active-price subquery. */
+	private function public_projection_sql(): string {
+		$base = $this->db->prefix . 'cinebot_';
+		$prices = "SELECT s.evento_id, MIN(p.importo) prezzo_min, MAX(p.importo) prezzo_max FROM {$base}settori s INNER JOIN {$base}prezzi p ON p.settore_id = s.id AND p.sync_active = 1 AND p.stato = 1 WHERE s.sync_active = 1 GROUP BY s.evento_id";
+		return "SELECT e.id evento_id, e.inizio, t.id titolo_id, t.titolo, COALESCE(t.descrizione, '') descrizione, t.locandina_url, ty.codice tipo_codice, ty.descrizione tipo_descrizione, l.id locale_id, l.nome locale_nome, l.comune, price.prezzo_min, price.prezzo_max FROM {$base}eventi e" . " LEFT JOIN ({$prices}) price ON price.evento_id = e.id";
+	}
+
+	/**
+	 * Hydrate a title while decoding its JSON tag field safely.
+	 *
+	 * @param array<string,mixed> $row Database row.
+	 */
+	private function hydrate( array $row ): Titolo {
+		$decoded = isset( $row['tag'] ) && is_string( $row['tag'] ) ? json_decode( $row['tag'], true ) : array();
+		$row['tag'] = is_array( $decoded ) ? $decoded : array();
+		return Titolo::fromArray( $row );
+	}
+
+	/** Build a safe persistence exception. */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not save the title. Verify its identifiers and try again.', 'cinebot-wp' ) );
+	}
+
+	/** Build a safe reconciliation exception. */
+	private function reconciliation_exception(): RuntimeException {
+		return new RuntimeException( esc_html__( 'Cinebot WP could not deactivate schedule titles. Try again.', 'cinebot-wp' ) );
+	}
+}
diff --git a/tests/Integration/ScheduleRepositoryTest.php b/tests/Integration/ScheduleRepositoryTest.php
new file mode 100644
index 0000000..2002676
--- /dev/null
+++ b/tests/Integration/ScheduleRepositoryTest.php
@@ -0,0 +1,676 @@
+<?php
+/**
+ * Schedule hierarchy repository integration tests.
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
+use CinebotWp\Models\Evento;
+use CinebotWp\Models\Locale;
+use CinebotWp\Models\Prezzo;
+use CinebotWp\Models\Settore;
+use CinebotWp\Models\Titolo;
+use CinebotWp\ReadModels\ProgrammazioneCard;
+use CinebotWp\Repositories\EventoRepository;
+use CinebotWp\Repositories\LocaleRepository;
+use CinebotWp\Repositories\PrezzoRepository;
+use CinebotWp\Repositories\SettoreRepository;
+use CinebotWp\Repositories\TitoloRepository;
+use RuntimeException;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies persistence, reads, public projection, and reconciliation.
+ */
+final class ScheduleRepositoryTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var TitoloRepository */
+	private $titles;
+
+	/** @var EventoRepository */
+	private $events;
+
+	/** @var SettoreRepository */
+	private $sectors;
+
+	/** @var PrezzoRepository */
+	private $prices;
+
+	/** @var LocaleRepository */
+	private $venues;
+
+	/** Store the WordPress database connection. */
+	public static function set_up_before_class(): void {
+		parent::set_up_before_class();
+		global $wpdb;
+		self::$db = $wpdb;
+	}
+
+	/** Install and clear the schema before each test. */
+	public function set_up(): void {
+		parent::set_up();
+		( new SchemaInstaller( self::$db ) )->install();
+		$this->clear_tables();
+		$this->titles = new TitoloRepository( self::$db );
+		$this->events = new EventoRepository( self::$db );
+		$this->sectors = new SettoreRepository( self::$db );
+		$this->prices = new PrezzoRepository( self::$db );
+		$this->venues = new LocaleRepository( self::$db );
+	}
+
+	/** Clear hierarchy fixtures after each test. */
+	public function tear_down(): void {
+		$this->clear_tables();
+		parent::tear_down();
+	}
+
+	/**
+	 * Every hierarchy level maps all fields and permits multiple manual null identities.
+	 */
+	public function test_crud_maps_dtos_and_preserves_timestamps_and_manual_sync_state(): void {
+		$venue_id = $this->venue( 'Venue', 'Roma' );
+		$title = $this->title( 501, 'Mapped title', 'api', 42, 'title-token' );
+		$title->autore = 'Mapped author';
+		$title->esecutore = 'Mapped performer';
+		$title->durata = 125;
+		$title->scadenza = 1;
+		$title->descrizione = 'Mapped description';
+		$title->locandinaFlag = 1;
+		$title->locandinaUrl = 'https://example.test/poster.jpg';
+		$title->cinetel = 'CINETEL-1';
+		$title->tmdb = 'TMDB-2';
+		$title->trailer = 'https://example.test/trailer';
+		$title->cast = 'Mapped cast';
+		$title->tag = array( 'family', array( 'key' => 'value' ) );
+		$title->syncHash = 'mapped-title-hash';
+		$title->syncActive = 0;
+		$title_id = $this->titles->save( $title );
+		$stored_title = $this->titles->find( $title_id );
+		self::assertInstanceOf( Titolo::class, $stored_title );
+		$this->assert_complete_dto( $title, $stored_title, $title_id );
+
+		$event = $this->event( 601, $title_id, $venue_id, 'api', '2030-02-03 19:45:00', 'event-token' );
+		$event->organizzatoreId = 701;
+		$event->organizzatoreCf = 'ORG-CF-01';
+		$event->stato = 2;
+		$event->otp = 1;
+		$event->controlloaccessi = 0;
+		$event->mappa = 81;
+		$event->syncActive = 0;
+		$event_id = $this->events->save( $event );
+		$stored_event = $this->events->findByRemoteId( 601 );
+		self::assertInstanceOf( Evento::class, $stored_event );
+		$this->assert_complete_dto( $event, $stored_event, $event_id );
+
+		$sector = $this->sector( 801, $event_id, 'api', 'sector-token' );
+		$sector->nome = 'Mapped sector';
+		$sector->syncActive = 0;
+		$sector_id = $this->sectors->save( $sector );
+		$stored_sector = $this->sectors->findByRemoteId( $event_id, 801 );
+		self::assertInstanceOf( Settore::class, $stored_sector );
+		$this->assert_complete_dto( $sector, $stored_sector, $sector_id );
+
+		$price = $this->price( 901, $sector_id, '12.50', 2, 'api', 'price-token' );
+		$price->nome = 'Mapped price';
+		$price->tipo = 'RID';
+		$price->prevendita = '1.75';
+		$price->syncActive = 0;
+		$price_id = $this->prices->save( $price );
+		$stored_price = $this->prices->findByRemoteId( $sector_id, 901 );
+		self::assertInstanceOf( Prezzo::class, $stored_price );
+		$this->assert_complete_dto( $price, $stored_price, $price_id );
+		self::assertSame( '12.50', $stored_price->importo );
+		self::assertSame( '1.75', $stored_price->prevendita );
+
+		$manual_title = $this->title( null, 'Manual title', 'manual' );
+		$manual_title->syncActive = 0;
+		$manual_title->lastSeenSync = 'must-clear';
+		$manual_title_id = $this->titles->save( $manual_title );
+		$other_title_id = $this->titles->save( $this->title( null, 'Other manual', 'manual' ) );
+		$stored_manual_title = $this->titles->find( $manual_title_id );
+		self::assertInstanceOf( Titolo::class, $stored_manual_title );
+		self::assertSame( 1, $stored_manual_title->syncActive );
+		self::assertNull( $stored_manual_title->lastSeenSync );
+		self::assertNotSame( $manual_title_id, $other_title_id );
+
+		$manual_event = $this->event( null, $manual_title_id, $venue_id, 'manual' );
+		$manual_event->syncActive = 0;
+		$manual_event_id = $this->events->save( $manual_event );
+		$other_event_id = $this->events->save( $this->event( null, $manual_title_id, $venue_id, 'manual' ) );
+		$manual_sector = $this->sector( null, $manual_event_id, 'manual' );
+		$manual_sector->syncActive = 0;
+		$manual_sector_id = $this->sectors->save( $manual_sector );
+		$other_sector_id = $this->sectors->save( $this->sector( null, $manual_event_id, 'manual' ) );
+		$manual_price = $this->price( null, $manual_sector_id, '14.50', 1, 'manual' );
+		$manual_price->syncActive = 0;
+		$manual_price_id = $this->prices->save( $manual_price );
+		$other_price_id = $this->prices->save( $this->price( null, $manual_sector_id, '15.50', 1, 'manual' ) );
+
+		self::assertNotSame( $manual_event_id, $other_event_id );
+		self::assertNotSame( $manual_sector_id, $other_sector_id );
+		self::assertNotSame( $manual_price_id, $other_price_id );
+		self::assertTrue( $this->events->belongsToTitolo( $manual_event_id, $manual_title_id ) );
+		self::assertTrue( $this->sectors->belongsToEvento( $manual_sector_id, $manual_event_id ) );
+		self::assertTrue( $this->prices->belongsToSettore( $manual_price_id, $manual_sector_id ) );
+		self::assertFalse( $this->events->belongsToTitolo( 0, $title_id ) );
+		$stored_manual_event = $this->events->findByTitoloId( $manual_title_id )[0];
+		$stored_manual_sector = $this->sectors->findByEventoId( $manual_event_id )[0];
+		$stored_manual_price = $this->prices->findBySettoreId( $manual_sector_id )[0];
+		self::assertSame( 1, $stored_manual_event->syncActive );
+		self::assertNull( $stored_manual_event->lastSeenSync );
+		self::assertSame( 1, $stored_manual_sector->syncActive );
+		self::assertNull( $stored_manual_sector->lastSeenSync );
+		self::assertSame( 1, $stored_manual_price->syncActive );
+		self::assertNull( $stored_manual_price->lastSeenSync );
+
+		$created_at = $stored_title->createdAt;
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'updated_at' => '2000-01-01 00:00:00' ), array( 'id' => $title_id ) );
+		$stored_title = $this->titles->find( $title_id );
+		$stored_title->titolo = 'Updated title';
+		$this->titles->save( $stored_title );
+		$updated = $this->titles->find( $title_id );
+		self::assertInstanceOf( Titolo::class, $updated );
+		self::assertSame( $created_at, $updated->createdAt );
+		self::assertNotSame( '2000-01-01 00:00:00', $updated->updatedAt );
+	}
+
+	/**
+	 * Remote identities are found in their documented global or parent scope.
+	 */
+	public function test_remote_identity_scope_and_safe_write_failures(): void {
+		$title_id = $this->titles->save( $this->title( 100, 'API title', 'api' ) );
+		$venue_id = $this->venue( 'Venue', 'Roma' );
+		$event_id = $this->events->save( $this->event( 200, $title_id, $venue_id, 'api' ) );
+		$sector_id = $this->sectors->save( $this->sector( 300, $event_id, 'api' ) );
+		$price_id = $this->prices->save( $this->price( 400, $sector_id, '10.00', 1, 'api' ) );
+
+		self::assertSame( $title_id, $this->titles->findByRemoteId( 100 )->id );
+		self::assertSame( $event_id, $this->events->findByRemoteId( 200 )->id );
+		self::assertSame( $sector_id, $this->sectors->findByRemoteId( $event_id, 300 )->id );
+		self::assertSame( $price_id, $this->prices->findByRemoteId( $sector_id, 400 )->id );
+		self::assertNull( $this->sectors->findByRemoteId( PHP_INT_MAX, 300 ) );
+		try {
+			$this->titles->save( $this->title( 100, 'Duplicate title', 'api' ) );
+			self::fail( 'A global API title identity must be unique.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'title', strtolower( $exception->getMessage() ) );
+		}
+		try {
+			$this->events->save( $this->event( 200, $title_id, $venue_id, 'api' ) );
+			self::fail( 'A global API event identity must be unique.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'event', strtolower( $exception->getMessage() ) );
+		}
+		$stored_title = $this->titles->find( $title_id );
+		self::assertInstanceOf( Titolo::class, $stored_title );
+		$stored_title->source = 'manual';
+		try {
+			$this->titles->save( $stored_title );
+			self::fail( 'A save must not convert API ownership to manual.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'title', strtolower( $exception->getMessage() ) );
+		}
+
+		$missing = $this->title( null, 'Missing', 'manual' );
+		$missing->id = PHP_INT_MAX;
+		$this->expectException( RuntimeException::class );
+		$this->titles->save( $missing );
+	}
+
+	/**
+	 * Invalid title tag JSON is an empty array and failed encoding is safe.
+	 */
+	public function test_title_tag_json_invalid_fallback_and_encoding_failure(): void {
+		$id = $this->titles->save( $this->title( null, 'JSON title', 'manual' ) );
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'tag' => '{invalid' ), array( 'id' => $id ) );
+		self::assertSame( array(), $this->titles->find( $id )->tag );
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'tag' => '"scalar"' ), array( 'id' => $id ) );
+		self::assertSame( array(), $this->titles->find( $id )->tag );
+
+		$title = $this->title( null, 'Bad JSON', 'manual' );
+		$title->tag = array( fopen( 'php://memory', 'r' ) );
+		$this->expectException( RuntimeException::class );
+		$this->titles->save( $title );
+	}
+
+	/**
+	 * Admin search/count share exact filters, escaped text, pagination, and ordering.
+	 */
+	public function test_admin_search_count_statistics_and_type_count(): void {
+		$one = $this->title( null, 'Beta', 'manual' );
+		$one->autore = 'Needle Author';
+		$one->tipoeventoCodice = '01';
+		$this->titles->save( $one );
+		$two = $this->title( 2, 'Alpha Needle', 'api' );
+		$two->tipoeventoCodice = '01';
+		$this->titles->save( $two );
+		$this->titles->save( $this->title( null, 'Gamma', 'manual' ) );
+
+		$filters = array( 'tipoevento_codice' => '01', 'source' => 'manual', 'search' => 'needle' );
+		$rows = $this->titles->search( $filters, 0, 0 );
+		self::assertCount( 1, $rows );
+		self::assertSame( 'Beta', $rows[0]->titolo );
+		self::assertSame( count( $rows ), $this->titles->count( $filters ) );
+		self::assertSame( 0, $this->titles->count( array( 'search' => "%_' OR 1=1 --" ) ) );
+		self::assertSame(
+			array( 'Alpha Needle', 'Beta', 'Gamma' ),
+			array_map(
+				static function ( Titolo $title ): string {
+					return $title->titolo;
+				},
+				$this->titles->search( array(), 1, 20 )
+			)
+		);
+		self::assertSame( 2, $this->titles->countByTypeCode( '01' ) );
+		self::assertSame(
+			array( 'titoli_totali', 'titoli_manuali', 'eventi_totali', 'locali_totali', 'tipologie_attive' ),
+			array_keys( $this->titles->statistics() )
+		);
+		self::assertSame( 3, $this->titles->statistics()['titoli_totali'] );
+		self::assertSame( 2, $this->titles->statistics()['titoli_manuali'] );
+	}
+
+	/**
+	 * Public schedule applies visibility, combined filters, pricing, sorting, and parity.
+	 */
+	public function test_public_schedule_projection_filters_visibility_and_count_parity(): void {
+		$rome = $this->venue( 'Rome Hall', 'Roma' );
+		$milan = $this->venue( 'Milan Hall', 'Milano' );
+		$title_id = $this->titles->save( $this->title( 10, 'Zulu Show', 'api' ) );
+		$second_id = $this->titles->save( $this->title( 11, 'Alpha Show', 'api' ) );
+		$future = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 10 );
+		$later = gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 20 );
+		$event_id = $this->events->save( $this->event( 20, $title_id, $rome, 'api', $future ) );
+		$no_price_event = $this->events->save( $this->event( 21, $second_id, $milan, 'api', $later ) );
+		$sector_id = $this->sectors->save( $this->sector( 30, $event_id, 'api' ) );
+		$this->prices->save( $this->price( 40, $sector_id, '20.00', 1, 'api' ) );
+		$this->prices->save( $this->price( 41, $sector_id, '10.00', 1, 'api' ) );
+		$this->prices->save( $this->price( 42, $sector_id, '1.00', 0, 'api' ) );
+
+		$cards = $this->titles->findPublicSchedule( array() );
+		self::assertCount( 2, $cards );
+		self::assertContainsOnlyInstancesOf( ProgrammazioneCard::class, $cards );
+		self::assertSame(
+			array(
+				'evento_id' => $event_id,
+				'inizio' => $future,
+				'titolo_id' => $title_id,
+				'titolo' => 'Zulu Show',
+				'descrizione' => 'Description',
+				'locandina_url' => null,
+				'tipo_codice' => '01',
+				'tipo_descrizione' => 'CINEMA',
+				'locale_id' => $rome,
+				'locale_nome' => 'Rome Hall',
+				'comune' => 'Roma',
+				'prezzo_min' => '10.00',
+				'prezzo_max' => '20.00',
+			),
+			$this->card_to_array( $cards[0] )
+		);
+		self::assertNull( $cards[1]->prezzoMin );
+		self::assertSame( 2, $this->titles->countPublicSchedule( array() ) );
+
+		$filters = array(
+			'tipo' => '01',
+			'locale' => $rome,
+			'comune' => 'Roma',
+			'from' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
+			'to' => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS * 15 ),
+			'orderby' => 'titolo',
+			'order' => 'DESC',
+			'limit' => 1,
+			'offset' => 0,
+		);
+		self::assertCount( 1, $this->titles->findPublicSchedule( $filters ) );
+		self::assertSame( 1, $this->titles->countPublicSchedule( $filters ) );
+		$empty_page_filters = array_merge( $filters, array( 'offset' => 1 ) );
+		self::assertSame( array(), $this->titles->findPublicSchedule( $empty_page_filters ) );
+		self::assertSame( 1, $this->titles->countPublicSchedule( $empty_page_filters ) );
+		self::assertSame(
+			$event_id,
+			$this->titles->findPublicSchedule(
+				array( 'orderby' => 'injection', 'order' => 'DROP TABLE', 'limit' => 999 )
+			)[0]->eventoId
+		);
+		self::assertSame(
+			$no_price_event,
+			$this->titles->findPublicSchedule(
+				array( 'orderby' => 'titolo', 'order' => 'ASC', 'limit' => 1, 'offset' => 0 )
+			)[0]->eventoId
+		);
+
+		self::$db->update( self::$db->prefix . 'cinebot_settori', array( 'sync_active' => 0 ), array( 'id' => $sector_id ) );
+		self::assertNull( $this->titles->findPublicSchedule( array() )[0]->prezzoMin );
+		self::$db->update( self::$db->prefix . 'cinebot_settori', array( 'sync_active' => 1 ), array( 'id' => $sector_id ) );
+		self::$db->update( self::$db->prefix . 'cinebot_prezzi', array( 'sync_active' => 0 ), array( 'idprezzo' => 41 ) );
+		self::assertSame( '20.00', $this->titles->findPublicSchedule( array() )[0]->prezzoMin );
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'sync_active' => 0 ), array( 'id' => $second_id ) );
+		self::assertSame(
+			array( $event_id ),
+			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
+		);
+		self::$db->update( self::$db->prefix . 'cinebot_titoli', array( 'sync_active' => 1 ), array( 'id' => $second_id ) );
+		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'stato' => 2 ), array( 'id' => $no_price_event ) );
+		self::assertSame( 1, $this->titles->countPublicSchedule( array() ) );
+		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'stato' => 3 ), array( 'id' => $no_price_event ) );
+		$past = $this->events->save( $this->event( 22, $title_id, $rome, 'api', gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) );
+		self::assertNotContains(
+			$past,
+			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
+		);
+
+		self::$db->update( self::$db->prefix . 'cinebot_eventi', array( 'sync_active' => 0 ), array( 'id' => $event_id ) );
+		self::assertSame(
+			array( $no_price_event ),
+			$this->card_ids( $this->titles->findPublicSchedule( array() ) )
+		);
+	}
+
+	/**
+	 * Ownership rejects wrong positive parents and direct deletes retain descendants.
+	 */
+	public function test_counts_deletes_and_delete_by_parent_contracts(): void {
+		$title_id = $this->titles->save( $this->title( null, 'Delete one', 'manual' ) );
+		$other_title_id = $this->titles->save( $this->title( null, 'Delete two', 'manual' ) );
+		$venue_id = $this->venue( 'Delete venue', 'Roma' );
+		$event_id = $this->events->save( $this->event( null, $title_id, $venue_id, 'manual' ) );
+		$other_event_id = $this->events->save( $this->event( null, $other_title_id, $venue_id, 'manual' ) );
+		$sector_id = $this->sectors->save( $this->sector( null, $event_id, 'manual' ) );
+		$other_sector_id = $this->sectors->save( $this->sector( null, $other_event_id, 'manual' ) );
+		$price_id = $this->prices->save( $this->price( null, $sector_id, '5.00', 1, 'manual' ) );
+		$other_price_id = $this->prices->save( $this->price( null, $other_sector_id, '6.00', 1, 'manual' ) );
+
+		self::assertSame( 1, $this->events->countByTitoloId( $title_id ) );
+		self::assertSame( 2, $this->events->countByLocaleId( $venue_id ) );
+		self::assertFalse( $this->events->belongsToTitolo( $event_id, $other_title_id ) );
+		self::assertFalse( $this->sectors->belongsToEvento( $sector_id, $other_event_id ) );
+		self::assertFalse( $this->prices->belongsToSettore( $price_id, $other_sector_id ) );
+
+		self::assertTrue( $this->titles->delete( $title_id ) );
+		self::assertCount( 1, $this->events->findByTitoloId( $title_id ) );
+		self::assertTrue( $this->events->delete( $event_id ) );
+		self::assertCount( 1, $this->sectors->findByEventoId( $event_id ) );
+		self::assertTrue( $this->sectors->delete( $sector_id ) );
+		self::assertCount( 1, $this->prices->findBySettoreId( $sector_id ) );
+		self::assertTrue( $this->prices->delete( $price_id ) );
+		self::assertFalse( $this->prices->delete( $price_id ) );
+		self::assertFalse( $this->titles->delete( $title_id ) );
+
+		self::assertSame( 1, $this->events->deleteByTitoloId( $other_title_id ) );
+		self::assertCount( 1, $this->sectors->findByEventoId( $other_event_id ) );
+		self::assertSame( 1, $this->sectors->deleteByEventoId( $other_event_id ) );
+		self::assertCount( 1, $this->prices->findBySettoreId( $other_sector_id ) );
+		self::assertSame( 1, $this->prices->deleteBySettoreId( $other_sector_id ) );
+		self::assertFalse( $this->prices->delete( $other_price_id ) );
+	}
+
+	/**
+	 * Unseen and cascade reconciliation is scoped, returns IDs, and preserves manual rows.
+	 */
+	public function test_reconciliation_scopes_and_empty_array_no_ops(): void {
+		$title_one = $this->titles->save( $this->title( 101, 'One', 'api', 50, 'old' ) );
+		$title_seen = $this->titles->save( $this->title( 102, 'Seen', 'api', 50, 'current' ) );
+		$title_other = $this->titles->save( $this->title( 103, 'Other', 'api', 51, 'old' ) );
+		$manual_title = $this->titles->save( $this->title( null, 'Manual', 'manual', 50, null ) );
+		self::assertSame( array( $title_one ), $this->titles->deactivateUnseenApi( 50, 'current' ) );
+		self::assertSame( 1, $this->titles->find( $title_seen )->syncActive );
+		self::assertSame( 1, $this->titles->find( $title_other )->syncActive );
+		self::assertSame( 1, $this->titles->find( $manual_title )->syncActive );
+
+		$venue_id = $this->venue( 'Venue', 'Roma' );
+		$event = $this->events->save( $this->event( 201, $title_seen, $venue_id, 'api', null, 'old' ) );
+		$manual_event = $this->events->save( $this->event( null, $title_seen, $venue_id, 'manual' ) );
+		self::assertSame( array( $event ), $this->events->deactivateUnseenApi( $title_seen, 'current' ) );
+		self::assertSame( 1, $this->events->findByTitoloId( $title_seen )[1]->syncActive );
+
+		$event_two = $this->events->save( $this->event( 202, $title_other, $venue_id, 'api' ) );
+		$sector = $this->sectors->save( $this->sector( 301, $event_two, 'api', 'old' ) );
+		$manual_sector = $this->sectors->save( $this->sector( null, $event_two, 'manual' ) );
+		self::assertSame( array( $sector ), $this->sectors->deactivateUnseenApi( $event_two, 'current' ) );
+		self::assertSame( array(), $this->sectors->deactivateByEventoIds( array() ) );
+
+		$active_sector = $this->sectors->save( $this->sector( 302, $event_two, 'api' ) );
+		$price = $this->prices->save( $this->price( 401, $active_sector, '10.00', 1, 'api', 'old' ) );
+		$manual_price = $this->prices->save( $this->price( null, $active_sector, '11.00', 1, 'manual' ) );
+		self::assertSame( array( $price ), $this->prices->deactivateUnseenApi( $active_sector, 'current' ) );
+		$cascade_price = $this->prices->save( $this->price( 402, $active_sector, '12.00', 1, 'api' ) );
+		self::assertSame( 0, $this->prices->deactivateBySettoreIds( array() ) );
+		self::assertSame( 0, $this->prices->deactivateBySettoreIds( array( $manual_sector ) ) );
+		self::assertSame( array( $active_sector ), $this->sectors->deactivateByEventoIds( array( $event_two ) ) );
+		self::assertSame( 1, $this->prices->deactivateBySettoreIds( array( $active_sector ) ) );
+		self::assertSame( array( $event_two ), $this->events->deactivateByTitoloIds( array( $title_other ) ) );
+		self::assertSame( array(), $this->events->deactivateByTitoloIds( array() ) );
+		self::assertSame( 0, $this->prices->findBySettoreId( $active_sector )[2]->syncActive );
+		self::assertSame( $manual_event, $this->events->findByTitoloId( $title_seen )[1]->id );
+		self::assertSame( $manual_price, $this->prices->findBySettoreId( $active_sector )[1]->id );
+		self::assertSame( $cascade_price, $this->prices->findBySettoreId( $active_sector )[2]->id );
+	}
+
+	/**
+	 * Reconciliation update failures throw and cannot report selected candidates.
+	 */
+	public function test_reconciliation_query_failures_throw_for_every_return_contract(): void {
+		$title_id = $this->titles->save( $this->title( 1001, 'Failure title', 'api', 90, 'old' ) );
+		$venue_id = $this->venue( 'Failure venue', 'Roma' );
+		$event_id = $this->events->save( $this->event( 1002, $title_id, $venue_id, 'api', null, 'old' ) );
+		$sector_id = $this->sectors->save( $this->sector( 1003, $event_id, 'api', 'old' ) );
+		$this->prices->save( $this->price( 1004, $sector_id, '10.00', 1, 'api', 'old' ) );
+
+		$failing_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			/** Fail only reconciliation UPDATE statements. */
+			public function query( $query ) {
+				if ( is_string( $query ) && 1 === preg_match( '/^\s*UPDATE\s+/i', $query ) ) {
+					return false;
+				}
+				return parent::query( $query );
+			}
+		};
+		$failing_db->set_prefix( self::$db->prefix );
+
+		try {
+			$this->assert_reconciliation_failure(
+				static function () use ( $failing_db ): void {
+					( new TitoloRepository( $failing_db ) )->deactivateUnseenApi( 90, 'current' );
+				}
+			);
+			$this->assert_reconciliation_failure(
+				static function () use ( $failing_db, $title_id ): void {
+					( new EventoRepository( $failing_db ) )->deactivateByTitoloIds( array( $title_id ) );
+				}
+			);
+			$this->assert_reconciliation_failure(
+				static function () use ( $failing_db, $event_id ): void {
+					( new SettoreRepository( $failing_db ) )->deactivateByEventoIds( array( $event_id ) );
+				}
+			);
+			$this->assert_reconciliation_failure(
+				static function () use ( $failing_db, $sector_id ): void {
+					( new PrezzoRepository( $failing_db ) )->deactivateBySettoreIds( array( $sector_id ) );
+				}
+			);
+		} finally {
+			$failing_db->close();
+		}
+
+		self::assertSame( 1, $this->titles->find( $title_id )->syncActive );
+		self::assertSame( 1, $this->events->findByTitoloId( $title_id )[0]->syncActive );
+		self::assertSame( 1, $this->sectors->findByEventoId( $event_id )[0]->syncActive );
+		self::assertSame( 1, $this->prices->findBySettoreId( $sector_id )[0]->syncActive );
+	}
+
+	/**
+	 * A stale candidate whose conditional update affects zero rows is not returned.
+	 */
+	public function test_reconciliation_returns_only_ids_reported_as_updated(): void {
+		$title_id = $this->titles->save( $this->title( 1101, 'Stale title', 'api', 91, 'old' ) );
+		$zero_update_db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			/** Simulate a candidate becoming stale before its conditional update. */
+			public function query( $query ) {
+				if ( is_string( $query ) && 1 === preg_match( '/^\s*UPDATE\s+/i', $query ) ) {
+					return 0;
+				}
+				return parent::query( $query );
+			}
+		};
+		$zero_update_db->set_prefix( self::$db->prefix );
+
+		try {
+			$repository = new TitoloRepository( $zero_update_db );
+			self::assertSame( array(), $repository->deactivateUnseenApi( 91, 'current' ) );
+		} finally {
+			$zero_update_db->close();
+		}
+
+		self::assertSame( 1, $this->titles->find( $title_id )->syncActive );
+	}
+
+	/** Clear hierarchy tables in child-first order. */
+	private function clear_tables(): void {
+		foreach ( array( 'prezzi', 'settori', 'eventi', 'titoli', 'locali' ) as $suffix ) {
+			self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_' . $suffix );
+		}
+	}
+
+	/**
+	 * Assert every DTO field plus generated timestamps.
+	 *
+	 * @param Titolo|Evento|Settore|Prezzo $expected Input DTO.
+	 * @param Titolo|Evento|Settore|Prezzo $actual Persisted DTO.
+	 */
+	private function assert_complete_dto( $expected, $actual, int $id ): void {
+		$expected_data = $expected->toArray();
+		$actual_data = $actual->toArray();
+		$expected_data['id'] = $id;
+		$expected_data['created_at'] = $actual_data['created_at'];
+		$expected_data['updated_at'] = $actual_data['updated_at'];
+		self::assertNotNull( $actual_data['created_at'] );
+		self::assertSame( $actual_data['created_at'], $actual_data['updated_at'] );
+		self::assertSame( $expected_data, $actual_data );
+	}
+
+	/** Assert a reconciliation write failure is safe and actionable. */
+	private function assert_reconciliation_failure( callable $operation ): void {
+		try {
+			$operation();
+			self::fail( 'A failed reconciliation update must throw.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'deactivate', strtolower( $exception->getMessage() ) );
+			self::assertDoesNotMatchRegularExpression(
+				'/\b(?:select|update|delete|insert)\b/i',
+				$exception->getMessage()
+			);
+		}
+	}
+
+	/**
+	 * Convert one public card to its documented projection shape.
+	 *
+	 * @return array<string,mixed>
+	 */
+	private function card_to_array( ProgrammazioneCard $card ): array {
+		return array(
+			'evento_id' => $card->eventoId,
+			'inizio' => $card->inizio,
+			'titolo_id' => $card->titoloId,
+			'titolo' => $card->titolo,
+			'descrizione' => $card->descrizione,
+			'locandina_url' => $card->locandinaUrl,
+			'tipo_codice' => $card->tipoCodice,
+			'tipo_descrizione' => $card->tipoDescrizione,
+			'locale_id' => $card->localeId,
+			'locale_nome' => $card->localeNome,
+			'comune' => $card->comune,
+			'prezzo_min' => $card->prezzoMin,
+			'prezzo_max' => $card->prezzoMax,
+		);
+	}
+
+	/**
+	 * Return event IDs from public cards.
+	 *
+	 * @param array<int,ProgrammazioneCard> $cards Public cards.
+	 * @return array<int,int>
+	 */
+	private function card_ids( array $cards ): array {
+		return array_map(
+			static function ( ProgrammazioneCard $card ): int {
+				return $card->eventoId;
+			},
+			$cards
+		);
+	}
+
+	/** Create a title fixture. */
+	private function title( ?int $remote_id, string $name, string $source, ?int $frontend_id = 1, ?string $token = 'token' ): Titolo {
+		$title = new Titolo();
+		$title->idtitolo = $remote_id;
+		$title->frontendId = $frontend_id;
+		$title->titolo = $name;
+		$title->descrizione = 'Description';
+		$title->tipoeventoCodice = '01';
+		$title->source = $source;
+		$title->syncHash = 'hash';
+		$title->lastSeenSync = $token;
+		return $title;
+	}
+
+	/** Create an event fixture. */
+	private function event( ?int $remote_id, int $title_id, int $venue_id, string $source, ?string $start = null, ?string $token = 'token' ): Evento {
+		$event = new Evento();
+		$event->idevento = $remote_id;
+		$event->titoloId = $title_id;
+		$event->inizio = $start ?? gmdate( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS * 5 );
+		$event->localeId = $venue_id;
+		$event->stato = 3;
+		$event->source = $source;
+		$event->lastSeenSync = $token;
+		return $event;
+	}
+
+	/** Create a sector fixture. */
+	private function sector( ?int $remote_id, int $event_id, string $source, ?string $token = 'token' ): Settore {
+		$sector = new Settore();
+		$sector->idsettore = $remote_id;
+		$sector->eventoId = $event_id;
+		$sector->nome = 'Sector';
+		$sector->source = $source;
+		$sector->lastSeenSync = $token;
+		return $sector;
+	}
+
+	/** Create a price fixture. */
+	private function price( ?int $remote_id, int $sector_id, string $amount, int $state, string $source, ?string $token = 'token' ): Prezzo {
+		$price = new Prezzo();
+		$price->idprezzo = $remote_id;
+		$price->settoreId = $sector_id;
+		$price->nome = 'Price';
+		$price->tipo = 'INT';
+		$price->importo = $amount;
+		$price->prevendita = '1.00';
+		$price->stato = $state;
+		$price->source = $source;
+		$price->lastSeenSync = $token;
+		return $price;
+	}
+
+	/** Persist and return a manual venue fixture. */
+	private function venue( string $name, string $city ): int {
+		$venue = new Locale();
+		$venue->nome = $name;
+		$venue->comune = $city;
+		$venue->source = 'manual';
+		return $this->venues->save( $venue );
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 5 commits. No Task 5 implementation file is currently modified or untracked.
