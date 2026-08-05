# Cinebot WP — Design Spec

**Data:** 2026-08-02
**Stato:** Approvato (tutte le 6 sezioni confermate)
**Slug plugin:** `cinebot-wp`
**Versione iniziale:** 1.0.0
**PHP minimo:** 7.4
**WordPress minimo:** 6.0

## Contesto

Sviluppo di un plugin WordPress 6 che sincronizza le programmazioni eventi da API Cinebot (`https://ws.cinebot.it/v1/programmazione/{frontend}` con BASIC AUTH), li memorizza in tabelle custom del DB WP, ne permette la gestione in area admin (CRUD con gerarchia Titolo → Eventi → Settori → Prezzi), li espone pubblicamente sul frontend via shortcode, e offre gestione di Locali, Tipologie evento, Dashboard e Log Sincronizzazioni.

Il payload di esempio (`cinebot.json`) mostra una struttura nidificata:
- `programmazione[]` → `titoli[]` (spettacoli) → `eventi[]` (programmazioni) → `settori[]` → `prezzi[]`
- Eventi includono dati embedded di `locale` e `organizzatore`
- `tipoevento` (codice stringa come "45", "51", "53", "75") mappa alla tabella tipologie

## Decisioni chiave

- **Approccio C (OOP MVC-like + admin WP-native):** classi namespaced `CinebotWp\` con separazione Models/Repositories/Services/Admin/Frontend. Niente build tool JS, admin UI con `WP_List_Table` + form PHP custom.
- **Storage:** 7 tabelle custom InnoDB con prefisso `{wpdb->prefix}cinebot_`. Chiavi esterne logiche (indici, non constraint fisici) per compatibilità WP.
- **Sync:** import unidirezionale (sola lettura dall'API). Record importati con `source=api` vengono aggiornati e riconciliati: se assenti vengono disattivati, non eliminati, e riattivati quando ricompaiono. Record `source=manual` non vengono MAI toccati dalla sync.
- **Frontend:** shortcode `[cinebot_programmazione]` con layout a card, filtri AJAX, template overridabili dal tema e caching transient. Sono pubblici solo eventi riconciliati attivi con `stato=3`.
- **Sezioni admin (6):** API, Programmazioni, Locali, Tipologie evento, Dashboard, Log Sincronizzazioni.
- **Scope 1.0:** WordPress single-site; Multisite, write-back API, React/Gutenberg, retry automatici e circuit breaker sono fuori scope.

---

## 1. Architettura e struttura del plugin

### Header plugin

File principale `cinebot-wp.php` con header standard WP. Un autoloader PSR-4 minimale incluso in `includes/autoload.php` mappa il namespace `CinebotWp\` a `includes/`; il plugin distribuito non dipende da `vendor/`. Composer è usato solo per sviluppo, test e quality gate.

### Struttura cartelle

```
cinebot-wp/
├── cinebot-wp.php              (bootstrap: costanti, autoload, activation hook)
├── includes/
│   ├── Plugin.php              (container centrale: hook registration, lifecycle)
│   ├── Database/
│   │   ├── SchemaInstaller.php (creazione/aggiornamento tabelle su activation)
│   │   └── Migrations.php      (future migrazioni schema)
│   ├── Models/                 (value objects / DTO puri, no logica DB)
│   │   ├── Titolo.php
│   │   ├── Evento.php
│   │   ├── Settore.php
│   │   ├── Prezzo.php
│   │   ├── Locale.php
│   │   ├── TipologiaEvento.php
│   │   └── SyncLog.php
│   ├── Repositories/           (accesso DB - un repository per tabella)
│   │   ├── TitoloRepository.php
│   │   ├── EventoRepository.php
│   │   ├── SettoreRepository.php
│   │   ├── PrezzoRepository.php
│   │   ├── LocaleRepository.php
│   │   ├── TipologiaRepository.php
│   │   └── SyncLogRepository.php
│   ├── Services/               (logica di business)
│   │   ├── ApiClient.php        (GET BASIC AUTH verso ws.cinebot.it)
│   │   ├── SyncService.php      (orchestra import: ApiClient → mappa → persiste)
│   │   ├── SettingsService.php  (legge/scrive opzioni)
│   │   ├── CronScheduler.php    (registrazione/schedulazione WP-Cron)
│   │   └── LocandinaService.php (costruisce URL locandina)
│   ├── Admin/                  (UI admin - WP_List_Table + form)
│   │   ├── AdminMenu.php        (6 voci menu + submenu)
│   │   └── Pages/
│   │       ├── DashboardPage.php
│   │       ├── ApiPage.php
│   │       ├── TitoliListPage.php
│   │       ├── TitoloEditPage.php
│   │       ├── LocaliListPage.php
│   │       ├── LocaleEditPage.php
│   │       ├── TipologieListPage.php
│   │       ├── TipologiaEditPage.php
│   │       └── SyncLogPage.php
│   └── Frontend/
│       ├── ShortcodeHandler.php  ([cinebot_programmazione])
│       └── TemplateRenderer.php  (render card con template PHP)
├── templates/                  (template PHP overridabili dal tema)
│   ├── programmazione-cards.php
│   ├── titolo-card.php
│   └── dettaglio-titolo.php
├── assets/
│   ├── css/cinebot-admin.css
│   ├── css/cinebot-frontend.css
│   └── js/cinebot-frontend.js   (filtri AJAX, vanilla JS)
├── languages/
│   └── cinebot-wp-it_IT.po
└── uninstall.php               (pulizia su disinstallazione esplicita)
```

### Pattern architetturali

- **Models** = DTO puri (getter/setter, niente DB)
- **Repositories** = solo accesso DB (CRUD + query con `$wpdb->prepare`), niente logica business
- **Services** = orchestrazione logica (SyncService chiama ApiClient + TitoloRepository)
- **Admin Pages** = solo UI, delegano a Services/Repositories
- **Dipendenze** istanziate in `Plugin.php` (container minimale, niente framework DI esterno)

### File principale

```php
<?php
/**
 * Plugin Name: Cinebot WP
 * Plugin URI: https://cinebot.it
 * Description: Sincronizzazione programmazioni eventi da Cinebot API con gestione admin e frontend.
 * Version: 1.0.0
 * Author: Cinebot
 * License: GPL-2.0+
 * Text Domain: cinebot-wp
 * Domain Path: /languages
 */

defined('ABSPATH') or die('No direct access');

define('CINEBOT_WP_VERSION', '1.0.0');
define('CINEBOT_WP_PATH', plugin_dir_path(__FILE__));
define('CINEBOT_WP_URL', plugin_dir_url(__FILE__));

require CINEBOT_WP_PATH . 'includes/autoload.php';

CinebotWp\Plugin::instance()->boot();
```

---

## 2. Schema del database

7 tabelle custom InnoDB con prefisso `{wpdb->prefix}cinebot_`. Chiavi esterne logiche (indici, non constraint fisici) per compatibilità WP. L'attivazione fallisce senza creare tabelle se InnoDB non è disponibile.

### 2.1 `{prefix}cinebot_titoli` — spettacoli

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK locale |
| `idtitolo` | BIGINT UNSIGNED NULL | ID remoto Cinebot (NULL per record manuali) |
| `frontend_id` | BIGINT UNSIGNED NULL | scope della programmazione API |
| `titolo` | VARCHAR(255) NOT NULL | |
| `autore` | VARCHAR(255) | |
| `esecutore` | VARCHAR(255) | |
| `durata` | INT | minuti |
| `scadenza` | TINYINT | 0/1 |
| `descrizione` | LONGTEXT | |
| `tipoevento_codice` | VARCHAR(10) | FK logica a `cinebot_tipologie_eventi.codice` |
| `locandina_flag` | TINYINT | 0/1 dall'API |
| `locandina_url` | VARCHAR(500) | costruita da `LocandinaService` |
| `cinetel` | VARCHAR(100) | |
| `tmdb` | VARCHAR(100) | |
| `trailer` | VARCHAR(500) | |
| `cast` | TEXT | |
| `tag` | TEXT | JSON array |
| `source` | VARCHAR(10) NOT NULL DEFAULT 'api' | `api` o `manual` |
| `sync_hash` | VARCHAR(64) | hash del payload per confronto rapido |
| `sync_active` | TINYINT DEFAULT 1 | 0 se assente dall'ultima sync del frontend |
| `last_seen_sync` | CHAR(36) NULL | token dell'ultima riconciliazione |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: UNIQUE(`idtitolo`), INDEX(`source`), INDEX(`tipoevento_codice`), INDEX(`frontend_id`, `sync_active`, `last_seen_sync`)

### 2.2 `{prefix}cinebot_eventi` — programmazioni/screenings

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `idevento` | BIGINT UNSIGNED NULL | ID remoto, UNIQUE |
| `titolo_id` | BIGINT UNSIGNED NOT NULL | FK → `cinebot_titoli.id` |
| `inizio` | DATETIME NOT NULL | |
| `organizzatore_id` | BIGINT | solo traccia |
| `organizzatore_cf` | VARCHAR(50) | solo traccia |
| `locale_id` | BIGINT UNSIGNED NOT NULL | FK → `cinebot_locali.id` |
| `stato` | TINYINT | dall'API (3=attivo) |
| `otp` | TINYINT | |
| `controlloaccessi` | TINYINT | |
| `mappa` | INT | codice mappa |
| `source` | VARCHAR(10) DEFAULT 'api' | |
| `sync_active` | TINYINT DEFAULT 1 | stato di riconciliazione |
| `last_seen_sync` | CHAR(36) NULL | token ultima sync |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: UNIQUE(`idevento`), INDEX(`titolo_id`), INDEX(`locale_id`), INDEX(`inizio`)

### 2.3 `{prefix}cinebot_settori` — settori per evento

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `idsettore` | BIGINT UNSIGNED NULL | ID remoto |
| `evento_id` | BIGINT UNSIGNED NOT NULL | FK → `cinebot_eventi.id` |
| `nome` | VARCHAR(255) | es. "Posto unico", "Platea 1" |
| `source` | VARCHAR(10) DEFAULT 'api' | |
| `sync_active` | TINYINT DEFAULT 1 | stato di riconciliazione |
| `last_seen_sync` | CHAR(36) NULL | token ultima sync |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: INDEX(`evento_id`), UNIQUE(`idsettore`, `evento_id`)

### 2.4 `{prefix}cinebot_prezzi` — prezzi per settore

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `idprezzo` | BIGINT UNSIGNED NULL | ID remoto |
| `settore_id` | BIGINT UNSIGNED NOT NULL | FK → `cinebot_settori.id` |
| `nome` | VARCHAR(255) | es. "Donne & Uomini INT ON" |
| `tipo` | VARCHAR(5) | "I" intero, "R" ridotto |
| `importo` | DECIMAL(10,2) | |
| `prevendita` | DECIMAL(10,2) | |
| `stato` | TINYINT | 0/1 |
| `source` | VARCHAR(10) DEFAULT 'api' | |
| `sync_active` | TINYINT DEFAULT 1 | stato di riconciliazione |
| `last_seen_sync` | CHAR(36) NULL | token ultima sync |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: INDEX(`settore_id`), UNIQUE(`idprezzo`, `settore_id`)

### 2.5 `{prefix}cinebot_locali`

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `locale_id_remoto` | BIGINT UNSIGNED NULL | ID remoto, UNIQUE |
| `nome` | VARCHAR(255) NOT NULL | es. "Cinema Martinovich" |
| `codice` | VARCHAR(50) | es. "0250120220822" |
| `indirizzo` | VARCHAR(255) | |
| `cap` | VARCHAR(10) | |
| `comune` | VARCHAR(100) | |
| `provincia` | VARCHAR(10) | |
| `mappa` | INT | |
| `source` | VARCHAR(10) DEFAULT 'manual' | `api` (creati dalla sync) o `manual` (creati dall'utente) |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: UNIQUE(`locale_id_remoto`), INDEX(`comune`)

### 2.6 `{prefix}cinebot_tipologie_eventi`

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `codice` | VARCHAR(10) NOT NULL | es. "45", "01" |
| `descrizione` | VARCHAR(255) NOT NULL | es. "TEATRO PROSA" |
| `predefinito` | TINYINT DEFAULT 0 | 1 per le 62 del PDF |
| `attivo` | TINYINT DEFAULT 1 | per disattivare senza eliminare |
| `created_at` | DATETIME | |
| `updated_at` | DATETIME | |

Indici: UNIQUE(`codice`)

Le 62 tipologie predefinite (vedi appendice A) vengono inserite all'attivazione con `predefinito=1`. Se l'utente le disattiva (toggle `attivo=0`), l'attivazione successiva NON le riattiva (rispetta la scelta utente). Le nuove tipologie utente hanno `predefinito=0`.

### 2.7 `{prefix}cinebot_sync_log`

| Campo | Tipo | Note |
|---|---|---|
| `id` | BIGINT UNSIGNED AI | PK |
| `started_at` | DATETIME NOT NULL | |
| `finished_at` | DATETIME | |
| `status` | VARCHAR(20) | `success`, `error`, `partial` |
| `titoli_added` | INT DEFAULT 0 | |
| `titoli_updated` | INT DEFAULT 0 | |
| `eventi_added` | INT DEFAULT 0 | |
| `eventi_updated` | INT DEFAULT 0 | |
| `error_message` | TEXT | |
| `payload_hash` | VARCHAR(64) | per evitare sync identiche |

Indici: INDEX(`started_at`), INDEX(`status`)

### Logica di sync (`SyncService`)

1. Carica record locali esistenti mappati per `idtitolo`/`idevento`
2. Per ogni titolo dal payload:
   - Se esiste e `source=api`: aggiorna i campi dall'API
   - Se esiste e `source=manual`: **salta** (mai toccato dalla sync)
   - Se non esiste: inserisci nuovo con `source=api`
3. Ogni record API visto riceve `sync_active=1` e il token `last_seen_sync`; la stessa logica vale per eventi/settori/prezzi.
4. A fine scope, gli elementi API non visti vengono marcati `sync_active=0` con propagazione ai figli. Se ricompaiono vengono riattivati mantenendo la PK locale.
5. I locali dal payload vengono creati o aggiornati (upsert su `locale_id_remoto`) — arrivano embedded negli eventi.
6. Scrive record in `sync_log` con contatori. Tutta la riconciliazione avviene in una transazione InnoDB.

### URL locandina

Costruita da `LocandinaService` con il pattern: `https://{host}/{path}/titolo/{idtitolo}/locandina`. I campi `host`, `path` provengono dal payload API (es. `host=ticket.cinebot.it`, `path=martinovich`). Per record manuali, l'URL è opzionale e inseribile dall'utente.

---

## 3. Sezione "API" + Cron di sincronizzazione

### Impostazioni (opzione WP `cinebot_wp_settings`, array serializzato)

| Chiave | Tipo | Default | Note |
|---|---|---|---|
| `api_username` | string | — | per BASIC AUTH |
| `api_password` | string (cifrata) | — | cifrata con `openssl_encrypt` + `AUTH_SALT` |
| `api_frontend` | int\|null | null | numerico, opzionale. Se vuoto l'URL diventa `/v1/programmazione` senza `/{frontend}` |
| `sync_frequency` | string | `daily` | `hourly` / `twicedaily` / `daily` / `weekly` |
| `sync_enabled` | bool | false | attiva/disattiva il cron |
| `api_base_url` | string | `https://ws.cinebot.it` | configurabile per test |

### Sicurezza password

La password BASIC AUTH viene salvata cifrata con `openssl_encrypt` (AES-256-CBC) usando come chiave il `AUTH_SALT` di `wp-config.php` (se presente) o un salt generato e memorizzato in un'opzione separata. Mai stampata in chiaro in HTML — il campo password mostra solo placeholder "•••••" se già impostata.

### Pagina Admin "API"

Form con campi: Username (text), Password (password), Frontend (number, opzionale), Frequenza sync (select), Attiva cron (checkbox).

Bottoni:
- **Salva impostazioni** — submit form standard WP
- **Test connessione** (AJAX `wp_ajax_cinebot_wp_test_connection`) — chiama l'API con credenziali correnti, mostra 200/401/errore + numero titoli ricevuti
- **Sincronizza ora** (AJAX `wp_ajax_cinebot_wp_sync_now`) — avvia sync manuale immediata, mostra progress. Max 1 sync contemporanea tramite lock atomico option-backed con token e TTL 5 min.

### ApiClient (`CinebotWp\Services\ApiClient`)

```php
class ApiClient {
    public function __construct(SettingsService $settings) { ... }
    
    public function fetchProgrammazione(): array {
        $url = $this->buildUrl();  // {base}/v1/programmazione[/{frontend}]
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($user . ':' . $pass),
                'Accept' => 'application/json',
            ],
            'timeout' => 60,
        ]);
        // Controlla is_wp_error, response code, json_decode
        // Ritorna il payload completo (array associativo)
        // Lancia ApiException con codice+messaggio su errore
    }
}
```

### CronScheduler (`CinebotWp\Services\CronScheduler`)

Hook: `cinebot_wp_sync_event`

- Su `register_activation_hook`: se `sync_enabled=1` → `wp_schedule_event(time(), $frequency, 'cinebot_wp_sync_event')`
- Su `register_deactivation_hook`: `wp_clear_scheduled_hook('cinebot_wp_sync_event')`
- Su `update_option_cinebot_wp_settings` (cambio frequenza o enabled): cancella e riprogramma con nuovo intervallo

### SyncService (`CinebotWp\Services\SyncService`)

```php
class SyncService {
    public function sync(): SyncResult {
        $logId = $this->logRepo->startLog();
        try {
            $payload = $this->apiClient->fetchProgrammazione();
            $programmazione = $payload['programmazione'] ?? [];
            $stats = ['titoli_added'=>0, 'titoli_updated'=>0, ...];
            
            foreach ($programmazione as $fe) {
                $this->syncFrontend($fe, $stats);  // itera titoli[]
            }
            
            $this->logRepo->finishLog($logId, 'success', $stats);
            return SyncResult::success($stats);
        } catch (ApiException $e) {
            $this->logRepo->finishLog($logId, 'error', [], $e->getMessage());
            return SyncResult::error($e->getMessage());
        }
    }
    
    private function syncFrontend(array $fe, array &$stats): void {
        foreach ($fe['titoli'] ?? [] as $titoloData) {
            $existing = $this->titoloRepo->findByIdtitolo($titoloData['idtitolo']);
            if ($existing && $existing->source === 'manual') continue;
            
            // upsert locale embedded
            $localeId = $this->upsertLocaleFromEvent($titoloData['eventi']);
            
            // upsert titolo
            $titoloId = $this->upsertTitolo($titoloData, $existing);
            $stats[$existing ? 'titoli_updated' : 'titoli_added']++;
            
            // upsert eventi + settori + prezzi
            $this->upsertEventi($titoloData['eventi'], $titoloId, $stats);
        }
    }
}
```

---

## 4. Sezioni admin

### 4.1 Sezione "Programmazioni"

**Lista titoli (`TitoliListPage` estende `WP_List_Table`):**

Colonne: Titolo | Autore | Tipo evento | Locandina (thumb) | Eventi (count) | Source | Ultima modifica

- Filtri: per tipo evento (select), per source (api/manual), ricerca testo (titolo/autore)
- Bulk: elimina
- Per-row: "Modifica" / "Elimina"
- Pulsante: "Nuovo titolo" → `TitoloEditPage`
- Pagination 50/page

**Edit titolo (`TitoloEditPage`):** form gerarchico in un'unica pagina.

1. **Blocco Titolo** (in alto): titolo, autore, esecutore, durata, tipo evento (select da tipologie attive), descrizione (wysiwyg), locandina (anteprima URL), cinetel/tmdb, trailer, cast, tag, flag `source` (read-only)
2. **Blocco Eventi** (sotto, lista di card espandibili): ogni evento è una card con header (data/ora + locale) e form:
   - `inizio` (datetime picker), `locale_id` (select con autocomplete, link a Sezione Locali), `organizzatore_id`, `organizzatore_cf`, `stato`, `otp`, `controlloaccessi`, `mappa`
   - **Sotto-blocco Settori**: lista inline con add/remove
     - Ogni settore: nome + lista prezzi
     - Ogni prezzo: nome, tipo (I/R), importo, prevendita, stato
   - Bottone "Aggiungi evento" / "Rimuovi evento"
3. JS jQuery (vanilla, niente React) gestisce add/remove di eventi/settori/prezzi dinamicamente nel form

Al submit: un unico handler salva Titolo → Eventi → Settori → Prezzi in transazione. I record manuali ottengono `source=manual`. Il flag `source` dei record importati resta `api` anche se editati (l'API può ancora aggiornarli al prossimo sync).

### 4.2 Sezione "Locali"

**Lista (`LocaliListPage`):** Nome | Codice | Comune | Provincia | Mappa | Eventi (count)

- Filtri: comune, provincia
- Pulsante "Nuovo locale"

**Edit (`LocaleEditPage`):** nome, codice, indirizzo, cap, comune, provincia, mappa. I locali creati manualmente dall'utente hanno `source=manual` e non vengono mai toccati dalla sync. I locali creati automaticamente dalla sync (dati embedded negli eventi) hanno `source=api` e vengono aggiornati a ogni sync (stessa logica dei titoli).

### 4.3 Sezione "Tipologie evento"

**Lista (`TipologieListPage`):** Codice | Descrizione | Predefinito (badge) | Attivo (toggle) | Eventi (count)

- Filtri: predefinite/personalizzate, attivo/non attivo
- Pulsante "Nuova tipologia"

**Edit (`TipologiaEditPage`):** codice (read-only se predefinito), descrizione, attivo.

Le 62 tipologie del PDF vengono inserite all'attivazione con `predefinito=1`. Se l'utente le disattiva (toggle `attivo=0`), l'attivazione successiva NON le riattiva (rispetta la scelta utente). Le nuove tipologie utente hanno `predefinito=0`.

### 4.4 Sezione "Dashboard"

Pagina principale del plugin (`toplevel_page_cinebot-wp`):

- **Stato sincronizzazione**: ultima sync (timestamp + status badge verde/rosso), prossima sync programmata (timestamp), pulsanti "Sincronizza ora" / "Vai a impostazioni API"
- **Contatori rapidi**: titoli totali, titoli manuali, eventi totali, locali, tipologie attive
- **Ultimi 5 record sync_log**: tabella compatta (data, status, titoli+eventi, link a Log)
- **Quick links**: card cliccabili per ogni sezione

### 4.5 Sezione "Log Sincronizzazioni"

**Lista (`SyncLogPage` estende `WP_List_Table`):** Iniziata alle | Finita alle | Durata | Status (badge) | Titoli +/Δ | Eventi +/Δ | Errore (anteprima tooltip)

- Per-row: "Dettagli" (mostra payload_hash, error completo)
- Bulk: elimina log vecchi
- Pulsante: "Pulisci log > 30 giorni"

### Capacità e ruoli

Tutte le sezioni admin richiedono capability `manage_options`. Per estensione futura: filtro `cinebot_wp_capability` per personalizzare (es. gestori locali).

---

## 5. Frontend pubblico (shortcode)

### Shortcode principale

```
[cinebot_programmazione]
```

**Attributi supportati:**

| Attributo | Tipo | Default | Descrizione |
|---|---|---|---|
| `tipo` | string | tutti | Codice tipologia (es. `tipo="45"` per Teatro Prosa) |
| `locale` | int | tutti | ID locale WP |
| `comune` | string | tutti | Filtra per comune |
| `from` | date | oggi | Data inizio (YYYY-MM-DD). Default: eventi futuri |
| `to` | date | nessuno | Data fine |
| `limit` | int | 50 | Max risultati |
| `orderby` | string | `inizio` | `inizio` / `titolo` |
| `order` | string | `ASC` | `ASC` / `DESC` |
| `show_filters` | bool | true | Mostra filtri AJAX sopra le card |
| `show_desc` | bool | false | Mostra descrizione breve nella card |
| `layout` | string | `cards` | `cards` / `list` (preview futuro) |

Esempi:
```
[cinebot_programmazione tipo="45" from="2026-10-01" limit="20"]
[cinebot_programmazione locale="2" show_desc="true"]
[cinebot_programmazione comune="Bassano del Grappa" show_filters="false"]
```

### Render delle card (`templates/programmazione-cards.php`)

Template PHP, overridabile dal tema copiandolo in `theme/cinebot-wp/programmazione-cards.php`.

Struttura HTML semantica, no React, CSS minimale con classi `cinebot-*`:

```html
<div class="cinebot-programmazione" data-filters='["tipo","comune","from"]'>
  <form class="cinebot-filters" id="cinebot-filters-{ID}">
    <select name="tipo">...tipologie attive...</select>
    <input type="date" name="from">
    <input type="text" name="comune" placeholder="Comune">
    <button type="submit">Filtra</button>
  </form>
  
  <div class="cinebot-cards">
    <article class="cinebot-card" data-event-id="...">
      <div class="cinebot-card-locandina">
        <img src="{locandina_url}" alt="{titolo}" loading="lazy">
      </div>
      <div class="cinebot-card-body">
        <h3 class="cinebot-card-title">{titolo}</h3>
        <p class="cinebot-card-meta">
          <span class="cinebot-card-data">{inizio_formattata}</span>
          <span class="cinebot-card-locale">{locale_nome} — {comune}</span>
          <span class="cinebot-card-tipo">{tipologia_descrizione}</span>
        </p>
        <p class="cinebot-card-prezzo">da €{prezzo_min} a €{prezzo_max}</p>
        <p class="cinebot-card-desc">{descrizione_breve}</p>
      </div>
    </article>
    ...
  </div>
  
  <button class="cinebot-load-more" data-page="2">Carica altri</button>
</div>
```

### Pagina dettaglio titolo

Shortcode `[cinebot_titolo id="123"]` (o gestione automatica via URL: `/programmazione?titolo=123`):

- Locandina grande
- Titolo, autore, esecutore, durata, descrizione completa
- Lista eventi del titolo (data, locale, settore/prezzi)
- Link "Acquista" (se disponibile — futuro)

### Filtri AJAX

`assets/js/cinebot-frontend.js` (vanilla JS, niente jQuery):

- Submit form filtri → `POST wp_ajax_cinebot_filter` con `action=cinebot_wp_filter&{params}&shortcode_id`
- Response JSON: `{ html: '<cards...>', total, has_more }`
- Aggiorna `.cinebot-cards` innerHTML
- "Load more" → `page+1` fino a `has_more=false`

Endpoint pubblico: `wp_ajax_nopriv_cinebot_wp_filter` per utenti non loggati.

### Stili

`assets/css/cinebot-frontend.css` — CSS minimale, usa CSS variables per temi (`--cinebot-accent`, `--cinebot-text`, ecc.), override dal tema via `add_theme_support('cinebot-wp')` o filtro `cinebot_wp_styles`.

### Caching

- I risultati shortcode vengono cached in transient (`cinebot_prog_{hash_params}`, TTL 15min, invalidated su sync/modify)
- Filtro `cinebot_wp_cache_ttl` per personalizzare

---

## 6. Sicurezza, performance, testing, installazione

### Sicurezza

- **Nonce** su tutti i form admin e azioni AJAX (`wp_verify_nonce`)
- **Capability check** `manage_options` su ogni pagina admin e AJAX handler
- **Escaping output**: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post` per descrizioni wysiwyg
- **Prepared statements**: `$wpdb->prepare()` su ogni query (prevenzione SQL injection)
- **Password API cifrata** con `openssl_encrypt` (AES-256-CBC) + `AUTH_SALT` (mai in chiaro nel DB né in HTML)
- **Sanitizzazione input**: `sanitize_text_field`, `sanitize_textarea_field`, `absint`, `(float)` per importi
- **CSRF**: nonce su tutti i form azione (save/sync/delete)
- **Lock sincronizzazione**: max 1 sync contemporanea tramite acquisizione atomica `add_option`, token proprietario e TTL 5 min; solo il proprietario può rilasciare il lock

### Performance

- **Indici DB** progettati per i pattern di query (idtitolo, idevento, locale_id, inizio, source)
- **Caching transient** risultati shortcode (TTL 15min, invalidato su sync)
- **Lazy loading** immagini locandina con `loading="lazy"`
- **Pagination** su tutte le liste admin (50/page default) e frontend (20/page)
- **WP-Cron**: intervallo minimo 1 ora per evitare sovraccarico; per sync più frequenti si raccomanda Action Scheduler (futuro)
- **No autoload** per le opzioni voluminose (solo `cinebot_wp_settings` in autoload, ~1KB)

### Internazionalizzazione

- Text domain `cinebot-wp`
- Tutte le stringhe user-facing con `__()` / `_e()` / `esc_html__()`
- File `.pot` generato con `wp-pot`
- Lingua base: italiano (`languages/cinebot-wp-it_IT.po`)

### Testing

- **Docker Compose** per ambiente PHP/MySQL e provisioning riproducibile della WordPress core test suite
- **PHPUnit 9** per test unit/integration, **WPCS** per lint e **PHPStan WordPress** per analisi statica
- **Unit test** isolati per Services (SyncService, ApiClient) con mock di `wp_remote_get`
- **Fixtures**: il `cinebot.json` reale va in `tests/fixtures/cinebot-sample.json`
- Test minimi richiesti:
  - `SyncServiceTest`: import nuovo record, aggiornamento, salto record manuali, gestione errori API
  - `TipologiaRepositoryTest`: insert 62 tipologie, toggle attivo
  - `ShortcodeHandlerTest`: render con vari attributi, caching
  - `ApiClientTest`: build URL con/senza frontend, BASIC AUTH header, errori 401/500
- **CI**: GitHub Actions con matrix PHP 7.4/8.0/8.1/8.2 e WP 6.0-6.x

### Installazione e attivazione

**Activation hook (`Plugin::activate`):**

1. Verifica il supporto InnoDB e crea le 7 tabelle con `dbDelta()` (idempotente)
2. Inserisce le 62 tipologie predefinite (solo se tabella vuota — non sovrascrive)
3. Salva opzione `cinebot_wp_version` per migrazioni future
4. NON schedula il cron (l'utente deve configurare API + attivare sync)

**Deactivation hook:**

- `wp_clear_scheduled_hook('cinebot_wp_sync_event')`
- Non elimina dati (disattivazione ≠ disinstallazione)

**Uninstall (`uninstall.php`, richiede `define('WP_UNINSTALL_PLUGIN', true)`):**

- Elimina le 7 tabelle `cinebot_*`
- Elimina opzioni `cinebot_wp_*` (settings, version, sync_lock)
- Pulisce transients `cinebot_prog_*`
- Rimuove eventi `cinebot_wp_sync_event` programmati
- Conferma: l'utente deve esplicitamente disinstallare (non automatico)

---

## Appendice A: 62 tipologie evento predefinite

Inserite all'attivazione con `predefinito=1`:

| Codice | Descrizione |
|---|---|
| 01 | CINEMA |
| 04 | PROIEZIONI IN LOCALI CINEMA DIVERSE DA SPETTACOLO |
| 05 | CALCIO (SERIE A/B ED INTERNAZIONALI) |
| 06 | CALCIO (SERIE C ED INFERIORI) |
| 07 | TELEDIFFUSIONE IN FORMA CODIFICATA NEI LOCALI APERTI AL PUBBLICO |
| 08 | DIFFUSIONE RADIO/TV CON ACCESSO CONDIZIONATO |
| 10 | PUGILATO |
| 11 | CICLISMO |
| 12 | ATLETICA LEGGERA |
| 13 | NUOTO E PALLANUOTO |
| 14 | PALLACANESTRO |
| 15 | PALLAVOLO |
| 16 | RUGBY |
| 17 | BASEBALL |
| 18 | TENNIS |
| 19 | CONCORSI IPPICI |
| 20 | SPORT INVERNALI |
| 21 | AUTOMOBILISMO |
| 22 | MOTOCICLISMO |
| 23 | MOTONAUTICA |
| 24 | CORSE CAVALLI (INGRESSI) |
| 25 | SPORT CON SCOMMESSE (INGRESSI) |
| 26 | ALTRI SPORT (INGRESSI) |
| 30 | CASINÒ (INGRESSI) |
| 33 | CASINÒ (PROVENTI DEL GIOCO) |
| 41 | MUSEI |
| 42 | EVENTI DIVERSI DA SPETTACOLO O INTRATTENIMENTO |
| 45 | TEATRO PROSA |
| 46 | TEATRO PROSA DIALETTALE |
| 47 | TEATRO REPERTORIO NAPOLETANO |
| 48 | TEATRO LIRICO |
| 49 | BALLETTO CLASSICO E MODERNO |
| 50 | OPERETTA |
| 51 | RIVISTE-COMMEDIE MUSICALI |
| 52 | CONCERTI CLASSICI |
| 53 | CONCERTI MUSICA LEGGERA |
| 54 | ARTE VARIA (IVA 10%) |
| 55 | BURATTINI-MARIONETTE |
| 56 | RECITALS LETTERARI |
| 57 | CONCERTI BANDISTICI-CORALI |
| 58 | CONCERTI JAZZ |
| 59 | CONCERTI DI DANZA |
| 60 | BALLO CON MUSICA DAL VIVO |
| 61 | BALLO CON MUSICA PREREGISTRATA |
| 64 | CONCERTINI CON MUSICA PREREGISTRATA |
| 65 | CONCERTINI CON MUSICA DAL VIVO |
| 67 | CONCERTI CORALI |
| 68 | CONCERTI FOLKLORISTICI |
| 70 | FIERE |
| 71 | MOSTRE |
| 74 | ARTE VARIA (IVA 22%) |
| 75 | CIRCO |
| 76 | SPETTACOLI VIAGGIANTI |
| 77 | PARCHI DIVERTIMENTO E ACQUATICI (con prevalenza attività dello spettacolo viaggiante) |
| 78 | PARCHI DIVERTIMENTO E ACQUATICI (senza prevalenza attività dello spettacolo viaggiante) |
| 84 | BOWLING |
| 85 | NOLEGGIO GO-KARTS |
| 90 | MANIFESTAZIONI MISTE (all'aperto) |
| 91 | MULTIMEDIALITÀ |
| 97 | ALTRE ATTIVITÀ DI SPETTACOLO CONGIUNTE CON ALTRE NON DI SPETTACOLO |
| 98 | ALTRI SPETTACOLI O INTRATTENIMENTI (in alberghi e villaggi turistici) |
| 99 | ALBERGHI E VILLAGGI TURISTICI (attività di spettacolo) |

---

## Riepilogo decisioni

| Decisione | Scelta |
|---|---|
| Approccio architetturale | C: OOP MVC-like + admin WP-native |
| Storage | 7 tabelle custom InnoDB con chiavi esterne logiche |
| Sync | Import unidirezionale con riconciliazione; record `source=manual` mai toccati |
| Frontend | Shortcode `[cinebot_programmazione]` con card + filtri AJAX; solo record attivi con evento `stato=3` |
| Sezioni admin | 6: API, Programmazioni, Locali, Tipologie, Dashboard, Log |
| Tipologie | 62 predefinite (PDF) + aggiunte utente |
| Locandina | URL costruito: `https://{host}/{path}/titolo/{idtitolo}/locandina` |
| Sicurezza password | `openssl_encrypt` AES-256-CBC + `AUTH_SALT` |
| Cron | WP-Cron nativo, intervallo configurabile hourly/daily/weekly e lock atomico |
| Caching frontend | Transient TTL 15min, invalidato su sync |
| Testing | PHPUnit + wp-phpunit, fixture `cinebot-sample.json` |
| Uninstall | Elimina tabelle + opzioni + transients + cron (esplicito) |
