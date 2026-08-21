# Task 4 Reviewer Handoff Package

## Scope

Complete Task 4 range from parent `e3253d83028cfab6315f206145ac7cdf4f854b18` through implementation commit `330e9ce3b1e413492fcbc6963da07c956102c651` and fix commit `89db1bf8d071489442ebe431fb141831be3aaacf`. Task 4 adds injected-`wpdb` event-type and venue repositories plus integration contracts; the fix hardens API remote-ID validation and timestamp/ownership assertions.

The updated Task 4 report records blocked Docker/PHP dynamic gates. Static review covers strict original-type ID validation, anchored digit handling, overflow rejection, exact manual DTO preservation, deterministic timestamp refresh checks, SQL safety, DTO returns, filter parity, PHP 7.4 compatibility, and scope.

## Commit Metadata

```text
commit 330e9ce3b1e413492fcbc6963da07c956102c651
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:32:45 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:32:45 2026 +0200

    feat: persist event types and venues

commit 89db1bf8d071489442ebe431fb141831be3aaacf
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:46:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:46:12 2026 +0200

    fix: validate repository updates
```

## Full Stat

Command: `git show --stat --format=fuller 330e9ce 89db1bf`

```text
commit 330e9ce3b1e413492fcbc6963da07c956102c651
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:32:45 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:32:45 2026 +0200

    feat: persist event types and venues

 .superpowers/sdd/task-4-report.md             |  95 +++++++++
 includes/Repositories/LocaleRepository.php    | 246 +++++++++++++++++++++++
 includes/Repositories/TipologiaRepository.php | 175 +++++++++++++++++
 tests/Integration/LocaleRepositoryTest.php    | 271 ++++++++++++++++++++++++++
 tests/Integration/TipologiaRepositoryTest.php | 220 +++++++++++++++++++++
 5 files changed, 1007 insertions(+)

commit 89db1bf8d071489442ebe431fb141831be3aaacf
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:46:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:46:12 2026 +0200

    fix: validate repository updates

 .superpowers/sdd/task-4-report.md             | 38 +++++++++++++++++++++
 includes/Repositories/LocaleRepository.php    | 31 +++++++++++++++--
 tests/Integration/LocaleRepositoryTest.php    | 49 +++++++++++++++++++++++++--
 tests/Integration/TipologiaRepositoryTest.php | 13 +++++++
 4 files changed, 127 insertions(+), 4 deletions(-)
```

The committed `.superpowers/sdd/task-4-report.md` is coordination evidence represented in the stat and summarized above; it is excluded from the implementation diff.

## Full Relevant Diff

This cumulative diff shows the final Task 4 implementation and tests after both commits.

Command: `git diff --unified=10 e3253d8 89db1bf -- includes/Repositories/LocaleRepository.php includes/Repositories/TipologiaRepository.php tests/Integration/LocaleRepositoryTest.php tests/Integration/TipologiaRepositoryTest.php`

```diff
diff --git a/includes/Repositories/LocaleRepository.php b/includes/Repositories/LocaleRepository.php
new file mode 100644
index 0000000..5215ba9
--- /dev/null
+++ b/includes/Repositories/LocaleRepository.php
@@ -0,0 +1,273 @@
+<?php
+/**
+ * Venue persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\Locale;
+use InvalidArgumentException;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists manual and API-owned venues in the plugin table.
+ */
+final class LocaleRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/**
+	 * Store the injected database connection.
+	 */
+	public function __construct( wpdb $db ) {
+		$this->db    = $db;
+		$this->table = $db->prefix . 'cinebot_locali';
+	}
+
+	/**
+	 * Find a venue by local ID.
+	 */
+	public function find( int $id ): ?Locale {
+		if ( $id <= 0 ) {
+			return null;
+		}
+
+		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
+
+		return is_array( $row ) ? Locale::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Find a venue by globally unique API ID.
+	 */
+	public function findByRemoteId( int $remoteId ): ?Locale {
+		if ( $remoteId <= 0 ) {
+			return null;
+		}
+
+		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE locale_id_remoto = %d", $remoteId ), ARRAY_A );
+
+		return is_array( $row ) ? Locale::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Insert or update a venue and return its local ID.
+	 *
+	 * @throws InvalidArgumentException When the source is not api or manual.
+	 * @throws RuntimeException When the row cannot be stored.
+	 */
+	public function save( Locale $locale ): int {
+		if ( ! in_array( $locale->source, array( 'api', 'manual' ), true ) ) {
+			throw new InvalidArgumentException( esc_html__( 'A venue source must be api or manual.', 'cinebot-wp' ) );
+		}
+
+		$now  = current_time( 'mysql', true );
+		$data = array(
+			'locale_id_remoto' => $locale->localeIdRemoto,
+			'nome'             => $locale->nome,
+			'codice'           => $locale->codice,
+			'indirizzo'        => $locale->indirizzo,
+			'cap'              => $locale->cap,
+			'comune'           => $locale->comune,
+			'provincia'        => $locale->provincia,
+			'mappa'            => $locale->mappa,
+			'source'           => $locale->source,
+			'updated_at'       => $now,
+		);
+		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s' );
+
+		// wpdb prepares every mapped value using the explicit formats.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $locale->id ) {
+			$data['created_at'] = $now;
+			$formats[]          = '%s';
+			$result             = $this->db->insert( $this->table, $data, $formats );
+			$id                 = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $locale->id;
+			if ( $id <= 0 || null === $this->find( $id ) ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+
+		return $id;
+	}
+
+	/**
+	 * Insert or update one API venue without overwriting manual ownership.
+	 *
+	 * @param array<string,mixed> $data API venue data.
+	 * @throws InvalidArgumentException When required API identity data is invalid.
+	 */
+	public function upsertApi( array $data ): int {
+		$remote_id = $this->api_remote_id( $data['localeId'] ?? null );
+		$name      = isset( $data['locale'] ) ? trim( sanitize_text_field( (string) $data['locale'] ) ) : '';
+		if ( null === $remote_id || '' === $name ) {
+			throw new InvalidArgumentException( esc_html__( 'An API venue requires a positive localeId and a non-empty locale name.', 'cinebot-wp' ) );
+		}
+
+		$existing = $this->findByRemoteId( $remote_id );
+		if ( null !== $existing && 'manual' === $existing->source ) {
+			return (int) $existing->id;
+		}
+
+		$locale                 = null !== $existing ? $existing : new Locale();
+		$locale->localeIdRemoto = $remote_id;
+		$locale->nome           = $name;
+		$locale->codice         = $this->api_string( $data, 'localeCodice' );
+		$locale->indirizzo      = $this->api_string( $data, 'indirizzo' );
+		$locale->cap            = $this->api_string( $data, 'cap' );
+		$locale->comune         = $this->api_string( $data, 'comune' );
+		$locale->provincia      = $this->api_string( $data, 'provincia' );
+		$locale->mappa          = isset( $data['mappa'] ) && null !== $data['mappa'] ? (int) $data['mappa'] : null;
+		$locale->source         = 'api';
+
+		return $this->save( $locale );
+	}
+
+	/**
+	 * Search venues using fixed predicates and ordering.
+	 *
+	 * @param array<string,mixed> $filters Supported provincia, comune, and search filters.
+	 * @return array<int,Locale>
+	 */
+	public function search( array $filters, int $page, int $perPage ): array {
+		$page     = max( 1, $page );
+		$perPage  = max( 1, $perPage );
+		$offset   = ( $page - 1 ) * $perPage;
+		$predicate = $this->filter_predicate( $filters );
+		$sql       = "SELECT * FROM {$this->table}{$predicate['sql']} ORDER BY nome ASC, id ASC LIMIT %d OFFSET %d";
+		$values    = array_merge( $predicate['values'], array( $perPage, $offset ) );
+
+		// Table and ordering identifiers are fixed; every value is prepared.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$rows = $this->db->get_results( $this->db->prepare( $sql, $values ), ARRAY_A );
+
+		return array_map(
+			static function ( array $row ): Locale {
+				return Locale::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/**
+	 * Count venues using the same predicates as search.
+	 *
+	 * @param array<string,mixed> $filters Supported provincia, comune, and search filters.
+	 */
+	public function count( array $filters = array() ): int {
+		$predicate = $this->filter_predicate( $filters );
+		$sql       = "SELECT COUNT(*) FROM {$this->table}{$predicate['sql']}";
+
+		if ( array() !== $predicate['values'] ) {
+			$sql = $this->db->prepare( $sql, $predicate['values'] );
+		}
+
+		// The table identifier and predicates come only from internal fixed fragments.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
+		return (int) $this->db->get_var( $sql );
+	}
+
+	/**
+	 * Build the shared fixed filter predicate and prepared values.
+	 *
+	 * @param array<string,mixed> $filters Raw boundary filters.
+	 * @return array{sql:string,values:array<int,string>}
+	 */
+	private function filter_predicate( array $filters ): array {
+		$clauses = array();
+		$values  = array();
+
+		$province = isset( $filters['provincia'] ) ? trim( sanitize_text_field( (string) $filters['provincia'] ) ) : '';
+		if ( '' !== $province ) {
+			$clauses[] = 'provincia = %s';
+			$values[]  = $province;
+		}
+
+		$city = isset( $filters['comune'] ) ? trim( sanitize_text_field( (string) $filters['comune'] ) ) : '';
+		if ( '' !== $city ) {
+			$clauses[] = 'comune = %s';
+			$values[]  = $city;
+		}
+
+		$search = isset( $filters['search'] ) ? trim( sanitize_text_field( (string) $filters['search'] ) ) : '';
+		if ( '' !== $search ) {
+			$clauses[] = '(LOWER(nome) LIKE LOWER(%s) OR LOWER(codice) LIKE LOWER(%s) OR LOWER(comune) LIKE LOWER(%s))';
+			$like      = '%' . $this->db->esc_like( $search ) . '%';
+			$values[]  = $like;
+			$values[]  = $like;
+			$values[]  = $like;
+		}
+
+		return array(
+			'sql'    => array() === $clauses ? '' : ' WHERE ' . implode( ' AND ', $clauses ),
+			'values' => $values,
+		);
+	}
+
+	/**
+	 * Return a nullable sanitized string from one API field.
+	 *
+	 * @param array<string,mixed> $data API venue data.
+	 */
+	private function api_string( array $data, string $key ): ?string {
+		if ( ! isset( $data[ $key ] ) || null === $data[ $key ] ) {
+			return null;
+		}
+
+		return sanitize_text_field( (string) $data[ $key ] );
+	}
+
+	/**
+	 * Validate a native integer or an all-digit integer string without lossy coercion.
+	 *
+	 * @param mixed $value Raw API identity.
+	 */
+	private function api_remote_id( $value ): ?int {
+		if ( is_int( $value ) ) {
+			return $value > 0 ? $value : null;
+		}
+
+		if ( ! is_string( $value ) || 1 !== preg_match( '/^[0-9]+$/D', $value ) ) {
+			return null;
+		}
+
+		$digits  = ltrim( $value, '0' );
+		$maximum = (string) PHP_INT_MAX;
+		if ( '' === $digits || strlen( $digits ) > strlen( $maximum ) ) {
+			return null;
+		}
+
+		if ( strlen( $digits ) === strlen( $maximum ) && strcmp( $digits, $maximum ) > 0 ) {
+			return null;
+		}
+
+		return (int) $digits;
+	}
+
+	/**
+	 * Build a safe venue persistence exception.
+	 */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException(
+			esc_html__( 'Cinebot WP could not save the venue. Verify its identifiers and try again.', 'cinebot-wp' )
+		);
+	}
+}
diff --git a/includes/Repositories/TipologiaRepository.php b/includes/Repositories/TipologiaRepository.php
new file mode 100644
index 0000000..c6c0102
--- /dev/null
+++ b/includes/Repositories/TipologiaRepository.php
@@ -0,0 +1,175 @@
+<?php
+/**
+ * Event-type persistence.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Repositories;
+
+use CinebotWp\Models\TipologiaEvento;
+use RuntimeException;
+use wpdb;
+
+/**
+ * Persists event types in the plugin table.
+ */
+final class TipologiaRepository {
+	/** @var wpdb */
+	private $db;
+
+	/** @var string */
+	private $table;
+
+	/**
+	 * Store the injected database connection.
+	 */
+	public function __construct( wpdb $db ) {
+		$this->db    = $db;
+		$this->table = $db->prefix . 'cinebot_tipologie_eventi';
+	}
+
+	/**
+	 * Find an event type by its string code.
+	 */
+	public function findByCode( string $code ): ?TipologiaEvento {
+		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE codice = %s", $code ), ARRAY_A );
+
+		return is_array( $row ) ? TipologiaEvento::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Return event types in ascending string-code order.
+	 *
+	 * @return array<int,TipologiaEvento>
+	 */
+	public function findAll( bool $activeOnly = false ): array {
+		$sql = "SELECT * FROM {$this->table}";
+		if ( $activeOnly ) {
+			$sql = $this->db->prepare( $sql . ' WHERE attivo = %d', 1 );
+		}
+		$sql .= ' ORDER BY codice ASC';
+
+		// The table and ordering identifiers are internal fixed fragments.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
+		$rows = $this->db->get_results( $sql, ARRAY_A );
+
+		return array_map(
+			static function ( array $row ): TipologiaEvento {
+				return TipologiaEvento::fromArray( $row );
+			},
+			$rows
+		);
+	}
+
+	/**
+	 * Insert or update an event type and return its local ID.
+	 *
+	 * @throws RuntimeException When the row cannot be stored.
+	 */
+	public function save( TipologiaEvento $type ): int {
+		$now  = current_time( 'mysql', true );
+		$data = array(
+			'codice'       => $type->codice,
+			'descrizione'  => $type->descrizione,
+			'predefinito'  => $type->predefinito,
+			'attivo'       => $type->attivo,
+			'updated_at'   => $now,
+		);
+		$formats = array( '%s', '%s', '%d', '%d', '%s' );
+
+		// wpdb prepares every mapped value using the explicit formats.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		if ( null === $type->id ) {
+			$data['created_at'] = $now;
+			$formats[]          = '%s';
+			$result             = $this->db->insert( $this->table, $data, $formats );
+			$id                 = (int) $this->db->insert_id;
+		} else {
+			$id = (int) $type->id;
+			if ( $id <= 0 || null === $this->findById( $id ) ) {
+				throw $this->save_exception();
+			}
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$result = $this->db->update( $this->table, $data, array( 'id' => $id ), $formats, array( '%d' ) );
+		}
+
+		if ( false === $result || $id <= 0 ) {
+			throw $this->save_exception();
+		}
+
+		return $id;
+	}
+
+	/**
+	 * Enable or disable one event type.
+	 *
+	 * @throws RuntimeException When the ID is invalid, missing, or cannot be updated.
+	 */
+	public function setActive( int $id, bool $active ): void {
+		if ( $id <= 0 || null === $this->findById( $id ) ) {
+			throw new RuntimeException( esc_html__( 'Cinebot WP could not find the event type to update.', 'cinebot-wp' ) );
+		}
+
+		// wpdb prepares every mapped value using the explicit formats.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$result = $this->db->update(
+			$this->table,
+			array(
+				'attivo'     => $active ? 1 : 0,
+				'updated_at' => current_time( 'mysql', true ),
+			),
+			array( 'id' => $id ),
+			array( '%d', '%s' ),
+			array( '%d' )
+		);
+
+		if ( false === $result ) {
+			throw new RuntimeException( esc_html__( 'Cinebot WP could not update the event type status. Try again.', 'cinebot-wp' ) );
+		}
+	}
+
+	/**
+	 * Delete a custom event type only.
+	 */
+	public function deleteCustom( int $id ): bool {
+		if ( $id <= 0 ) {
+			return false;
+		}
+
+		// The predefined predicate is part of the explicit delete boundary.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+		$deleted = $this->db->delete(
+			$this->table,
+			array(
+				'id'          => $id,
+				'predefinito' => 0,
+			),
+			array( '%d', '%d' )
+		);
+
+		return 1 === $deleted;
+	}
+
+	/**
+	 * Find an event type by local ID for mutation validation.
+	 */
+	private function findById( int $id ): ?TipologiaEvento {
+		// The table identifier is a trusted WordPress prefix plus a fixed suffix.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		$row = $this->db->get_row( $this->db->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id ), ARRAY_A );
+
+		return is_array( $row ) ? TipologiaEvento::fromArray( $row ) : null;
+	}
+
+	/**
+	 * Build a safe event-type persistence exception.
+	 */
+	private function save_exception(): RuntimeException {
+		return new RuntimeException(
+			esc_html__( 'Cinebot WP could not save the event type. Verify that its code is unique and try again.', 'cinebot-wp' )
+		);
+	}
+}
diff --git a/tests/Integration/LocaleRepositoryTest.php b/tests/Integration/LocaleRepositoryTest.php
new file mode 100644
index 0000000..1611f9a
--- /dev/null
+++ b/tests/Integration/LocaleRepositoryTest.php
@@ -0,0 +1,316 @@
+<?php
+/**
+ * Venue repository integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Test setup uses a trusted, fixed plugin table identifier.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Models\Locale;
+use CinebotWp\Repositories\LocaleRepository;
+use InvalidArgumentException;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies venue persistence and filtering behavior.
+ */
+final class LocaleRepositoryTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var LocaleRepository */
+	private $repository;
+
+	/**
+	 * Store the WordPress database connection.
+	 */
+	public static function set_up_before_class(): void {
+		parent::set_up_before_class();
+
+		global $wpdb;
+		self::$db = $wpdb;
+	}
+
+	/**
+	 * Install the schema and clear venues before each test.
+	 */
+	public function set_up(): void {
+		parent::set_up();
+
+		( new SchemaInstaller( self::$db ) )->install();
+		self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_locali' );
+		$this->repository = new LocaleRepository( self::$db );
+	}
+
+	/**
+	 * Clear venues after each test.
+	 */
+	public function tear_down(): void {
+		self::$db->query( 'DELETE FROM ' . self::$db->prefix . 'cinebot_locali' );
+
+		parent::tear_down();
+	}
+
+	/**
+	 * Manual venues can be inserted, found, and updated without replacing creation time.
+	 */
+	public function test_save_insert_find_and_update_preserve_manual_source_and_created_timestamp(): void {
+		$locale               = $this->manual_locale( 'Cinema Centro', 'Roma', 'RM' );
+		$locale->codice       = 'CC';
+		$locale->indirizzo    = 'Via Uno 1';
+		$locale->cap          = '00100';
+		$locale->mappa        = 42;
+		$id                   = $this->repository->save( $locale );
+		$stored               = $this->repository->find( $id );
+
+		self::assertGreaterThan( 0, $id );
+		self::assertInstanceOf( Locale::class, $stored );
+		self::assertSame( 'manual', $stored->source );
+		self::assertSame( 'CC', $stored->codice );
+		self::assertSame( 42, $stored->mappa );
+		self::assertNotNull( $stored->createdAt );
+		self::assertSame( $stored->createdAt, $stored->updatedAt );
+		self::assertNull( $this->repository->find( PHP_INT_MAX ) );
+
+		$old_updated_at = '2000-01-01 00:00:00';
+		self::$db->update(
+			self::$db->prefix . 'cinebot_locali',
+			array( 'updated_at' => $old_updated_at ),
+			array( 'id' => $id ),
+			array( '%s' ),
+			array( '%d' )
+		);
+		$stored = $this->repository->find( $id );
+		self::assertInstanceOf( Locale::class, $stored );
+		self::assertSame( $old_updated_at, $stored->updatedAt );
+
+		$created_at       = $stored->createdAt;
+		$stored->nome     = 'Cinema Centro Nuovo';
+		$stored->source   = 'manual';
+		self::assertSame( $id, $this->repository->save( $stored ) );
+		$updated = $this->repository->find( $id );
+		self::assertInstanceOf( Locale::class, $updated );
+		self::assertSame( 'Cinema Centro Nuovo', $updated->nome );
+		self::assertSame( $created_at, $updated->createdAt );
+		self::assertNotSame( $old_updated_at, $updated->updatedAt );
+	}
+
+	/**
+	 * API upsert creates and updates API-owned venues.
+	 */
+	public function test_upsert_api_creates_and_updates_api_owned_venue(): void {
+		$id = $this->repository->upsertApi(
+			array(
+				'localeId'     => 901,
+				'locale'       => 'Arena API',
+				'localeCodice' => 'API-1',
+				'indirizzo'    => 'Via API 1',
+				'cap'          => '20100',
+				'comune'       => 'Milano',
+				'provincia'    => 'MI',
+				'mappa'        => 8,
+			)
+		);
+		$created = $this->repository->findByRemoteId( 901 );
+
+		self::assertInstanceOf( Locale::class, $created );
+		self::assertSame( $id, $created->id );
+		self::assertSame( 'api', $created->source );
+		self::assertSame( 'Arena API', $created->nome );
+		$created_at = $created->createdAt;
+
+		self::assertSame(
+			$id,
+			$this->repository->upsertApi(
+				array(
+					'localeId'     => 901,
+					'locale'       => 'Arena API Updated',
+					'localeCodice' => 'API-2',
+					'indirizzo'    => null,
+					'cap'          => null,
+					'comune'       => 'Monza',
+					'provincia'    => 'MB',
+					'mappa'        => 9,
+				)
+			)
+		);
+		$updated = $this->repository->findByRemoteId( 901 );
+		self::assertInstanceOf( Locale::class, $updated );
+		self::assertSame( 'Arena API Updated', $updated->nome );
+		self::assertSame( 'API-2', $updated->codice );
+		self::assertSame( 'api', $updated->source );
+		self::assertSame( $created_at, $updated->createdAt );
+	}
+
+	/**
+	 * API synchronization never overwrites a manually owned remote identity.
+	 */
+	public function test_upsert_api_returns_manual_match_unchanged(): void {
+		$manual                 = $this->manual_locale( 'Manual owner', 'Torino', 'TO' );
+		$manual->localeIdRemoto = 777;
+		$manual->codice         = 'MANUAL-777';
+		$manual->indirizzo      = 'Via Manuale 7';
+		$manual->cap            = '10100';
+		$manual->mappa          = 77;
+		$id                     = $this->repository->save( $manual );
+		$before                 = $this->repository->findByRemoteId( 777 );
+		self::assertInstanceOf( Locale::class, $before );
+		$before_state = $before->toArray();
+
+		self::assertSame(
+			$id,
+			$this->repository->upsertApi(
+				array(
+					'localeId'  => 777,
+					'locale'    => 'API replacement',
+					'comune'    => 'Elsewhere',
+					'provincia' => 'XX',
+				)
+			)
+		);
+		$stored = $this->repository->findByRemoteId( 777 );
+		self::assertInstanceOf( Locale::class, $stored );
+		self::assertSame( $before_state, $stored->toArray() );
+	}
+
+	/**
+	 * API upsert accepts an all-digit positive string identity without coercing malformed values.
+	 */
+	public function test_upsert_api_accepts_positive_all_digit_string_id(): void {
+		$id = $this->repository->upsertApi(
+			array(
+				'localeId' => '00902',
+				'locale'   => 'String identity venue',
+			)
+		);
+
+		$stored = $this->repository->findByRemoteId( 902 );
+		self::assertInstanceOf( Locale::class, $stored );
+		self::assertSame( $id, $stored->id );
+	}
+
+	/**
+	 * API payloads require a positive remote ID and non-empty venue name.
+	 *
+	 * @dataProvider invalid_api_payload_provider
+	 * @param array<string,mixed> $payload Invalid API payload.
+	 */
+	public function test_upsert_api_rejects_invalid_payloads( array $payload ): void {
+		$this->expectException( InvalidArgumentException::class );
+
+		$this->repository->upsertApi( $payload );
+	}
+
+	/**
+	 * Provide malformed API payloads.
+	 *
+	 * @return array<string,array{array<string,mixed>}>
+	 */
+	public function invalid_api_payload_provider(): array {
+		return array(
+			'missing remote ID' => array( array( 'locale' => 'Venue' ) ),
+			'zero remote ID'    => array( array( 'localeId' => 0, 'locale' => 'Venue' ) ),
+			'negative ID'       => array( array( 'localeId' => -1, 'locale' => 'Venue' ) ),
+			'float ID'          => array( array( 'localeId' => 1.9, 'locale' => 'Venue' ) ),
+			'true ID'           => array( array( 'localeId' => true, 'locale' => 'Venue' ) ),
+			'false ID'          => array( array( 'localeId' => false, 'locale' => 'Venue' ) ),
+			'signed string ID'  => array( array( 'localeId' => '+1', 'locale' => 'Venue' ) ),
+			'minus string ID'   => array( array( 'localeId' => '-1', 'locale' => 'Venue' ) ),
+			'decimal string ID' => array( array( 'localeId' => '1.0', 'locale' => 'Venue' ) ),
+			'alphanumeric ID'   => array( array( 'localeId' => '1junk', 'locale' => 'Venue' ) ),
+			'whitespace ID'     => array( array( 'localeId' => ' 1 ', 'locale' => 'Venue' ) ),
+			'zero string ID'    => array( array( 'localeId' => '000', 'locale' => 'Venue' ) ),
+			'overflowing ID'    => array( array( 'localeId' => '999999999999999999999999', 'locale' => 'Venue' ) ),
+			'missing name'      => array( array( 'localeId' => 1 ) ),
+			'blank name'        => array( array( 'localeId' => 1, 'locale' => '   ' ) ),
+		);
+	}
+
+	/**
+	 * Combined filters and free text share identical search and count predicates.
+	 */
+	public function test_search_combines_filters_and_count_matches(): void {
+		$this->repository->save( $this->manual_locale( 'Cinema Alfa', 'Roma', 'RM' ) );
+		$this->repository->save( $this->manual_locale( 'Teatro Beta', 'Roma', 'RM' ) );
+		$this->repository->save( $this->manual_locale( 'Cinema Gamma', 'Roma', 'VT' ) );
+		$this->repository->save( $this->manual_locale( 'Cinema Delta', 'Milano', 'MI' ) );
+
+		$filters = array(
+			'provincia' => ' RM ',
+			'comune'    => ' Roma ',
+			'search'    => 'cinema',
+		);
+		$results = $this->repository->search( $filters, 1, 20 );
+
+		self::assertCount( 1, $results );
+		self::assertContainsOnlyInstancesOf( Locale::class, $results );
+		self::assertSame( 'Cinema Alfa', $results[0]->nome );
+		self::assertSame( count( $results ), $this->repository->count( $filters ) );
+	}
+
+	/**
+	 * Search checks name, code, and comune without broadening injection-shaped values.
+	 */
+	public function test_text_search_fields_and_injection_shaped_filters_are_safe(): void {
+		$coded         = $this->manual_locale( 'Auditorium', 'Firenze', 'FI' );
+		$coded->codice = 'SPECIAL-CODE';
+		$this->repository->save( $coded );
+		$this->repository->save( $this->manual_locale( 'Other venue', 'Roma', 'RM' ) );
+
+		self::assertCount( 1, $this->repository->search( array( 'search' => 'special-code' ), 1, 10 ) );
+		self::assertCount( 1, $this->repository->search( array( 'search' => 'fIrEnZe' ), 1, 10 ) );
+		self::assertSame( 0, $this->repository->count( array( 'provincia' => "RM' OR 1=1 --" ) ) );
+		self::assertSame( 0, $this->repository->count( array( 'comune' => "Roma' OR 1=1 --" ) ) );
+		self::assertSame( 0, $this->repository->count( array( 'search' => "%' OR 1=1 --" ) ) );
+	}
+
+	/**
+	 * Pagination clamps positive bounds and orders equal names by local ID.
+	 */
+	public function test_search_paginates_deterministically_and_clamps_bounds(): void {
+		$first_same  = $this->repository->save( $this->manual_locale( 'Same Name', 'Roma', 'RM' ) );
+		$alpha       = $this->repository->save( $this->manual_locale( 'Alpha', 'Roma', 'RM' ) );
+		$second_same = $this->repository->save( $this->manual_locale( 'Same Name', 'Roma', 'RM' ) );
+		$omega       = $this->repository->save( $this->manual_locale( 'Omega', 'Roma', 'RM' ) );
+
+		$page_one = $this->repository->search( array(), 1, 2 );
+		$page_two = $this->repository->search( array(), 2, 2 );
+		$clamped  = $this->repository->search( array(), 0, 0 );
+
+		self::assertSame( array( $alpha, $omega ), array_column( array_map( array( $this, 'locale_to_array' ), $page_one ), 'id' ) );
+		self::assertSame( array( $first_same, $second_same ), array_column( array_map( array( $this, 'locale_to_array' ), $page_two ), 'id' ) );
+		self::assertCount( 1, $clamped );
+		self::assertSame( $alpha, $clamped[0]->id );
+		self::assertSame( array(), $this->repository->search( array(), 99, 2 ) );
+	}
+
+	/**
+	 * Create a manual venue fixture.
+	 */
+	private function manual_locale( string $name, string $city, string $province ): Locale {
+		$locale            = new Locale();
+		$locale->nome      = $name;
+		$locale->comune    = $city;
+		$locale->provincia = $province;
+		$locale->source    = 'manual';
+
+		return $locale;
+	}
+
+	/**
+	 * Convert a DTO for concise ordered-ID assertions.
+	 *
+	 * @return array<string,mixed>
+	 */
+	public function locale_to_array( Locale $locale ): array {
+		return $locale->toArray();
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
diff --git a/tests/Integration/TipologiaRepositoryTest.php b/tests/Integration/TipologiaRepositoryTest.php
new file mode 100644
index 0000000..d5152d1
--- /dev/null
+++ b/tests/Integration/TipologiaRepositoryTest.php
@@ -0,0 +1,233 @@
+<?php
+/**
+ * Event-type repository integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Test setup and assertions use trusted, fixed plugin table identifiers.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Models\TipologiaEvento;
+use CinebotWp\Repositories\TipologiaRepository;
+use RuntimeException;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies event-type persistence behavior.
+ */
+final class TipologiaRepositoryTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var TipologiaRepository */
+	private $repository;
+
+	/**
+	 * Store the WordPress database connection.
+	 */
+	public static function set_up_before_class(): void {
+		parent::set_up_before_class();
+
+		global $wpdb;
+		self::$db = $wpdb;
+	}
+
+	/**
+	 * Recreate the event-type table and its approved defaults.
+	 */
+	public function set_up(): void {
+		parent::set_up();
+
+		self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_tipologie_eventi' );
+		( new SchemaInstaller( self::$db ) )->install();
+		$this->repository = new TipologiaRepository( self::$db );
+	}
+
+	/**
+	 * Remove the test table.
+	 */
+	public function tear_down(): void {
+		self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_tipologie_eventi' );
+
+		parent::tear_down();
+	}
+
+	/**
+	 * Leading zeroes survive lookup and rows are returned as DTOs.
+	 */
+	public function test_find_by_code_preserves_leading_zero_code(): void {
+		$type = $this->repository->findByCode( '01' );
+
+		self::assertInstanceOf( TipologiaEvento::class, $type );
+		self::assertSame( '01', $type->codice );
+		self::assertSame( 'CINEMA', $type->descrizione );
+		self::assertNull( $this->repository->findByCode( 'missing' ) );
+	}
+
+	/**
+	 * Active filtering retains ascending string-code order.
+	 */
+	public function test_find_all_filters_active_rows_and_orders_by_code(): void {
+		$disabled = $this->repository->findByCode( '01' );
+		self::assertInstanceOf( TipologiaEvento::class, $disabled );
+		$this->repository->setActive( (int) $disabled->id, false );
+
+		$all    = $this->repository->findAll();
+		$active = $this->repository->findAll( true );
+		$codes  = array_map(
+			static function ( TipologiaEvento $type ): string {
+				return $type->codice;
+			},
+			$all
+		);
+
+		self::assertNotEmpty( $all );
+		self::assertContainsOnlyInstancesOf( TipologiaEvento::class, $all );
+		self::assertSame( '01', $codes[0] );
+		self::assertSame( $codes, array_values( array_unique( $codes ) ) );
+		self::assertSame( array_values( $codes ), $this->sorted_codes( $codes ) );
+		self::assertNotContains( '01', array_column( array_map( array( $this, 'type_to_array' ), $active ), 'codice' ) );
+	}
+
+	/**
+	 * Custom rows support insert, update, disable, and delete without replacing creation time.
+	 */
+	public function test_custom_type_lifecycle_preserves_created_timestamp(): void {
+		$type               = new TipologiaEvento();
+		$type->codice       = 'CUSTOM';
+		$type->descrizione  = 'Custom type';
+		$type->predefinito  = 0;
+		$type->attivo       = 1;
+		$id                 = $this->repository->save( $type );
+		$stored             = $this->repository->findByCode( 'CUSTOM' );
+
+		self::assertGreaterThan( 0, $id );
+		self::assertInstanceOf( TipologiaEvento::class, $stored );
+		self::assertSame( $id, $stored->id );
+		self::assertNotNull( $stored->createdAt );
+		self::assertSame( $stored->createdAt, $stored->updatedAt );
+
+		$old_updated_at = '2000-01-01 00:00:00';
+		self::$db->update(
+			self::$db->prefix . 'cinebot_tipologie_eventi',
+			array( 'updated_at' => $old_updated_at ),
+			array( 'id' => $id ),
+			array( '%s' ),
+			array( '%d' )
+		);
+		$stored = $this->repository->findByCode( 'CUSTOM' );
+		self::assertInstanceOf( TipologiaEvento::class, $stored );
+		self::assertSame( $old_updated_at, $stored->updatedAt );
+
+		$created_at          = $stored->createdAt;
+		$stored->descrizione = 'Updated custom type';
+		self::assertSame( $id, $this->repository->save( $stored ) );
+		$updated = $this->repository->findByCode( 'CUSTOM' );
+		self::assertInstanceOf( TipologiaEvento::class, $updated );
+		self::assertSame( 'Updated custom type', $updated->descrizione );
+		self::assertSame( $created_at, $updated->createdAt );
+		self::assertNotSame( $old_updated_at, $updated->updatedAt );
+
+		$this->repository->setActive( $id, false );
+		$disabled = $this->repository->findByCode( 'CUSTOM' );
+		self::assertInstanceOf( TipologiaEvento::class, $disabled );
+		self::assertSame( 0, $disabled->attivo );
+		self::assertTrue( $this->repository->deleteCustom( $id ) );
+		self::assertFalse( $this->repository->deleteCustom( $id ) );
+		self::assertNull( $this->repository->findByCode( 'CUSTOM' ) );
+	}
+
+	/**
+	 * Predefined and missing rows cannot be deleted as custom types.
+	 */
+	public function test_delete_custom_rejects_predefined_and_missing_rows(): void {
+		$type = $this->repository->findByCode( '01' );
+		self::assertInstanceOf( TipologiaEvento::class, $type );
+
+		self::assertFalse( $this->repository->deleteCustom( (int) $type->id ) );
+		self::assertFalse( $this->repository->deleteCustom( PHP_INT_MAX ) );
+		self::assertNotNull( $this->repository->findByCode( '01' ) );
+	}
+
+	/**
+	 * Duplicate codes produce an actionable exception without exposing SQL.
+	 */
+	public function test_save_rejects_duplicate_codes_with_safe_error(): void {
+		$type              = new TipologiaEvento();
+		$type->codice      = '01';
+		$type->descrizione = 'Duplicate';
+
+		try {
+			$this->repository->save( $type );
+			self::fail( 'A duplicate event-type code should fail.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
+			self::assertDoesNotMatchRegularExpression( '/\b(?:insert|update|select|delete)\b/i', $exception->getMessage() );
+		}
+	}
+
+	/**
+	 * Failed writes and invalid identifiers produce safe actionable exceptions.
+	 */
+	public function test_failed_updates_and_missing_activation_targets_throw(): void {
+		$type = $this->repository->findByCode( '01' );
+		self::assertInstanceOf( TipologiaEvento::class, $type );
+
+		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			public function update( $table, $data, $where, $format = null, $where_format = null ) {
+				return false;
+			}
+		};
+		$db->set_prefix( self::$db->prefix );
+
+		try {
+			$failing_repository = new TipologiaRepository( $db );
+			try {
+				$failing_repository->save( $type );
+				self::fail( 'A failed database update should throw.' );
+			} catch ( RuntimeException $exception ) {
+				self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
+				self::assertDoesNotMatchRegularExpression( '/\bupdate\b/i', $exception->getMessage() );
+			}
+		} finally {
+			$db->close();
+		}
+
+		foreach ( array( 0, -1, PHP_INT_MAX ) as $id ) {
+			try {
+				$this->repository->setActive( $id, true );
+				self::fail( 'An invalid or missing event-type ID should throw.' );
+			} catch ( RuntimeException $exception ) {
+				self::assertStringContainsString( 'event type', strtolower( $exception->getMessage() ) );
+			}
+		}
+	}
+
+	/**
+	 * Convert a DTO for concise assertions.
+	 *
+	 * @return array<string,mixed>
+	 */
+	public function type_to_array( TipologiaEvento $type ): array {
+		return $type->toArray();
+	}
+
+	/**
+	 * Sort codes using the repository's documented string ordering.
+	 *
+	 * @param string[] $codes Codes to sort.
+	 * @return string[]
+	 */
+	private function sorted_codes( array $codes ): array {
+		sort( $codes, SORT_STRING );
+
+		return $codes;
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
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 4 commits. No Task 4 implementation file is currently modified or untracked.
