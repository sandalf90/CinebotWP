# Task 2 Reviewer Handoff Package

## Scope

Task 2 implements the seven-table Cinebot schema, the 62 approved event-type defaults, activation/deactivation lifecycle behavior, single-site uninstall cleanup, integration coverage, and the PHPStan path update. The follow-up fix makes default seeding transactional and retryable, fingerprints the complete catalog, and adds uninstall boundary coverage. Review range: parent `1aaf9f510b52a641ba51f049203e425dea299ac0` through commits `f3240382aec5e911cd40f4fc66399affa5278b14` and `3007fcba0de52ec356304da7abbdcf15c657f77a`.

The updated Task 2 report records that the required red and green commands could not reach PHPUnit because the Docker Desktop Linux engine was unavailable; host PHP was also unavailable. Static checks additionally verified the canonical 62-row fingerprint, explicit transaction/rollback shape, rollback/retry contract, and uninstall fixture isolation.

## Commit Metadata

```text
commit f3240382aec5e911cd40f4fc66399affa5278b14
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:42:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:42:12 2026 +0200

    feat: install cinebot database schema

commit 3007fcba0de52ec356304da7abbdcf15c657f77a
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:58:34 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:58:34 2026 +0200

    fix: make schema lifecycle recoverable
```

## Full Stat

Command: `git show --stat --format=fuller f324038 3007fcb`

```text
commit f3240382aec5e911cd40f4fc66399affa5278b14
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:42:12 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:42:12 2026 +0200

    feat: install cinebot database schema

 .superpowers/sdd/task-2-report.md         | 101 +++++++++++
 cinebot-wp.php                            |   7 +-
 includes/Database/EventTypeDefaults.php   |  88 ++++++++++
 includes/Database/SchemaInstaller.php     | 258 ++++++++++++++++++++++++++++
 includes/Plugin.php                       |  18 ++
 phpstan.neon.dist                         |   1 +
 tests/Integration/SchemaInstallerTest.php | 276 ++++++++++++++++++++++++++++++
 uninstall.php                             |  47 +++++
 8 files changed, 795 insertions(+), 1 deletion(-)

commit 3007fcba0de52ec356304da7abbdcf15c657f77a
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Wed Aug 5 23:58:34 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Wed Aug 5 23:58:34 2026 +0200

    fix: make schema lifecycle recoverable

 .superpowers/sdd/task-2-report.md                  |  56 +++++++++++
 .../plans/2026-08-02-cinebot-wp-plugin.md          |  11 ++-
 includes/Database/SchemaInstaller.php              |  38 +++++++-
 tests/Integration/SchemaInstallerTest.php          |  97 +++++++++++++++++-
 tests/Integration/UninstallTest.php                | 108 +++++++++++++++++++++
 5 files changed, 302 insertions(+), 8 deletions(-)
```

## Coordinator Artifact Summary

- `.superpowers/sdd/task-2-report.md` is committed coordination evidence, not runtime implementation. Its current form records the initial implementation and the transactional-seed/uninstall fix review, blocked Docker/PHP execution, static checks, and the remaining dynamic-verification concern.
- `.superpowers/sdd/task-2-brief.md` is an uncommitted coordinator brief defining Task 2 scope and acceptance criteria.
- `.superpowers/sdd/progress.md`, `specs/state.yaml`, and `specs/execution-status.yaml` are unrelated orchestration state.
- `.superpowers/sdd/task-1-review.md` and `.superpowers/sdd/task-1-review-package.md` are unrelated Task 1 review artifacts.

## Complete Task 2 Implementation Diff

Command: `git diff --unified=10 1aaf9f5 f324038 -- cinebot-wp.php includes/Database/EventTypeDefaults.php includes/Database/SchemaInstaller.php includes/Plugin.php phpstan.neon.dist tests/Integration/SchemaInstallerTest.php uninstall.php`

```diff
diff --git a/cinebot-wp.php b/cinebot-wp.php
index 8e3f890..4d91110 100644
--- a/cinebot-wp.php
+++ b/cinebot-wp.php
@@ -4,18 +4,23 @@
  * Description: Cinebot schedule synchronization for WordPress.
  * Version: 1.0.0
  * Requires at least: 6.0
  * Requires PHP: 7.4
  * Text Domain: cinebot-wp
  * License: GPL-2.0-or-later
  *
  * @package CinebotWp
  */
 
+use CinebotWp\Plugin;
+
 define( 'CINEBOT_WP_VERSION', '1.0.0' );
 define( 'CINEBOT_WP_FILE', __FILE__ );
 define( 'CINEBOT_WP_PATH', plugin_dir_path( __FILE__ ) );
 define( 'CINEBOT_WP_URL', plugin_dir_url( __FILE__ ) );
 
 require CINEBOT_WP_PATH . 'includes/autoload.php';
 
-CinebotWp\Plugin::instance()->boot();
+register_activation_hook( CINEBOT_WP_FILE, array( Plugin::class, 'activate' ) );
+register_deactivation_hook( CINEBOT_WP_FILE, array( Plugin::class, 'deactivate' ) );
+
+Plugin::instance()->boot();
diff --git a/includes/Database/EventTypeDefaults.php b/includes/Database/EventTypeDefaults.php
new file mode 100644
index 0000000..d3034c8
--- /dev/null
+++ b/includes/Database/EventTypeDefaults.php
@@ -0,0 +1,88 @@
+<?php
+/**
+ * Built-in event type definitions.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Database;
+
+/**
+ * Provides the event types approved for initial installation.
+ */
+final class EventTypeDefaults {
+	/**
+	 * Return all built-in event types.
+	 *
+	 * @return array<int,array{codice:string,descrizione:string}>
+	 */
+	public static function all(): array {
+		// Keeping each immutable code/description pair on one line makes the approved catalog auditable.
+		// phpcs:disable Generic.Files.LineLength.TooLong
+		return array(
+			array( 'codice' => '01', 'descrizione' => 'CINEMA' ),
+			array( 'codice' => '04', 'descrizione' => 'PROIEZIONI IN LOCALI CINEMA DIVERSE DA SPETTACOLO' ),
+			array( 'codice' => '05', 'descrizione' => 'CALCIO (SERIE A/B ED INTERNAZIONALI)' ),
+			array( 'codice' => '06', 'descrizione' => 'CALCIO (SERIE C ED INFERIORI)' ),
+			array( 'codice' => '07', 'descrizione' => 'TELEDIFFUSIONE IN FORMA CODIFICATA NEI LOCALI APERTI AL PUBBLICO' ),
+			array( 'codice' => '08', 'descrizione' => 'DIFFUSIONE RADIO/TV CON ACCESSO CONDIZIONATO' ),
+			array( 'codice' => '10', 'descrizione' => 'PUGILATO' ),
+			array( 'codice' => '11', 'descrizione' => 'CICLISMO' ),
+			array( 'codice' => '12', 'descrizione' => 'ATLETICA LEGGERA' ),
+			array( 'codice' => '13', 'descrizione' => 'NUOTO E PALLANUOTO' ),
+			array( 'codice' => '14', 'descrizione' => 'PALLACANESTRO' ),
+			array( 'codice' => '15', 'descrizione' => 'PALLAVOLO' ),
+			array( 'codice' => '16', 'descrizione' => 'RUGBY' ),
+			array( 'codice' => '17', 'descrizione' => 'BASEBALL' ),
+			array( 'codice' => '18', 'descrizione' => 'TENNIS' ),
+			array( 'codice' => '19', 'descrizione' => 'CONCORSI IPPICI' ),
+			array( 'codice' => '20', 'descrizione' => 'SPORT INVERNALI' ),
+			array( 'codice' => '21', 'descrizione' => 'AUTOMOBILISMO' ),
+			array( 'codice' => '22', 'descrizione' => 'MOTOCICLISMO' ),
+			array( 'codice' => '23', 'descrizione' => 'MOTONAUTICA' ),
+			array( 'codice' => '24', 'descrizione' => 'CORSE CAVALLI (INGRESSI)' ),
+			array( 'codice' => '25', 'descrizione' => 'SPORT CON SCOMMESSE (INGRESSI)' ),
+			array( 'codice' => '26', 'descrizione' => 'ALTRI SPORT (INGRESSI)' ),
+			array( 'codice' => '30', 'descrizione' => 'CASINÒ (INGRESSI)' ),
+			array( 'codice' => '33', 'descrizione' => 'CASINÒ (PROVENTI DEL GIOCO)' ),
+			array( 'codice' => '41', 'descrizione' => 'MUSEI' ),
+			array( 'codice' => '42', 'descrizione' => 'EVENTI DIVERSI DA SPETTACOLO O INTRATTENIMENTO' ),
+			array( 'codice' => '45', 'descrizione' => 'TEATRO PROSA' ),
+			array( 'codice' => '46', 'descrizione' => 'TEATRO PROSA DIALETTALE' ),
+			array( 'codice' => '47', 'descrizione' => 'TEATRO REPERTORIO NAPOLETANO' ),
+			array( 'codice' => '48', 'descrizione' => 'TEATRO LIRICO' ),
+			array( 'codice' => '49', 'descrizione' => 'BALLETTO CLASSICO E MODERNO' ),
+			array( 'codice' => '50', 'descrizione' => 'OPERETTA' ),
+			array( 'codice' => '51', 'descrizione' => 'RIVISTE-COMMEDIE MUSICALI' ),
+			array( 'codice' => '52', 'descrizione' => 'CONCERTI CLASSICI' ),
+			array( 'codice' => '53', 'descrizione' => 'CONCERTI MUSICA LEGGERA' ),
+			array( 'codice' => '54', 'descrizione' => 'ARTE VARIA (IVA 10%)' ),
+			array( 'codice' => '55', 'descrizione' => 'BURATTINI-MARIONETTE' ),
+			array( 'codice' => '56', 'descrizione' => 'RECITALS LETTERARI' ),
+			array( 'codice' => '57', 'descrizione' => 'CONCERTI BANDISTICI-CORALI' ),
+			array( 'codice' => '58', 'descrizione' => 'CONCERTI JAZZ' ),
+			array( 'codice' => '59', 'descrizione' => 'CONCERTI DI DANZA' ),
+			array( 'codice' => '60', 'descrizione' => 'BALLO CON MUSICA DAL VIVO' ),
+			array( 'codice' => '61', 'descrizione' => 'BALLO CON MUSICA PREREGISTRATA' ),
+			array( 'codice' => '64', 'descrizione' => 'CONCERTINI CON MUSICA PREREGISTRATA' ),
+			array( 'codice' => '65', 'descrizione' => 'CONCERTINI CON MUSICA DAL VIVO' ),
+			array( 'codice' => '67', 'descrizione' => 'CONCERTI CORALI' ),
+			array( 'codice' => '68', 'descrizione' => 'CONCERTI FOLKLORISTICI' ),
+			array( 'codice' => '70', 'descrizione' => 'FIERE' ),
+			array( 'codice' => '71', 'descrizione' => 'MOSTRE' ),
+			array( 'codice' => '74', 'descrizione' => 'ARTE VARIA (IVA 22%)' ),
+			array( 'codice' => '75', 'descrizione' => 'CIRCO' ),
+			array( 'codice' => '76', 'descrizione' => 'SPETTACOLI VIAGGIANTI' ),
+			array( 'codice' => '77', 'descrizione' => 'PARCHI DIVERTIMENTO E ACQUATICI (con prevalenza attività dello spettacolo viaggiante)' ),
+			array( 'codice' => '78', 'descrizione' => 'PARCHI DIVERTIMENTO E ACQUATICI (senza prevalenza attività dello spettacolo viaggiante)' ),
+			array( 'codice' => '84', 'descrizione' => 'BOWLING' ),
+			array( 'codice' => '85', 'descrizione' => 'NOLEGGIO GO-KARTS' ),
+			array( 'codice' => '90', 'descrizione' => 'MANIFESTAZIONI MISTE (all\'aperto)' ),
+			array( 'codice' => '91', 'descrizione' => 'MULTIMEDIALITÀ' ),
+			array( 'codice' => '97', 'descrizione' => 'ALTRE ATTIVITÀ DI SPETTACOLO CONGIUNTE CON ALTRE NON DI SPETTACOLO' ),
+			array( 'codice' => '98', 'descrizione' => 'ALTRI SPETTACOLI O INTRATTENIMENTI (in alberghi e villaggi turistici)' ),
+			array( 'codice' => '99', 'descrizione' => 'ALBERGHI E VILLAGGI TURISTICI (attività di spettacolo)' ),
+		);
+		// phpcs:enable Generic.Files.LineLength.TooLong
+	}
+}
diff --git a/includes/Database/SchemaInstaller.php b/includes/Database/SchemaInstaller.php
new file mode 100644
index 0000000..d794623
--- /dev/null
+++ b/includes/Database/SchemaInstaller.php
@@ -0,0 +1,258 @@
+<?php
+/**
+ * Database schema installation.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Database;
+
+use RuntimeException;
+use wpdb;
+
+/**
+ * Installs the plugin's custom tables and initial data.
+ */
+final class SchemaInstaller {
+	/** @var wpdb */
+	private $db;
+
+	/**
+	 * Store the WordPress database connection.
+	 */
+	public function __construct( wpdb $db ) {
+		$this->db = $db;
+	}
+
+	/**
+	 * Install or update the database schema.
+	 *
+	 * @throws RuntimeException When InnoDB is unavailable or defaults cannot be stored.
+	 */
+	public function install(): void {
+		if ( ! $this->supportsTransactions() ) {
+			throw new RuntimeException(
+				esc_html__(
+					'Cinebot WP requires InnoDB support. Enable InnoDB on the database server, then activate the plugin again.',
+					'cinebot-wp'
+				)
+			);
+		}
+
+		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
+
+		foreach ( $this->schema_statements() as $statement ) {
+			dbDelta( $statement );
+		}
+
+		if ( ! add_option( 'cinebot_wp_db_version', '1.0.0', '', false ) ) {
+			update_option( 'cinebot_wp_db_version', '1.0.0', false );
+		}
+
+		$this->seed_event_types();
+	}
+
+	/**
+	 * Return whether the server supports transactional InnoDB tables.
+	 */
+	public function supportsTransactions(): bool {
+		// Server engine metadata is not cacheable application data.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
+		$engines = $this->db->get_results( 'SHOW ENGINES', ARRAY_A );
+
+		foreach ( $engines as $engine ) {
+			$name    = isset( $engine['Engine'] ) ? $engine['Engine'] : '';
+			$support = isset( $engine['Support'] ) ? $engine['Support'] : '';
+			if ( 'innodb' === strtolower( (string) $name ) && in_array( strtoupper( (string) $support ), array( 'YES', 'DEFAULT' ), true ) ) {
+				return true;
+			}
+		}
+
+		return false;
+	}
+
+	/**
+	 * Return the seven dbDelta statements.
+	 *
+	 * @return string[]
+	 */
+	private function schema_statements(): array {
+		$base            = $this->db->prefix . 'cinebot_';
+		$charset_collate = $this->db->get_charset_collate();
+		$table_options   = "ENGINE=InnoDB {$charset_collate}";
+
+		return array(
+			"CREATE TABLE {$base}titoli (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				idtitolo bigint(20) unsigned NULL,
+				frontend_id bigint(20) unsigned NULL,
+				titolo varchar(255) NOT NULL,
+				autore varchar(255) NULL,
+				esecutore varchar(255) NULL,
+				durata int NULL,
+				scadenza tinyint(1) unsigned NULL,
+				descrizione longtext NULL,
+				tipoevento_codice varchar(10) NULL,
+				locandina_flag tinyint(1) unsigned NULL,
+				locandina_url varchar(500) NULL,
+				cinetel varchar(100) NULL,
+				tmdb varchar(100) NULL,
+				trailer varchar(500) NULL,
+				`cast` text NULL,
+				tag text NULL,
+				source varchar(10) NOT NULL DEFAULT 'api',
+				sync_hash varchar(64) NULL,
+				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
+				last_seen_sync char(36) NULL,
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				UNIQUE KEY idtitolo (idtitolo),
+				KEY source (source),
+				KEY tipoevento_codice (tipoevento_codice),
+				KEY sync_active (sync_active),
+				KEY frontend_sync (frontend_id,sync_active,last_seen_sync)
+			) {$table_options};",
+			"CREATE TABLE {$base}eventi (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				idevento bigint(20) unsigned NULL,
+				titolo_id bigint(20) unsigned NOT NULL,
+				inizio datetime NOT NULL,
+				organizzatore_id bigint(20) NULL,
+				organizzatore_cf varchar(50) NULL,
+				locale_id bigint(20) unsigned NOT NULL,
+				stato tinyint NULL,
+				otp tinyint(1) unsigned NULL,
+				controlloaccessi tinyint(1) unsigned NULL,
+				mappa int NULL,
+				source varchar(10) NOT NULL DEFAULT 'api',
+				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
+				last_seen_sync char(36) NULL,
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				UNIQUE KEY idevento (idevento),
+				KEY titolo_id (titolo_id),
+				KEY locale_id (locale_id),
+				KEY inizio (inizio),
+				KEY sync_active (sync_active),
+				KEY titolo_sync (titolo_id,sync_active,last_seen_sync)
+			) {$table_options};",
+			"CREATE TABLE {$base}settori (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				idsettore bigint(20) unsigned NULL,
+				evento_id bigint(20) unsigned NOT NULL,
+				nome varchar(255) NULL,
+				source varchar(10) NOT NULL DEFAULT 'api',
+				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
+				last_seen_sync char(36) NULL,
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				KEY evento_id (evento_id),
+				KEY sync_active (sync_active),
+				UNIQUE KEY remote_evento (idsettore,evento_id),
+				KEY evento_sync (evento_id,sync_active,last_seen_sync)
+			) {$table_options};",
+			"CREATE TABLE {$base}prezzi (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				idprezzo bigint(20) unsigned NULL,
+				settore_id bigint(20) unsigned NOT NULL,
+				nome varchar(255) NULL,
+				tipo varchar(5) NULL,
+				importo decimal(10,2) NULL,
+				prevendita decimal(10,2) NULL,
+				stato tinyint NULL,
+				source varchar(10) NOT NULL DEFAULT 'api',
+				sync_active tinyint(1) unsigned NOT NULL DEFAULT 1,
+				last_seen_sync char(36) NULL,
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				KEY settore_id (settore_id),
+				KEY sync_active (sync_active),
+				UNIQUE KEY remote_settore (idprezzo,settore_id),
+				KEY settore_sync (settore_id,sync_active,last_seen_sync)
+			) {$table_options};",
+			"CREATE TABLE {$base}locali (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				locale_id_remoto bigint(20) unsigned NULL,
+				nome varchar(255) NOT NULL,
+				codice varchar(50) NULL,
+				indirizzo varchar(255) NULL,
+				cap varchar(10) NULL,
+				comune varchar(100) NULL,
+				provincia varchar(10) NULL,
+				mappa int NULL,
+				source varchar(10) NOT NULL DEFAULT 'manual',
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				UNIQUE KEY locale_id_remoto (locale_id_remoto),
+				KEY comune (comune)
+			) {$table_options};",
+			"CREATE TABLE {$base}tipologie_eventi (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				codice varchar(10) NOT NULL,
+				descrizione varchar(255) NOT NULL,
+				predefinito tinyint(1) unsigned NOT NULL DEFAULT 0,
+				attivo tinyint(1) unsigned NOT NULL DEFAULT 1,
+				created_at datetime NULL,
+				updated_at datetime NULL,
+				PRIMARY KEY  (id),
+				UNIQUE KEY codice (codice)
+			) {$table_options};",
+			"CREATE TABLE {$base}sync_log (
+				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
+				started_at datetime NOT NULL,
+				finished_at datetime NULL,
+				status varchar(20) NULL,
+				titoli_added int NOT NULL DEFAULT 0,
+				titoli_updated int NOT NULL DEFAULT 0,
+				eventi_added int NOT NULL DEFAULT 0,
+				eventi_updated int NOT NULL DEFAULT 0,
+				error_message text NULL,
+				payload_hash varchar(64) NULL,
+				PRIMARY KEY  (id),
+				KEY started_at (started_at),
+				KEY status (status)
+			) {$table_options};",
+		);
+	}
+
+	/**
+	 * Seed defaults only when no event types exist.
+	 *
+	 * @throws RuntimeException When a default cannot be inserted.
+	 */
+	private function seed_event_types(): void {
+		$table = $this->db->prefix . 'cinebot_tipologie_eventi';
+		// The table identifier is composed only from the trusted WordPress prefix and a fixed suffix.
+		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+		if ( 0 !== (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" ) ) {
+			return;
+		}
+
+		$now = current_time( 'mysql', true );
+		foreach ( EventTypeDefaults::all() as $event_type ) {
+			// wpdb::insert prepares every dynamic value using the supplied formats.
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
+			$inserted = $this->db->insert(
+				$table,
+				array(
+					'codice'      => $event_type['codice'],
+					'descrizione' => $event_type['descrizione'],
+					'predefinito' => 1,
+					'attivo'      => 1,
+					'created_at'  => $now,
+					'updated_at'  => $now,
+				),
+				array( '%s', '%s', '%d', '%d', '%s', '%s' )
+			);
+
+			if ( false === $inserted ) {
+				throw new RuntimeException( esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ) );
+			}
+		}
+	}
+}
diff --git a/includes/Plugin.php b/includes/Plugin.php
index e23540f..42fe036 100644
--- a/includes/Plugin.php
+++ b/includes/Plugin.php
@@ -1,19 +1,21 @@
 <?php
 /**
  * Main plugin coordinator.
  *
  * @package CinebotWp
  */
 
 namespace CinebotWp;
 
+use CinebotWp\Database\SchemaInstaller;
+
 final class Plugin {
 	/**
 	 * Singleton instance.
 	 *
 	 * @var self|null
 	 */
 	private static $instance;
 
 	/**
 	 * Whether the plugin has booted.
@@ -26,20 +28,36 @@ final class Plugin {
 	 * Return the singleton plugin instance.
 	 */
 	public static function instance(): self {
 		if ( null === self::$instance ) {
 			self::$instance = new self();
 		}
 
 		return self::$instance;
 	}
 
+	/**
+	 * Install the plugin database schema on activation.
+	 */
+	public static function activate(): void {
+		global $wpdb;
+
+		( new SchemaInstaller( $wpdb ) )->install();
+	}
+
+	/**
+	 * Stop scheduled synchronization on deactivation.
+	 */
+	public static function deactivate(): void {
+		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+	}
+
 	/**
 	 * Boot the plugin once.
 	 */
 	public function boot(): void {
 		if ( $this->booted ) {
 			return;
 		}
 
 		$this->booted = true;
 		do_action( 'cinebot_wp_booted' );
diff --git a/phpstan.neon.dist b/phpstan.neon.dist
index 79e19a6..bf2eb82 100644
--- a/phpstan.neon.dist
+++ b/phpstan.neon.dist
@@ -1,12 +1,13 @@
 includes:
     - vendor/szepeviktor/phpstan-wordpress/extension.neon
 
 parameters:
     level: 6
     phpVersion: 70400
     paths:
         - cinebot-wp.php
+        - uninstall.php
         - includes
     excludePaths:
         - vendor
         - dist
diff --git a/tests/Integration/SchemaInstallerTest.php b/tests/Integration/SchemaInstallerTest.php
new file mode 100644
index 0000000..1974d86
--- /dev/null
+++ b/tests/Integration/SchemaInstallerTest.php
@@ -0,0 +1,276 @@
+<?php
+/**
+ * Database schema integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Integration assertions query trusted, fixed schema identifiers directly.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\EventTypeDefaults;
+use CinebotWp\Database\SchemaInstaller;
+use CinebotWp\Plugin;
+use RuntimeException;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies schema installation and plugin lifecycle behavior.
+ */
+final class SchemaInstallerTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var string[] */
+	private const TABLE_SUFFIXES = array(
+		'titoli',
+		'eventi',
+		'settori',
+		'prezzi',
+		'locali',
+		'tipologie_eventi',
+		'sync_log',
+	);
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
+	 * Remove plugin data before each test.
+	 */
+	public function set_up(): void {
+		parent::set_up();
+
+		$this->drop_plugin_tables();
+		delete_option( 'cinebot_wp_db_version' );
+		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+	}
+
+	/**
+	 * Remove plugin data after each test.
+	 */
+	public function tear_down(): void {
+		$this->drop_plugin_tables();
+		delete_option( 'cinebot_wp_db_version' );
+		wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+
+		parent::tear_down();
+	}
+
+	/**
+	 * Verifies all approved tables, fields, indexes, and defaults.
+	 */
+	public function test_install_creates_approved_schema_and_defaults(): void {
+		( new SchemaInstaller( self::$db ) )->install();
+		$expected_tables = array_map(
+			static function ( string $suffix ): string {
+				return self::$db->prefix . 'cinebot_' . $suffix;
+			},
+			self::TABLE_SUFFIXES
+		);
+		$actual_tables   = self::$db->get_col(
+			self::$db->prepare(
+				'SHOW TABLES LIKE %s',
+				self::$db->esc_like( self::$db->prefix . 'cinebot_' ) . '%'
+			)
+		);
+		sort( $expected_tables );
+		sort( $actual_tables );
+		self::assertSame( $expected_tables, $actual_tables );
+
+		foreach ( self::TABLE_SUFFIXES as $suffix ) {
+			$table = self::$db->prefix . 'cinebot_' . $suffix;
+			self::assertSame(
+				$table,
+				self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', self::$db->esc_like( $table ) ) )
+			);
+			self::assertSame( 'InnoDB', $this->table_engine( $table ) );
+		}
+
+		self::assertSame( '1.0.0', get_option( 'cinebot_wp_db_version' ) );
+		self::assertArrayNotHasKey( 'cinebot_wp_db_version', wp_load_alloptions() );
+		self::assertCount( 62, EventTypeDefaults::all() );
+		self::assertCount( 62, array_unique( array_column( EventTypeDefaults::all(), 'codice' ) ) );
+		self::assertSame( '01', EventTypeDefaults::all()[0]['codice'] );
+		self::assertSame(
+			62,
+			(int) self::$db->get_var( 'SELECT COUNT(*) FROM ' . self::$db->prefix . 'cinebot_tipologie_eventi' )
+		);
+
+		$this->assert_nullable_column( 'titoli', 'idtitolo' );
+		$this->assert_nullable_column( 'titoli', 'frontend_id' );
+		$this->assert_nullable_column( 'eventi', 'idevento' );
+		$this->assert_nullable_column( 'settori', 'idsettore' );
+		$this->assert_nullable_column( 'prezzi', 'idprezzo' );
+		$this->assert_nullable_column( 'locali', 'locale_id_remoto' );
+
+		$this->assert_index( 'titoli', 'idtitolo', array( 'idtitolo' ), true );
+		$this->assert_index( 'titoli', 'frontend_sync', array( 'frontend_id', 'sync_active', 'last_seen_sync' ) );
+		$this->assert_index( 'eventi', 'idevento', array( 'idevento' ), true );
+		$this->assert_index( 'eventi', 'inizio', array( 'inizio' ) );
+		$this->assert_index( 'eventi', 'titolo_sync', array( 'titolo_id', 'sync_active', 'last_seen_sync' ) );
+		$this->assert_index( 'settori', 'remote_evento', array( 'idsettore', 'evento_id' ), true );
+		$this->assert_index( 'settori', 'evento_sync', array( 'evento_id', 'sync_active', 'last_seen_sync' ) );
+		$this->assert_index( 'prezzi', 'remote_settore', array( 'idprezzo', 'settore_id' ), true );
+		$this->assert_index( 'prezzi', 'settore_sync', array( 'settore_id', 'sync_active', 'last_seen_sync' ) );
+		$this->assert_index( 'locali', 'locale_id_remoto', array( 'locale_id_remoto' ), true );
+
+		foreach ( array( 'titoli', 'eventi', 'settori', 'prezzi' ) as $suffix ) {
+			$this->assert_column_exists( $suffix, 'sync_active' );
+			$this->assert_nullable_column( $suffix, 'last_seen_sync' );
+		}
+	}
+
+	/**
+	 * Verifies repeated activation preserves existing event-type choices.
+	 */
+	public function test_install_is_idempotent_and_preserves_disabled_defaults(): void {
+		$installer = new SchemaInstaller( self::$db );
+		$installer->install();
+
+		$table = self::$db->prefix . 'cinebot_tipologie_eventi';
+		self::$db->update( $table, array( 'attivo' => 0 ), array( 'codice' => '01' ), array( '%d' ), array( '%s' ) );
+		$installer->install();
+
+		self::assertSame( 62, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
+		self::assertSame(
+			'0',
+			self::$db->get_var( self::$db->prepare( "SELECT attivo FROM {$table} WHERE codice = %s", '01' ) )
+		);
+	}
+
+	/**
+	 * Verifies lifecycle hooks use only the plugin coordinator callbacks.
+	 */
+	public function test_entry_point_registers_plugin_lifecycle_callbacks(): void {
+		$activation_hook   = 'activate_' . plugin_basename( CINEBOT_WP_FILE );
+		$deactivation_hook = 'deactivate_' . plugin_basename( CINEBOT_WP_FILE );
+
+		self::assertSame( 10, has_action( $activation_hook, array( Plugin::class, 'activate' ) ) );
+		self::assertSame( 10, has_action( $deactivation_hook, array( Plugin::class, 'deactivate' ) ) );
+	}
+
+	/**
+	 * Verifies deactivation clears cron without deleting persisted data.
+	 */
+	public function test_deactivation_retains_schema_and_data(): void {
+		Plugin::activate();
+		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_sync_event' );
+
+		Plugin::deactivate();
+
+		self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );
+		self::assertSame( '1.0.0', get_option( 'cinebot_wp_db_version' ) );
+		self::assertSame(
+			self::$db->prefix . 'cinebot_titoli',
+			self::$db->get_var(
+				self::$db->prepare(
+					'SHOW TABLES LIKE %s',
+					self::$db->esc_like( self::$db->prefix . 'cinebot_titoli' )
+				)
+			)
+		);
+	}
+
+	/**
+	 * Verifies unsupported storage engines abort before schema statements.
+	 */
+	public function test_install_fails_before_creation_without_innodb(): void {
+		$db = new class() extends wpdb {
+			/** @var string[] */
+			public $queries = array();
+
+			public function __construct() {
+				$this->prefix = 'wp_';
+			}
+
+			public function get_results( $query = null, $output = OBJECT ) {
+				$this->queries[] = $query;
+
+				return array( array( 'Engine' => 'MyISAM', 'Support' => 'DEFAULT' ) );
+			}
+
+			public function query( $query ) {
+				$this->queries[] = $query;
+
+				return false;
+			}
+		};
+
+		$installer = new SchemaInstaller( $db );
+		self::assertFalse( $installer->supportsTransactions() );
+
+		try {
+			$installer->install();
+			self::fail( 'Installation should fail when InnoDB is unavailable.' );
+		} catch ( RuntimeException $exception ) {
+			self::assertStringContainsString( 'InnoDB', $exception->getMessage() );
+		}
+
+		self::assertCount( 2, $db->queries );
+		self::assertSame( array( 'SHOW ENGINES', 'SHOW ENGINES' ), $db->queries );
+	}
+
+	/**
+	 * Drop all fixed plugin tables.
+	 */
+	private function drop_plugin_tables(): void {
+		foreach ( array_reverse( self::TABLE_SUFFIXES ) as $suffix ) {
+			self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_' . $suffix );
+		}
+	}
+
+	/**
+	 * Return a table storage engine.
+	 */
+	private function table_engine( string $table ): string {
+		$status = self::$db->get_row( self::$db->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
+		self::assertIsObject( $status );
+
+		return $status->Engine;
+	}
+
+	/**
+	 * Assert that a column exists.
+	 */
+	private function assert_column_exists( string $suffix, string $column ): void {
+		$table = self::$db->prefix . 'cinebot_' . $suffix;
+		self::assertSame( $column, self::$db->get_var( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) ) );
+	}
+
+	/**
+	 * Assert that a column permits NULL.
+	 */
+	private function assert_nullable_column( string $suffix, string $column ): void {
+		$table  = self::$db->prefix . 'cinebot_' . $suffix;
+		$result = self::$db->get_row( self::$db->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
+		self::assertIsObject( $result );
+		self::assertSame( 'YES', $result->Null );
+	}
+
+	/**
+	 * Assert an index's ordered columns and uniqueness.
+	 *
+	 * @param string[] $columns Expected ordered columns.
+	 */
+	private function assert_index( string $suffix, string $name, array $columns, bool $unique = false ): void {
+		$table = self::$db->prefix . 'cinebot_' . $suffix;
+		$rows  = self::$db->get_results( self::$db->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $name ) );
+
+		self::assertCount( count( $columns ), $rows );
+		self::assertSame( $columns, array_column( $rows, 'Column_name' ) );
+		self::assertSame( $unique ? '0' : '1', (string) $rows[0]->Non_unique );
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
diff --git a/uninstall.php b/uninstall.php
new file mode 100644
index 0000000..a9062ea
--- /dev/null
+++ b/uninstall.php
@@ -0,0 +1,47 @@
+<?php
+/**
+ * Remove all single-site Cinebot WP data.
+ *
+ * @package CinebotWp
+ */
+
+if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
+	exit;
+}
+
+global $wpdb;
+
+// Uninstall must issue schema changes and delete matching options directly.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+$table_suffixes = array(
+	'titoli',
+	'eventi',
+	'settori',
+	'prezzi',
+	'locali',
+	'tipologie_eventi',
+	'sync_log',
+);
+
+foreach ( $table_suffixes as $table_suffix ) {
+	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'cinebot_' . $table_suffix );
+}
+
+delete_option( 'cinebot_wp_settings' );
+delete_option( 'cinebot_wp_db_version' );
+delete_option( 'cinebot_wp_encryption_salt' );
+delete_option( 'cinebot_wp_sync_lock' );
+wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+
+$value_pattern   = $wpdb->esc_like( '_transient_cinebot_prog_' ) . '%';
+$timeout_pattern = $wpdb->esc_like( '_transient_timeout_cinebot_prog_' ) . '%';
+$wpdb->query(
+	$wpdb->prepare(
+		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
+		$value_pattern,
+		$timeout_pattern
+	)
+);
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
```

## Complete Task 2 Fix Diff

Command: `git diff --unified=10 f324038 3007fcb -- includes/Database/SchemaInstaller.php tests/Integration/SchemaInstallerTest.php tests/Integration/UninstallTest.php`

```diff
diff --git a/includes/Database/SchemaInstaller.php b/includes/Database/SchemaInstaller.php
index d794623..7727be1 100644
--- a/includes/Database/SchemaInstaller.php
+++ b/includes/Database/SchemaInstaller.php
@@ -226,33 +226,69 @@ final class SchemaInstaller {
 	 * @throws RuntimeException When a default cannot be inserted.
 	 */
 	private function seed_event_types(): void {
 		$table = $this->db->prefix . 'cinebot_tipologie_eventi';
 		// The table identifier is composed only from the trusted WordPress prefix and a fixed suffix.
 		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 		if ( 0 !== (int) $this->db->get_var( "SELECT COUNT(*) FROM {$table}" ) ) {
 			return;
 		}
 
+		// Catalog seeding must remain retryable after any partial database failure.
+		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
+		try {
+			if ( false === $this->db->query( 'START TRANSACTION' ) ) {
+				throw new RuntimeException( 'seed transaction failed' );
+			}
+
+			$this->insert_event_type_defaults( $table );
+			if ( false === $this->db->query( 'COMMIT' ) ) {
+				throw new RuntimeException( 'seed commit failed' );
+			}
+		} catch ( \Throwable $exception ) {
+			$this->rollback_event_type_seed();
+			throw new RuntimeException( esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ) );
+		}
+		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
+	}
+
+	/**
+	 * Insert every default within the caller's transaction.
+	 *
+	 * @throws RuntimeException When an insert fails.
+	 */
+	private function insert_event_type_defaults( string $table ): void {
 		$now = current_time( 'mysql', true );
 		foreach ( EventTypeDefaults::all() as $event_type ) {
 			// wpdb::insert prepares every dynamic value using the supplied formats.
 			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
 			$inserted = $this->db->insert(
 				$table,
 				array(
 					'codice'      => $event_type['codice'],
 					'descrizione' => $event_type['descrizione'],
 					'predefinito' => 1,
 					'attivo'      => 1,
 					'created_at'  => $now,
 					'updated_at'  => $now,
 				),
 				array( '%s', '%s', '%d', '%d', '%s', '%s' )
 			);
 
 			if ( false === $inserted ) {
-				throw new RuntimeException( esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ) );
+				throw new RuntimeException( 'seed insert failed' );
 			}
 		}
 	}
+
+	/**
+	 * Attempt rollback without replacing the safe installation exception.
+	 */
+	private function rollback_event_type_seed(): void {
+		try {
+			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
+			$this->db->query( 'ROLLBACK' );
+		} catch ( \Throwable $exception ) {
+			return;
+		}
+	}
 }
diff --git a/tests/Integration/SchemaInstallerTest.php b/tests/Integration/SchemaInstallerTest.php
index 1974d86..f3d5b24 100644
--- a/tests/Integration/SchemaInstallerTest.php
+++ b/tests/Integration/SchemaInstallerTest.php
@@ -92,23 +92,20 @@ final class SchemaInstallerTest extends WP_UnitTestCase {
 			$table = self::$db->prefix . 'cinebot_' . $suffix;
 			self::assertSame(
 				$table,
 				self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', self::$db->esc_like( $table ) ) )
 			);
 			self::assertSame( 'InnoDB', $this->table_engine( $table ) );
 		}
 
 		self::assertSame( '1.0.0', get_option( 'cinebot_wp_db_version' ) );
 		self::assertArrayNotHasKey( 'cinebot_wp_db_version', wp_load_alloptions() );
-		self::assertCount( 62, EventTypeDefaults::all() );
-		self::assertCount( 62, array_unique( array_column( EventTypeDefaults::all(), 'codice' ) ) );
-		self::assertSame( '01', EventTypeDefaults::all()[0]['codice'] );
 		self::assertSame(
 			62,
 			(int) self::$db->get_var( 'SELECT COUNT(*) FROM ' . self::$db->prefix . 'cinebot_tipologie_eventi' )
 		);
 
 		$this->assert_nullable_column( 'titoli', 'idtitolo' );
 		$this->assert_nullable_column( 'titoli', 'frontend_id' );
 		$this->assert_nullable_column( 'eventi', 'idevento' );
 		$this->assert_nullable_column( 'settori', 'idsettore' );
 		$this->assert_nullable_column( 'prezzi', 'idprezzo' );
@@ -124,38 +121,116 @@ final class SchemaInstallerTest extends WP_UnitTestCase {
 		$this->assert_index( 'prezzi', 'remote_settore', array( 'idprezzo', 'settore_id' ), true );
 		$this->assert_index( 'prezzi', 'settore_sync', array( 'settore_id', 'sync_active', 'last_seen_sync' ) );
 		$this->assert_index( 'locali', 'locale_id_remoto', array( 'locale_id_remoto' ), true );
 
 		foreach ( array( 'titoli', 'eventi', 'settori', 'prezzi' ) as $suffix ) {
 			$this->assert_column_exists( $suffix, 'sync_active' );
 			$this->assert_nullable_column( $suffix, 'last_seen_sync' );
 		}
 	}
 
+	/**
+	 * Verifies every approved default code and description through a canonical fingerprint.
+	 */
+	public function test_defaults_match_approved_catalog(): void {
+		$defaults = EventTypeDefaults::all();
+
+		self::assertCount( 62, $defaults );
+		self::assertCount( 62, array_unique( array_column( $defaults, 'codice' ) ) );
+		self::assertSame(
+			'26e7c32546f10b24f2260373b2d65c06dbf94d44d84c66963c69ce7d0d4ef380',
+			$this->event_type_fingerprint( $defaults )
+		);
+	}
+
 	/**
 	 * Verifies repeated activation preserves existing event-type choices.
 	 */
 	public function test_install_is_idempotent_and_preserves_disabled_defaults(): void {
 		$installer = new SchemaInstaller( self::$db );
 		$installer->install();
 
 		$table = self::$db->prefix . 'cinebot_tipologie_eventi';
 		self::$db->update( $table, array( 'attivo' => 0 ), array( 'codice' => '01' ), array( '%d' ), array( '%s' ) );
 		$installer->install();
 
 		self::assertSame( 62, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
 		self::assertSame(
 			'0',
 			self::$db->get_var( self::$db->prepare( "SELECT attivo FROM {$table} WHERE codice = %s", '01' ) )
 		);
 	}
 
+	/**
+	 * Verifies a failed partial seed rolls back and can be retried completely.
+	 */
+	public function test_mid_seed_failure_rolls_back_and_retry_seeds_all_defaults(): void {
+		$db = new class( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST ) extends wpdb {
+			/** @var bool */
+			public $failed = false;
+
+			/** @var string[] */
+			public $transaction_queries = array();
+
+			/** @var int */
+			private $insert_count = 0;
+
+			public function insert( $table, $data, $format = null ) {
+				++$this->insert_count;
+				if ( ! $this->failed && 5 === $this->insert_count ) {
+					$this->failed = true;
+
+					return false;
+				}
+
+				return parent::insert( $table, $data, $format );
+			}
+
+			public function query( $query ) {
+				$normalized = strtoupper( trim( $query ) );
+				if ( in_array( $normalized, array( 'START TRANSACTION', 'COMMIT', 'ROLLBACK' ), true ) ) {
+					$this->transaction_queries[] = $normalized;
+				}
+
+				return parent::query( $query );
+			}
+		};
+		$db->set_prefix( self::$db->prefix );
+		$installer = new SchemaInstaller( $db );
+		$table     = self::$db->prefix . 'cinebot_tipologie_eventi';
+
+		try {
+			try {
+				$installer->install();
+				self::fail( 'A forced mid-seed insert failure should abort installation.' );
+			} catch ( RuntimeException $exception ) {
+				self::assertSame(
+					esc_html__( 'Cinebot WP could not store its default event types.', 'cinebot-wp' ),
+					$exception->getMessage()
+				);
+			}
+
+			self::assertSame( 0, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
+			self::assertSame( array( 'START TRANSACTION', 'ROLLBACK' ), $db->transaction_queries );
+
+			$installer->install();
+
+			self::assertSame( 62, (int) self::$db->get_var( "SELECT COUNT(*) FROM {$table}" ) );
+			self::assertSame(
+				array( 'START TRANSACTION', 'ROLLBACK', 'START TRANSACTION', 'COMMIT' ),
+				$db->transaction_queries
+			);
+		} finally {
+			$db->close();
+		}
+	}
+
 	/**
 	 * Verifies lifecycle hooks use only the plugin coordinator callbacks.
 	 */
 	public function test_entry_point_registers_plugin_lifecycle_callbacks(): void {
 		$activation_hook   = 'activate_' . plugin_basename( CINEBOT_WP_FILE );
 		$deactivation_hook = 'deactivate_' . plugin_basename( CINEBOT_WP_FILE );
 
 		self::assertSame( 10, has_action( $activation_hook, array( Plugin::class, 'activate' ) ) );
 		self::assertSame( 10, has_action( $deactivation_hook, array( Plugin::class, 'deactivate' ) ) );
 	}
@@ -223,20 +298,36 @@ final class SchemaInstallerTest extends WP_UnitTestCase {
 
 	/**
 	 * Drop all fixed plugin tables.
 	 */
 	private function drop_plugin_tables(): void {
 		foreach ( array_reverse( self::TABLE_SUFFIXES ) as $suffix ) {
 			self::$db->query( 'DROP TABLE IF EXISTS ' . self::$db->prefix . 'cinebot_' . $suffix );
 		}
 	}
 
+	/**
+	 * Hash UTF-8 `code<TAB>description` rows joined by LF with no trailing LF.
+	 *
+	 * @param array<int,array{codice:string,descrizione:string}> $defaults Event type catalog.
+	 */
+	private function event_type_fingerprint( array $defaults ): string {
+		$rows = array_map(
+			static function ( array $event_type ): string {
+				return $event_type['codice'] . "\t" . $event_type['descrizione'];
+			},
+			$defaults
+		);
+
+		return hash( 'sha256', implode( "\n", $rows ) );
+	}
+
 	/**
 	 * Return a table storage engine.
 	 */
 	private function table_engine( string $table ): string {
 		$status = self::$db->get_row( self::$db->prepare( 'SHOW TABLE STATUS LIKE %s', $table ) );
 		self::assertIsObject( $status );
 
 		return $status->Engine;
 	}
 
diff --git a/tests/Integration/UninstallTest.php b/tests/Integration/UninstallTest.php
new file mode 100644
index 0000000..09ebb68
--- /dev/null
+++ b/tests/Integration/UninstallTest.php
@@ -0,0 +1,108 @@
+<?php
+/**
+ * Plugin uninstall integration tests.
+ *
+ * @package CinebotWp
+ */
+
+namespace CinebotWp\Tests\Integration;
+
+// Integration assertions query trusted, fixed schema identifiers directly.
+// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
+
+use CinebotWp\Database\SchemaInstaller;
+use WP_UnitTestCase;
+use wpdb;
+
+/**
+ * Verifies the exact destructive boundary of single-site uninstall.
+ */
+final class UninstallTest extends WP_UnitTestCase {
+	/** @var wpdb */
+	private static $db;
+
+	/** @var string[] */
+	private const TABLE_SUFFIXES = array(
+		'titoli',
+		'eventi',
+		'settori',
+		'prezzi',
+		'locali',
+		'tipologie_eventi',
+		'sync_log',
+	);
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
+	 * Verifies uninstall removes approved data and preserves unrelated data.
+	 */
+	public function test_uninstall_removes_only_approved_single_site_data(): void {
+		$installer       = new SchemaInstaller( self::$db );
+		$unrelated_table = self::$db->prefix . 'cinebot_unrelated';
+
+		try {
+			$installer->install();
+			self::$db->query( "CREATE TABLE {$unrelated_table} (id bigint(20) unsigned NOT NULL) ENGINE=InnoDB" );
+
+			update_option( 'cinebot_wp_settings', array( 'remove' => true ) );
+			update_option( 'cinebot_wp_db_version', 'remove' );
+			update_option( 'cinebot_wp_encryption_salt', 'remove' );
+			update_option( 'cinebot_wp_sync_lock', 'remove' );
+			update_option( 'cinebot_wp_unrelated', 'preserve' );
+			set_transient( 'cinebot_prog_contract', 'remove', HOUR_IN_SECONDS );
+			set_transient( 'cinebot_unrelated', 'preserve', HOUR_IN_SECONDS );
+			wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+			wp_clear_scheduled_hook( 'cinebot_wp_unrelated_event' );
+			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_sync_event' );
+			wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'cinebot_wp_unrelated_event' );
+
+			if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
+				define( 'WP_UNINSTALL_PLUGIN', 'cinebot-wp/cinebot-wp.php' );
+			}
+			require CINEBOT_WP_PATH . 'uninstall.php';
+
+			foreach ( self::TABLE_SUFFIXES as $suffix ) {
+				$table = self::$db->prefix . 'cinebot_' . $suffix;
+				self::assertNull( self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', $table ) ) );
+			}
+
+			self::assertSame( $unrelated_table, self::$db->get_var( self::$db->prepare( 'SHOW TABLES LIKE %s', $unrelated_table ) ) );
+			self::assertFalse( get_option( 'cinebot_wp_settings' ) );
+			self::assertFalse( get_option( 'cinebot_wp_db_version' ) );
+			self::assertFalse( get_option( 'cinebot_wp_encryption_salt' ) );
+			self::assertFalse( get_option( 'cinebot_wp_sync_lock' ) );
+			self::assertSame( 'preserve', get_option( 'cinebot_wp_unrelated' ) );
+			self::assertFalse( get_transient( 'cinebot_prog_contract' ) );
+			self::assertFalse( get_option( '_transient_cinebot_prog_contract' ) );
+			self::assertFalse( get_option( '_transient_timeout_cinebot_prog_contract' ) );
+			self::assertSame( 'preserve', get_transient( 'cinebot_unrelated' ) );
+			self::assertSame( 'preserve', get_option( '_transient_cinebot_unrelated' ) );
+			self::assertNotFalse( get_option( '_transient_timeout_cinebot_unrelated' ) );
+			self::assertFalse( wp_next_scheduled( 'cinebot_wp_sync_event' ) );
+			self::assertNotFalse( wp_next_scheduled( 'cinebot_wp_unrelated_event' ) );
+		} finally {
+			delete_option( 'cinebot_wp_settings' );
+			delete_option( 'cinebot_wp_db_version' );
+			delete_option( 'cinebot_wp_encryption_salt' );
+			delete_option( 'cinebot_wp_sync_lock' );
+			delete_option( 'cinebot_wp_unrelated' );
+			delete_transient( 'cinebot_prog_contract' );
+			delete_transient( 'cinebot_unrelated' );
+			wp_clear_scheduled_hook( 'cinebot_wp_sync_event' );
+			wp_clear_scheduled_hook( 'cinebot_wp_unrelated_event' );
+			self::$db->query( "DROP TABLE IF EXISTS {$unrelated_table}" );
+			$installer->install();
+		}
+	}
+}
+
+// phpcs:enable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
```

The updated report and approved Task 2 plan changes in `3007fcb` are summarized above and represented in the full stat; they are omitted from the implementation diff.

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
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 2 implementation commit. No Task 2 implementation file is currently modified or untracked.
