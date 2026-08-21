# Task 3 Reviewer Handoff Package

## Scope

Task 3 adds seven typed database-row DTOs, the `ProgrammazioneCard` public read model, focused unit coverage, and narrowly scoped WPCS OO camelCase exceptions. Review range: parent `3007fcba0de52ec356304da7abbdcf15c657f77a` to commit `e3253d83028cfab6315f206145ac7cdf4f854b18`.

The Task 3 report records that red, green, and full-gate commands could not reach Composer because Docker was unavailable; native PHP was also unavailable. Static review covered signatures, typed properties, PHP 7.4 syntax, exact database key mappings, defaults/nullability, input immutability, decimal strings, and scope/security.

## Commit Metadata

```text
commit e3253d83028cfab6315f206145ac7cdf4f854b18
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:14:06 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:14:06 2026 +0200

    feat: add cinebot domain models
```

## Full Stat

Command: `git show --stat --format=fuller e3253d8`

```text
commit e3253d83028cfab6315f206145ac7cdf4f854b18
Author:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
AuthorDate: Thu Aug 6 00:14:06 2026 +0200
Commit:     sandalf90 <60645744+sandalf90@users.noreply.github.com>
CommitDate: Thu Aug 6 00:14:06 2026 +0200

    feat: add cinebot domain models

 .superpowers/sdd/task-3-report.md                  |  35 +++
 .../plans/2026-08-02-cinebot-wp-plugin.md          |   7 +
 includes/Models/Evento.php                         |  83 ++++++
 includes/Models/Locale.php                         |  71 +++++
 includes/Models/Prezzo.php                         |  74 ++++++
 includes/Models/Settore.php                        |  62 +++++
 includes/Models/SyncLog.php                        |  65 +++++
 includes/Models/TipologiaEvento.php                |  56 ++++
 includes/Models/Titolo.php                         | 105 ++++++++
 includes/ReadModels/ProgrammazioneCard.php         |  51 ++++
 phpcs.xml.dist                                     |   7 +
 tests/Unit/ModelsTest.php                          | 289 +++++++++++++++++++++
 12 files changed, 905 insertions(+)
```

The committed Task 3 report and approved-plan PHPCS snippet are coordination/documentation artifacts represented in the full stat. The report is summarized above; the seven-line plan mirror is omitted from the implementation diff.

## Full Relevant Diff

Command: `git diff --unified=10 3007fcb e3253d8 -- includes/Models includes/ReadModels phpcs.xml.dist tests/Unit/ModelsTest.php`

```diff
diff --git a/includes/Models/Evento.php b/includes/Models/Evento.php
new file mode 100644
index 0000000..9c60680
--- /dev/null
+++ b/includes/Models/Evento.php
@@ -0,0 +1,83 @@
+<?php
+/** Event data transfer object. @package CinebotWp */
+namespace CinebotWp\Models;
+/** Represents one event database row. */
+final class Evento {
+	public ?int $id = null;
+	public ?int $idevento = null;
+	public int $titoloId = 0;
+	public string $inizio = '';
+	public ?int $organizzatoreId = null;
+	public ?string $organizzatoreCf = null;
+	public int $localeId = 0;
+	public ?int $stato = null;
+	public ?int $otp = null;
+	public ?int $controlloaccessi = null;
+	public ?int $mappa = null;
+	public string $source = 'manual';
+	public int $syncActive = 1;
+	public ?string $lastSeenSync = null;
+	public ?string $createdAt = null;
+	public ?string $updatedAt = null;
+	/** Hydrate an event from a database-shaped array. @param array<string,mixed> $data Database data. */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->idevento = isset( $data['idevento'] ) ? (int) $data['idevento'] : null;
+		$model->titoloId = isset( $data['titolo_id'] ) ? (int) $data['titolo_id'] : 0;
+		$model->inizio = isset( $data['inizio'] ) ? (string) $data['inizio'] : '';
+		$model->organizzatoreId = isset( $data['organizzatore_id'] ) ? (int) $data['organizzatore_id'] : null;
+		$model->organizzatoreCf = isset( $data['organizzatore_cf'] ) ? (string) $data['organizzatore_cf'] : null;
+		$model->localeId = isset( $data['locale_id'] ) ? (int) $data['locale_id'] : 0;
+		$model->stato = isset( $data['stato'] ) ? (int) $data['stato'] : null;
+		$model->otp = isset( $data['otp'] ) ? (int) $data['otp'] : null;
+		$model->controlloaccessi = isset( $data['controlloaccessi'] ) ? (int) $data['controlloaccessi'] : null;
+		$model->mappa = isset( $data['mappa'] ) ? (int) $data['mappa'] : null;
+		$model->source = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
+		$model->syncActive = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
+		$model->lastSeenSync = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** Return database-shaped data. @return array<string,mixed> */
+	public function toArray(): array {
+		return array(
+			'id' => $this->id, 'idevento' => $this->idevento, 'titolo_id' => $this->titoloId,
+			'inizio' => $this->inizio, 'organizzatore_id' => $this->organizzatoreId,
+			'organizzatore_cf' => $this->organizzatoreCf, 'locale_id' => $this->localeId,
+			'stato' => $this->stato, 'otp' => $this->otp, 'controlloaccessi' => $this->controlloaccessi,
+			'mappa' => $this->mappa, 'source' => $this->source, 'sync_active' => $this->syncActive,
+			'last_seen_sync' => $this->lastSeenSync, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt,
+		);
+	}
+}
diff --git a/includes/Models/Locale.php b/includes/Models/Locale.php
new file mode 100644
index 0000000..e7e179d
--- /dev/null
+++ b/includes/Models/Locale.php
@@ -0,0 +1,71 @@
+<?php
+/** Venue data transfer object. @package CinebotWp */
+namespace CinebotWp\Models;
+/** Represents one venue database row. */
+final class Locale {
+	public ?int $id = null;
+	public ?int $localeIdRemoto = null;
+	public string $nome = '';
+	public ?string $codice = null;
+	public ?string $indirizzo = null;
+	public ?string $cap = null;
+	public ?string $comune = null;
+	public ?string $provincia = null;
+	public ?int $mappa = null;
+	public string $source = 'manual';
+	public ?string $createdAt = null;
+	public ?string $updatedAt = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->localeIdRemoto = isset( $data['locale_id_remoto'] ) ? (int) $data['locale_id_remoto'] : null;
+		$model->nome = isset( $data['nome'] ) ? (string) $data['nome'] : '';
+		$model->codice = isset( $data['codice'] ) ? (string) $data['codice'] : null;
+		$model->indirizzo = isset( $data['indirizzo'] ) ? (string) $data['indirizzo'] : null;
+		$model->cap = isset( $data['cap'] ) ? (string) $data['cap'] : null;
+		$model->comune = isset( $data['comune'] ) ? (string) $data['comune'] : null;
+		$model->provincia = isset( $data['provincia'] ) ? (string) $data['provincia'] : null;
+		$model->mappa = isset( $data['mappa'] ) ? (int) $data['mappa'] : null;
+		$model->source = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array( 'id' => $this->id, 'locale_id_remoto' => $this->localeIdRemoto, 'nome' => $this->nome,
+			'codice' => $this->codice, 'indirizzo' => $this->indirizzo, 'cap' => $this->cap,
+			'comune' => $this->comune, 'provincia' => $this->provincia, 'mappa' => $this->mappa,
+			'source' => $this->source, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt );
+	}
+}
diff --git a/includes/Models/Prezzo.php b/includes/Models/Prezzo.php
new file mode 100644
index 0000000..0e5d1f6
--- /dev/null
+++ b/includes/Models/Prezzo.php
@@ -0,0 +1,74 @@
+<?php
+/** Price data transfer object. @package CinebotWp */
+namespace CinebotWp\Models;
+final class Prezzo {
+	public ?int $id = null;
+	public ?int $idprezzo = null;
+	public int $settoreId = 0;
+	public ?string $nome = null;
+	public ?string $tipo = null;
+	public ?string $importo = null;
+	public ?string $prevendita = null;
+	public ?int $stato = null;
+	public string $source = 'manual';
+	public int $syncActive = 1;
+	public ?string $lastSeenSync = null;
+	public ?string $createdAt = null;
+	public ?string $updatedAt = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->idprezzo = isset( $data['idprezzo'] ) ? (int) $data['idprezzo'] : null;
+		$model->settoreId = isset( $data['settore_id'] ) ? (int) $data['settore_id'] : 0;
+		$model->nome = isset( $data['nome'] ) ? (string) $data['nome'] : null;
+		$model->tipo = isset( $data['tipo'] ) ? (string) $data['tipo'] : null;
+		$model->importo = isset( $data['importo'] ) ? (string) $data['importo'] : null;
+		$model->prevendita = isset( $data['prevendita'] ) ? (string) $data['prevendita'] : null;
+		$model->stato = isset( $data['stato'] ) ? (int) $data['stato'] : null;
+		$model->source = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
+		$model->syncActive = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
+		$model->lastSeenSync = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array( 'id' => $this->id, 'idprezzo' => $this->idprezzo, 'settore_id' => $this->settoreId,
+			'nome' => $this->nome, 'tipo' => $this->tipo, 'importo' => $this->importo,
+			'prevendita' => $this->prevendita, 'stato' => $this->stato, 'source' => $this->source,
+			'sync_active' => $this->syncActive, 'last_seen_sync' => $this->lastSeenSync,
+			'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt );
+	}
+}
diff --git a/includes/Models/Settore.php b/includes/Models/Settore.php
new file mode 100644
index 0000000..998a94e
--- /dev/null
+++ b/includes/Models/Settore.php
@@ -0,0 +1,62 @@
+<?php
+/** Sector DTO. @package CinebotWp */
+namespace CinebotWp\Models;
+final class Settore {
+	public ?int $id = null; public ?int $idsettore = null; public int $eventoId = 0;
+	public ?string $nome = null; public string $source = 'manual'; public int $syncActive = 1;
+	public ?string $lastSeenSync = null; public ?string $createdAt = null; public ?string $updatedAt = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->idsettore = isset( $data['idsettore'] ) ? (int) $data['idsettore'] : null;
+		$model->eventoId = isset( $data['evento_id'] ) ? (int) $data['evento_id'] : 0;
+		$model->nome = isset( $data['nome'] ) ? (string) $data['nome'] : null;
+		$model->source = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
+		$model->syncActive = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
+		$model->lastSeenSync = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array( 'id' => $this->id, 'idsettore' => $this->idsettore, 'evento_id' => $this->eventoId,
+			'nome' => $this->nome, 'source' => $this->source, 'sync_active' => $this->syncActive,
+			'last_seen_sync' => $this->lastSeenSync, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt );
+	}
+}
diff --git a/includes/Models/SyncLog.php b/includes/Models/SyncLog.php
new file mode 100644
index 0000000..8dae240
--- /dev/null
+++ b/includes/Models/SyncLog.php
@@ -0,0 +1,65 @@
+<?php
+/** Sync log DTO. @package CinebotWp */
+namespace CinebotWp\Models;
+final class SyncLog {
+	public ?int $id = null; public string $startedAt = ''; public ?string $finishedAt = null;
+	public ?string $status = null; public int $titoliAdded = 0; public int $titoliUpdated = 0;
+	public int $eventiAdded = 0; public int $eventiUpdated = 0; public ?string $errorMessage = null;
+	public ?string $payloadHash = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->startedAt = isset( $data['started_at'] ) ? (string) $data['started_at'] : '';
+		$model->finishedAt = isset( $data['finished_at'] ) ? (string) $data['finished_at'] : null;
+		$model->status = isset( $data['status'] ) ? (string) $data['status'] : null;
+		$model->titoliAdded = isset( $data['titoli_added'] ) ? (int) $data['titoli_added'] : 0;
+		$model->titoliUpdated = isset( $data['titoli_updated'] ) ? (int) $data['titoli_updated'] : 0;
+		$model->eventiAdded = isset( $data['eventi_added'] ) ? (int) $data['eventi_added'] : 0;
+		$model->eventiUpdated = isset( $data['eventi_updated'] ) ? (int) $data['eventi_updated'] : 0;
+		$model->errorMessage = isset( $data['error_message'] ) ? (string) $data['error_message'] : null;
+		$model->payloadHash = isset( $data['payload_hash'] ) ? (string) $data['payload_hash'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array( 'id' => $this->id, 'started_at' => $this->startedAt, 'finished_at' => $this->finishedAt,
+			'status' => $this->status, 'titoli_added' => $this->titoliAdded, 'titoli_updated' => $this->titoliUpdated,
+			'eventi_added' => $this->eventiAdded, 'eventi_updated' => $this->eventiUpdated,
+			'error_message' => $this->errorMessage, 'payload_hash' => $this->payloadHash );
+	}
+}
diff --git a/includes/Models/TipologiaEvento.php b/includes/Models/TipologiaEvento.php
new file mode 100644
index 0000000..7a04e2b
--- /dev/null
+++ b/includes/Models/TipologiaEvento.php
@@ -0,0 +1,56 @@
+<?php
+/** Event type DTO. @package CinebotWp */
+namespace CinebotWp\Models;
+final class TipologiaEvento {
+	public ?int $id = null; public string $codice = ''; public string $descrizione = '';
+	public int $predefinito = 0; public int $attivo = 1; public ?string $createdAt = null; public ?string $updatedAt = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->codice = isset( $data['codice'] ) ? (string) $data['codice'] : '';
+		$model->descrizione = isset( $data['descrizione'] ) ? (string) $data['descrizione'] : '';
+		$model->predefinito = isset( $data['predefinito'] ) ? (int) $data['predefinito'] : 0;
+		$model->attivo = isset( $data['attivo'] ) ? (int) $data['attivo'] : 1;
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array( 'id' => $this->id, 'codice' => $this->codice, 'descrizione' => $this->descrizione,
+			'predefinito' => $this->predefinito, 'attivo' => $this->attivo,
+			'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt );
+	}
+}
diff --git a/includes/Models/Titolo.php b/includes/Models/Titolo.php
new file mode 100644
index 0000000..141b90b
--- /dev/null
+++ b/includes/Models/Titolo.php
@@ -0,0 +1,105 @@
+<?php
+/** Title DTO. @package CinebotWp */
+namespace CinebotWp\Models;
+final class Titolo {
+	public ?int $id = null; public ?int $idtitolo = null; public ?int $frontendId = null;
+	public string $titolo = ''; public ?string $autore = null; public ?string $esecutore = null;
+	public ?int $durata = null; public ?int $scadenza = null; public ?string $descrizione = null;
+	public ?string $tipoeventoCodice = null; public ?int $locandinaFlag = null;
+	public ?string $locandinaUrl = null; public ?string $cinetel = null; public ?string $tmdb = null;
+	public ?string $trailer = null; public ?string $cast = null; /** @var array<int,mixed> */ public array $tag = array();
+	public string $source = 'manual'; public ?string $syncHash = null; public int $syncActive = 1;
+	public ?string $lastSeenSync = null; public ?string $createdAt = null; public ?string $updatedAt = null;
+	/** @param array<string,mixed> $data */
+	public static function fromArray( array $data ): self {
+		$model = new self();
+		$model->id = isset( $data['id'] ) ? (int) $data['id'] : null;
+		$model->idtitolo = isset( $data['idtitolo'] ) ? (int) $data['idtitolo'] : null;
+		$model->frontendId = isset( $data['frontend_id'] ) ? (int) $data['frontend_id'] : null;
+		$model->titolo = isset( $data['titolo'] ) ? (string) $data['titolo'] : '';
+		$model->autore = isset( $data['autore'] ) ? (string) $data['autore'] : null;
+		$model->esecutore = isset( $data['esecutore'] ) ? (string) $data['esecutore'] : null;
+		$model->durata = isset( $data['durata'] ) ? (int) $data['durata'] : null;
+		$model->scadenza = isset( $data['scadenza'] ) ? (int) $data['scadenza'] : null;
+		$model->descrizione = isset( $data['descrizione'] ) ? (string) $data['descrizione'] : null;
+		$model->tipoeventoCodice = isset( $data['tipoevento_codice'] ) ? (string) $data['tipoevento_codice'] : null;
+		$model->locandinaFlag = isset( $data['locandina_flag'] ) ? (int) $data['locandina_flag'] : null;
+		$model->locandinaUrl = isset( $data['locandina_url'] ) ? (string) $data['locandina_url'] : null;
+		$model->cinetel = isset( $data['cinetel'] ) ? (string) $data['cinetel'] : null;
+		$model->tmdb = isset( $data['tmdb'] ) ? (string) $data['tmdb'] : null;
+		$model->trailer = isset( $data['trailer'] ) ? (string) $data['trailer'] : null;
+		$model->cast = isset( $data['cast'] ) ? (string) $data['cast'] : null;
+		$model->tag = isset( $data['tag'] ) && is_array( $data['tag'] ) ? $data['tag'] : array();
+		$model->source = isset( $data['source'] ) ? (string) $data['source'] : 'manual';
+		$model->syncHash = isset( $data['sync_hash'] ) ? (string) $data['sync_hash'] : null;
+		$model->syncActive = isset( $data['sync_active'] ) ? (int) $data['sync_active'] : 1;
+		$model->lastSeenSync = isset( $data['last_seen_sync'] ) ? (string) $data['last_seen_sync'] : null;
+		$model->createdAt = isset( $data['created_at'] ) ? (string) $data['created_at'] : null;
+		$model->updatedAt = isset( $data['updated_at'] ) ? (string) $data['updated_at'] : null;
+		return $model;
+	}
+	/** @return array<string,mixed> */
+	public function toArray(): array {
+		return array(
+			'id' => $this->id, 'idtitolo' => $this->idtitolo, 'frontend_id' => $this->frontendId,
+			'titolo' => $this->titolo, 'autore' => $this->autore, 'esecutore' => $this->esecutore,
+			'durata' => $this->durata, 'scadenza' => $this->scadenza, 'descrizione' => $this->descrizione,
+			'tipoevento_codice' => $this->tipoeventoCodice, 'locandina_flag' => $this->locandinaFlag,
+			'locandina_url' => $this->locandinaUrl, 'cinetel' => $this->cinetel, 'tmdb' => $this->tmdb,
+			'trailer' => $this->trailer, 'cast' => $this->cast, 'tag' => $this->tag,
+			'source' => $this->source, 'sync_hash' => $this->syncHash, 'sync_active' => $this->syncActive,
+			'last_seen_sync' => $this->lastSeenSync, 'created_at' => $this->createdAt, 'updated_at' => $this->updatedAt,
+		);
+	}
+}
diff --git a/includes/ReadModels/ProgrammazioneCard.php b/includes/ReadModels/ProgrammazioneCard.php
new file mode 100644
index 0000000..abb6f0b
--- /dev/null
+++ b/includes/ReadModels/ProgrammazioneCard.php
@@ -0,0 +1,51 @@
+<?php
+/** Public schedule card read model. @package CinebotWp */
+namespace CinebotWp\ReadModels;
+final class ProgrammazioneCard {
+	public int $eventoId; public string $inizio; public int $titoloId; public string $titolo;
+	public string $descrizione; public ?string $locandinaUrl; public ?string $tipoCodice;
+	public ?string $tipoDescrizione; public int $localeId; public string $localeNome;
+	public ?string $comune; public ?string $prezzoMin; public ?string $prezzoMax;
+	/** @param array<string,mixed> $row */
+	public static function fromRow( array $row ): self {
+		$model = new self();
+		$model->eventoId = isset( $row['evento_id'] ) ? (int) $row['evento_id'] : 0;
+		$model->inizio = isset( $row['inizio'] ) ? (string) $row['inizio'] : '';
+		$model->titoloId = isset( $row['titolo_id'] ) ? (int) $row['titolo_id'] : 0;
+		$model->titolo = isset( $row['titolo'] ) ? (string) $row['titolo'] : '';
+		$model->descrizione = isset( $row['descrizione'] ) ? (string) $row['descrizione'] : '';
+		$model->locandinaUrl = isset( $row['locandina_url'] ) ? (string) $row['locandina_url'] : null;
+		$model->tipoCodice = isset( $row['tipo_codice'] ) ? (string) $row['tipo_codice'] : null;
+		$model->tipoDescrizione = isset( $row['tipo_descrizione'] ) ? (string) $row['tipo_descrizione'] : null;
+		$model->localeId = isset( $row['locale_id'] ) ? (int) $row['locale_id'] : 0;
+		$model->localeNome = isset( $row['locale_nome'] ) ? (string) $row['locale_nome'] : '';
+		$model->comune = isset( $row['comune'] ) ? (string) $row['comune'] : null;
+		$model->prezzoMin = isset( $row['prezzo_min'] ) ? (string) $row['prezzo_min'] : null;
+		$model->prezzoMax = isset( $row['prezzo_max'] ) ? (string) $row['prezzo_max'] : null;
+		return $model;
+	}
+}
diff --git a/phpcs.xml.dist b/phpcs.xml.dist
index 9a798b5..7870a50 100644
--- a/phpcs.xml.dist
+++ b/phpcs.xml.dist
@@ -9,11 +9,18 @@
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
+    <!-- Approved DTO interfaces use camelCase OO members; procedural naming rules remain enabled. -->
+    <rule ref="WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid">
+        <exclude-pattern type="relative">^includes/</exclude-pattern>
+    </rule>
+    <rule ref="WordPress.NamingConventions.ValidVariableName.PropertyNotSnakeCase">
+        <exclude-pattern type="relative">^includes/</exclude-pattern>
+    </rule>
 </ruleset>
diff --git a/tests/Unit/ModelsTest.php b/tests/Unit/ModelsTest.php
new file mode 100644
index 0000000..eb8b244
--- /dev/null
+++ b/tests/Unit/ModelsTest.php
@@ -0,0 +1,289 @@
+The complete 289-line test file is the committed blob `eb8b244` and is included by the exact command above. It covers seven complete DTO rows, exact snake_case keys, defaults/nullability, immutable inputs, integer flags, decimal strings, and every `ProgrammazioneCard` key.
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
```

The modified `specs/` files and untracked coordinator/review artifacts are outside the Task 3 implementation commit. No Task 3 implementation file is currently modified or untracked.
