# Cinebot WP — Home Sections & Pagination Design Spec

**Data:** 2026-08-09
**Stato:** Approvato
**Plugin:** cinebot-wp 1.0.0
**Riferimento:** `docs/superpowers/specs/2026-08-02-cinebot-wp-plugin-design.md` (design 1.0 approvato)

## Contesto

Il plugin Cinebot WP 1.0 è implementato (tutti i 19 task committed su `feat/cinebot-wp`). Lo shortcode `[cinebot_programmazione]` esiste già con filtri AJAX, load-more AJAX, caching transient e template overridabili dal tema. Questa nuova funzionalità estende lo shortcode esistente per supportare due casi d'uso nuovi sulla home page e sulle pagine di elenco completo, senza introdurre rewrite rules, pagine virtuali o React.

### Requisiti utente

1. Nella home page mostrare una sezione con le **prime 4 programmazioni** della tipologia **CINEMA** (codice `"01"`) e, subito sotto, una sezione con le **prime 8 programmazioni** delle **altre tipologie** (tutte tranne CINEMA).
2. Ogni sezione ha un bottone **"Vedi altro"**.
3. Cliccando "Vedialtro" si arriva a una pagina con l'elenco completo della tipologia, con eventuale paginazione.
4. La pagina di destinazione è una **pagina WP reale creata dall'utente** che contiene lo shortcode.

### Gap rispetto al design 1.0

- Il filtro `tipo` attuale supporta solo l'**inclusione** di un singolo codice (`ty.codice = %s`); non esclude. Serve una nuova capacità "tutti tranne X".
- Non esiste un bottone "Vedi altro" che linka a una pagina separata; esiste solo "Carica altri" (load-more AJAX che accoda card sulla stessa pagina).
- Non esiste una paginazione numerata server-side con URL condivisibili.

## Decisioni chiave

- **Approccio C (estensione minima):** estendere lo shortcode `[cinebot_programmazione]` esistente con nuovi attributi, invece di creare nuovi shortcode o template dedicati. Coerente col principio YAGNI e col design 1.0 (un solo template `programmazione-cards.php` con classi `cinebot-*`).
- **CINEMA = codice `"01"`:** come da appendice A del design 1.0. Il filtro shortcode opera per `codice`, non per `id` autoincrement.
- **Pagina di destinazione = pagina WP reale creata dall'utente** che contiene lo shortcode. Niente rewrite rules, niente pagine virtuali generate dal plugin.
- **Paginazione opzionale:** di default resta il load-more AJAX esistente; un attributo `pagination="numbered"` attiva la paginazione numerata server-side via query string `?cinebot_page=N` (senza permalink rewrite).
- **Precedenza `tipo` su `exclude_tipo`:** se entrambi sono impostati, `tipo` prevale e `exclude_tipo` viene ignorato (con notice in PHPStan/log di debug, ma nessun errore utente).
- **Scope 1.0 mantenuto:** niente Multisite, write-back API, React/Gutenberg, retry automatici. Tutto shortcode + template, come da design 1.0.

---

## 1. Nuovi attributi shortcode

Estensione di `[cinebot_programmazione]` con i seguenti attributi (aggiunti a `ShortcodeHandler::normalizeAttributes()`):

| Attributo | Tipo | Default | Descrizione |
|---|---|---|---|
| `exclude_tipo` | string | `''` | Codice tipologia da **escludere** (`ty.codice != %s`). Ignorato se `tipo` è impostato. |
| `more_url` | string | `''` | Se non vuoto, renderizza un link "Vedi altro" verso quell'URL **al posto** del load-more AJAX. |
| `more_label` | string | `Vedi altro` | Etichetta del bottone "Vedi altro" (i18n text domain `cinebot-wp`). |
| `pagination` | string | `ajax` | `ajax` (load-more esistente, default) oppure `numbered` (paginazione numerata server-side via `?cinebot_page=N`). |
| `per_page` | int | = `limit` | Dimensione pagina per la paginazione numerata. Default uguale a `limit`. Clamp 1–100. |

### Comportamento precedence

- Se `tipo` è non vuoto → filtro per inclusione su `ty.codice = %s`; `exclude_tipo` viene **ignorato**.
- Se `tipo` è vuoto e `exclude_tipo` è non vuoto → filtro per esclusione `ty.codice != %s`.
- Se entrambi sono vuoti → nessun filtro tipologia (tutte le tipologie attive).

### Esempi d'uso

**Home page (due shortcode nel contenuto della pagina front-page):**

```
[cinebot_programmazione tipo="01" limit="4" show_filters="false" more_url="/programmazione-cinema"]
[cinebot_programmazione exclude_tipo="01" limit="8" show_filters="false" more_url="/programmazione-altri-tipi"]
```

**Pagina di destinazione "Programmazione Cinema" (pagina WP creata dall'utente):**

```
[cinebot_programmazione tipo="01" limit="20" pagination="numbered" per_page="20"]
```

**Pagina di destinazione "Altre tipologie" (pagina WP creata dall'utente):**

```
[cinebot_programmazione exclude_tipo="01" limit="20" pagination="numbered" per_page="20"]
```

**Combinazione con load-more AJAX (default, senza paginazione numerata):**

```
[cinebot_programmazione tipo="01" limit="20"]
```

---

## 2. Modifiche al repository

### `TitoloRepository::public_query()` — `includes/Repositories/TitoloRepository.php:283`

Aggiungere un ramo per `exclude_tipo` nel builder dei predicati:

```php
if ( isset( $filters['tipo'] ) && '' !== trim( (string) $filters['tipo'] ) ) {
    $clauses[] = 'ty.codice = %s';
    $values[] = sanitize_text_field( (string) $filters['tipo'] );
} elseif ( isset( $filters['exclude_tipo'] ) && '' !== trim( (string) $filters['exclude_tipo'] ) ) {
    $clauses[] = 'ty.codice != %s';
    $values[] = sanitize_text_field( (string) $filters['exclude_tipo'] );
}
```

Sostituisce il blocco esistente (righe 293–296) che gestiva solo `tipo`. La precedenza `tipo` su `exclude_tipo` è garantita dall'`elseif`.

### `TitoloRepository::findPublicSchedule()` / `countPublicSchedule()`

Nessuna modifica strutturale. La paginazione numerata si traduce in:
- `limit = per_page` (o `limit` se `per_page` non impostato)
- `offset = (current_page - 1) * per_page`

Questi valori sono già gestiti dal codice esistente (righe 180–181 di `TitoloRepository.php`). Il calcolo di `current_page` e `total_pages` avviene in `ShortcodeHandler::renderProgrammazione()` e viene passato al template.

---

## 3. Modifiche a `ShortcodeHandler`

### `normalizeAttributes()` — `includes/Frontend/ShortcodeHandler.php:146`

Aggiungere al `$defaults` array:

```php
'exclude_tipo' => '',
'more_url'     => '',
'more_label'   => __( 'Vedi altro', 'cinebot-wp' ),
'pagination'   => 'ajax',
'per_page'     => 0,  // 0 = usa 'limit' come default
```

Aggiungere dopo le validazioni esistenti:

```php
if ( ! in_array( $atts['pagination'], array( 'ajax', 'numbered' ), true ) ) {
    $atts['pagination'] = 'ajax';
}
$atts['per_page'] = (int) $atts['per_page'];
if ( $atts['per_page'] <= 0 ) {
    $atts['per_page'] = $atts['limit'];
}
$atts['per_page'] = max( 1, min( 100, $atts['per_page'] ) );
$atts['more_url'] = '' !== trim( $atts['more_url'] ) ? esc_url_raw( $atts['more_url'] ) : '';
$atts['more_label'] = sanitize_text_field( $atts['more_label'] );
$atts['exclude_tipo'] = sanitize_text_field( $atts['exclude_tipo'] );
```

### `renderProgrammazione()` — `includes/Frontend/ShortcodeHandler.php:93`

Quando `pagination="numbered"`:
- Leggere `current_page` da `$_GET['cinebot_page']` (sanitize `absint`, default 1, min 1).
- Calcolare `offset = (current_page - 1) * per_page`.
- Sovrascrivere `$atts['limit'] = $atts['per_page']` e `$atts['offset'] = $offset` prima della query.
- Calcolare `total_pages = max(1, ceil($total / $per_page))`.
- Passare al template: `current_page`, `total_pages`, `base_url` (URL corrente senza `?cinebot_page=`).

Quando `more_url` è impostato:
- Passare `more_url` e `more_label` al template. Il template decide se mostrare il bottone (solo se `total > count(cards)`).

La chiave di cache transient include già `md5(wp_json_encode($atts))`, quindi le varianti con attributi diversi si cachedano separatamente. Quando `pagination="numbered"`, includere `current_page` nella chiave di cache.

### `ajaxFilter()` — `includes/Frontend/ShortcodeHandler.php:57`

Nessuna modifica. Il load-more AJAX esistente resta valido per `pagination="ajax"` (default). Quando `pagination="numbered"`, il load-more non viene renderizzato dal template, quindi l'AJAX handler non viene invocato.

---

## 4. Modifiche al template `programmazione-cards.php`

### `templates/programmazione-cards.php`

Aggiungere rami condizionali controllati dai nuovi attributi:

**Bottone "Vedi altro" (sostituisce il load-more quando `more_url` è impostato):**

```php
<?php if ( ! empty( $atts['more_url'] ) && count( $cards ) < $total ) : ?>
    <a class="cinebot-vedi-altro" href="<?php echo esc_url( $atts['more_url'] ); ?>">
        <?php echo esc_html( $atts['more_label'] ); ?>
    </a>
<?php elseif ( empty( $atts['more_url'] ) && 'ajax' === $atts['pagination'] && count( $cards ) < $total ) : ?>
    <button class="cinebot-load-more" data-instance="<?php echo esc_attr( (string) $instance ); ?>" data-page="2" data-limit="<?php echo esc_attr( (string) $atts['limit'] ); ?>">
        <?php esc_html_e( 'Carica altri', 'cinebot-wp' ); ?>
    </button>
<?php endif; ?>
```

**Paginazione numerata (sostituisce il load-more quando `pagination="numbered"`):**

```php
<?php if ( 'numbered' === $atts['pagination'] && $total_pages > 1 ) : ?>
    <nav class="cinebot-pagination" aria-label="<?php esc_attr_e( 'Navigazione pagine', 'cinebot-wp' ); ?>">
        <?php
        $base = $atts['base_url'];
        for ( $i = 1; $i <= $total_pages; $i++ ) :
            $url = $base . ( false !== strpos( $base, '?' ) ? '&' : '?' ) . 'cinebot_page=' . $i;
            $is_current = $i === $current_page;
            ?>
            <a href="<?php echo esc_url( $url ); ?>" <?php echo $is_current ? 'aria-current="page" class="cinebot-page-current"' : ''; ?>>
                <?php echo esc_html( (string) $i ); ?>
            </a>
        <?php endfor; ?>
    </nav>
<?php endif; ?>
```

Logica di esclusione reciproca:
- Se `more_url` è impostato → mostra "Vedi altro" (mai load-more né paginazione numerata).
- Se `pagination="numbered"` → mostra paginazione numerata (mai load-more né "Vedi altro" — ma `more_url` ha la precedenza).
- Altrimenti (default) → load-more AJAX esistente.

Le sezioni home (`more_url` impostato) usano tipicamente `show_filters="false"`, quindi i filtri non vengono renderizzati. Le pagine di destinazione possono avere filtri attivi se desiderato.

---

## 5. Sicurezza, performance, convenzioni

### Sicurezza

- **Prepared statements:** `exclude_tipo` usa `$wpdb->prepare()` con `%s`, come il filtro `tipo` esistente.
- **Escaping output:** `more_url` → `esc_url()` nel template; `more_label` → `esc_html()`; `current_page`/`total_pages` → `esc_attr()`/`esc_html()`.
- **Sanitizzazione input:** `more_url` → `esc_url_raw()` in `normalizeAttributes`; `exclude_tipo` → `sanitize_text_field`; `pagination` → allowlist; `per_page` → `absint` + clamp; `more_label` → `sanitize_text_field`.
- **Nonce:** non richiesto per il render server-side della paginazione numerata (link GET, nessuna mutazione). Il load-more AJAX esistente mantiene il suo nonce.
- **Visibility:** nessun cambiamento — solo eventi riconciliati attivi con `evento.stato=3` (già garantito da `public_query` riga 286).
- **Caching transient:** la chiave include già `md5(wp_json_encode($atts))`; per `pagination="numbered"` includere `current_page` nella chiave per evitare collisioni tra pagine.

### Performance

- Nessuna nuova query. `findPublicSchedule()` e `countPublicSchedule()` eseguono già con limit/offset.
- Indici DB esistenti (`tipoevento_codice` su `cinebot_titoli`, `attivo` su `cinebot_tipologie_eventi`) supportano `ty.codice != %s` in modo efficiente.

### Convenzioni (`CONVENTIONS.md`)

- Namespace `CinebotWp\`, una classe per file — rispettato.
- Hook adapter thin, SQL in repositories, business in services — `ShortcodeHandler` resta thin (delega a repository e template).
- `$wpdb->prefix . 'cinebot_'` e `$wpdb->prepare()` — rispettato.
- Solo eventi riconciliati attivi con `stato=3` — rispettato (nessun cambiamento a `public_query` per la visibility).
- TDD: test failing prima dell'implementazione.
- Quality gate: `docker compose run --rm php composer check` (WPCS + PHPStan + PHPUnit + build).

---

## 6. Testing

### Test integration (`tests/Integration/`)

**`TitoloRepositoryExcludeTipoTest`** (o estensione di test esistente):

- `findPublicSchedule(['exclude_tipo' => '01'])` ritorna eventi di tipologie attive tranne CINEMA.
- `findPublicSchedule(['exclude_tipo' => '01'])` non ritorna eventi con `tipoevento_codice = '01'`.
- `countPublicSchedule(['exclude_tipo' => '01'])` è coerente con `findPublicSchedule`.
- `findPublicSchedule(['tipo' => '01', 'exclude_tipo' => '45'])` ignora `exclude_tipo` e filtra solo per `tipo = '01'` (precedenza).
- `findPublicSchedule(['exclude_tipo' => '01'])` include solo eventi con `stato=3` e gerarchia attiva (regression del visibility).

**`ShortcodeMoreUrlTest`** (o estensione di test esistente):

- `renderProgrammazione(['tipo' => '01', 'limit' => 4, 'more_url' => '/x'])` renderizza `<a class="cinebot-vedi-altro" href="/x">` quando `total > 4`.
- `renderProgrammazione(['tipo' => '01', 'limit' => 4, 'more_url' => '/x'])` non renderizza il bottone "Carica altri".
- `renderProgrammazione(['tipo' => '01', 'limit' => 100, 'more_url' => '/x'])` non renderizza "Vedi altro" se `total <= count(cards)` (nessun risultato aggiuntivo).

**`ShortcodeNumberedPaginationTest`:**

- `renderProgrammazione(['tipo' => '01', 'limit' => 2, 'pagination' => 'numbered', 'per_page' => 2])` con 5 risultati totali renderizza 3 link di paginazione.
- `?cinebot_page=2` ritorna la seconda pagina (offset corretto).
- `pagination="numbered"` non renderizza il load-more.
- `pagination="numbered"` + `more_url` impostato → prevale `more_url` (paginazione numerata nascosta).

### Test di regression

- `renderProgrammazione(['tipo' => '01'])` (senza nuovi attributi) mantiene il comportamento 1.0: load-more AJAX, nessun "Vedi altro", nessuna paginazione numerata.

---

## 7. File coinvolti

| File | Modifica |
|---|---|
| `includes/Frontend/ShortcodeHandler.php` | Estendere `normalizeAttributes()` e `renderProgrammazione()` con nuovi attributi e logica paginazione numerata. |
| `includes/Repositories/TitoloRepository.php` | Modificare `public_query()` per `exclude_tipo` (ramo `elseif`). |
| `templates/programmazione-cards.php` | Aggiungere rami condizionali per "Vedi altro" e paginazione numerata. |
| `tests/Integration/ShortcodeHandlerTest.php` (o nuovo file) | Test `more_url`, `exclude_tipo`, `pagination="numbered"`. |
| `tests/Integration/TitoloRepositoryTest.php` (o nuovo file) | Test `exclude_tipo`, precedenza `tipo`. |

Nessun nuovo file di codice produzione. Nessun nuovo modello, repository o service. Nessuna migrazione DB.

---

## 8. Fuori scope

- Rewrite rules permalink (`/programmazione-cinema/page/2` style) — si usa `?cinebot_page=N`.
- Pagine virtuali generate dal plugin.
- React/Gutenberg blocks.
- Filtri per multiple tipologie (`tipo="01,45,53"`) — solo singolo codice inclusione/esclusione.
- Write-back API, Multisite, retry automatici.
- Nuovi template dedicati per la home — si riusa `programmazione-cards.php`.

Qualsiasi requisito futuro che esca da questi confini richiede un nuovo design approvato, come da `CONVENTIONS.md` e `AGENTS.md`.

---

## 9. Riepilogo decisioni

| Decisione | Scelta |
|---|---|
| Pagina di destinazione "Vedi altro" | Pagina WP reale creata dall'utente con shortcode |
| Paginazione su pagina di destinazione | Entrambe (load-more AJAX default, `pagination="numbered"` opzionale) |
| Sezione "altre tipologie" | `exclude_tipo` dinamico (tutte tranne CINEMA) |
| Render sezioni home | Estendere shortcode esistente con `more_url` |
| CINEMA | Codice `"01"` (appendice A design 1.0) |
| `tipo` + `exclude_tipo` simultanei | `tipo` prevale, `exclude_tipo` ignorato |
| Paginazione numerata URL | `?cinebot_page=N` senza permalink rewrite |
