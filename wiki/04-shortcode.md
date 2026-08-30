# Shortcode Frontend

Cinebot WP mette a disposizione diversi shortcode per rendere visibile la programmazione e i dettagli degli spettacoli sul lato pubblico del sito WordPress. 
Gli shortcode, elaborati da un gestore dedicato, si appoggiano a un sistema di cache interno (Transients) che viene automaticamente svuotato al momento dell'aggiornamento dei file di template.

## `[cinebot_programmazione]`

Questo shortcode è il principale del plugin e genera l'elenco (la "griglia") degli spettacoli futuri in programmazione, sotto forma di **Card**. Offre supporto a una form di filtri (basati su AJAX) e alla paginazione.

### Attributi supportati

*Tutti gli attributi sono facoltativi. Puoi usarli per costruire griglie altamente pre-filtrate o personalizzate.*

- **`tipo`** (stringa): ID della tipologia di evento (es. `"45"` per "Teatro Prosa"). Mostra solo gli eventi di quella tipologia.
- **`exclude_tipo`** (stringa): ID o lista di tipologie da escludere (separati da virgola, se supportato dal core).
- **`locale`** (intero): ID del locale; se indicato, verranno estratti solo i titoli con spettacoli in tale locale.
- **`from`** (stringa, YYYY-MM-DD): Data d'inizio per filtrare la visualizzazione (default: la data odierna).
- **`to`** (stringa, YYYY-MM-DD): Data finale limite.
- **`limit`** (intero): Il numero massimo totale di risultati ammessi dalla query. Il valore deve essere compreso fra 1 e 100. (default: `50`).
- **`orderby`** (stringa): Il campo su cui ordinare i titoli in output. Valori consentiti: `"inizio"` o `"titolo"` (default: `"inizio"`).
- **`order`** (stringa): Direzione dell'ordinamento. Valori consentiti: `"ASC"` o `"DESC"` (default: `"ASC"`).
- **`show_filters`** (booleano): Mostra in cima alla lista un modulo di filtraggio per l'utente, gestito in AJAX (default: `true`).
- **`show_desc`** (booleano): Mostra l'anteprima della descrizione o i dati addizionali all'interno della singola card (default: `false`).
- **`layout`** (stringa): La variante di template per le card (default: `"cards"`).
- **`offset`** (intero): Scarto dei risultati iniziale, utile se gestisci liste manuali in sequenza (default: `0`).
- **`more_url`** (stringa): URL della pagina a cui farà link il pulsante di espansione (vedi altro), se specificato sostituisce il comportamento asincrono con un link a una pagina.
- **`more_label`** (stringa): Il testo da mostrare nel pulsante "Vedi Altro" o di caricamento AJAX (default: `"Vedi altro"`).
- **`detail_url`** (stringa): URL relativo (o assoluto) a cui andrà a puntare la singola Card per visualizzare i dettagli (es. `"/dettaglio-film/"`). 
- **`detail_page_id`** (intero): Un ID numerico di un Post/Pagina WordPress da usare per la navigazione dei dettagli. *Nota bene: se usato, ha la precedenza sull'attributo `detail_url` e calcola dinamicamente la permalink corretta di WP.*
- **`pagination`** (stringa): Tipologia di paginazione. Valori consentiti: `"ajax"` o `"numbered"` (default: `"ajax"`).
- **`per_page`** (intero): In caso di paginazione, stabilisce il numero di card da far apparire in ciascuna pagina; se omesso ricalca il valore indicato nel parametro `limit`.

---

## `[cinebot_titolo]`

Questo shortcode renderizza **tutto il template di dettaglio** di un singolo Titolo (o evento aggregato). Richiama al suo interno la locandina, la descrizione, le date disponibili e la tabella dei prezzi/orari d'acquisto in un blocco unitario.

### Attributi supportati

- **`id`** (intero): L'ID univoco del Titolo presente nel DB Cinebot. 
  *Nota: se l'attributo `id` non viene esplicitamente passato (es. all'interno di una pagina usata come template per il dettaglio), il plugin proverà automaticamente a ricavarlo dalla Query Variable di WordPress (`titolo_id`) o dal parametro URL GET (`?titolo_id=...`).*

---

## Shortcode per Layout di Dettaglio Scomposto (Micro-Shortcode)

Spesso, potresti voler disegnare una pagina di dettaglio per il singolo evento su misura, posizionando le informazioni esatte dove vuoi sul page builder o nel tema. 
In tal caso puoi non usare `[cinebot_titolo]` globale, ma "spacchettare" i dati coi seguenti shortcode atomici. 
*Tutti questi shortcode accettano opzionalmente l'attributo **`id`** per forzare il recupero del dato di un determinato evento (altrimenti estraggono l'id automaticamente dall'URL, vedi sopra).*

- **`[cinebot_titolo_titolo]`**: Restituisce puramente la stringa col titolo dell'evento.
- **`[cinebot_titolo_autore]`**: Restituisce il nome dell'autore o creatore.
- **`[cinebot_titolo_esecutore]`**: Restituisce il nome del cast, del regista o dell'artista/compagnia che esegue lo spettacolo.
- **`[cinebot_titolo_giorno]`**: Restituisce una stringa discorsiva delle date (Giorno della settimana, Giorno del mese/Mese). Se l'evento è su più date, restituirà un range (es: *Da Giovedì 18/10 a Domenica 20/10*).
- **`[cinebot_titolo_durata]`**: Restituisce in maniera intelligente l'orario o la durata. Se l'evento è composto da una sola replica, mostra le fasce (es: *dalle 21:00 alle 23:00*). Se ci sono più repliche e una durata globale, mostra i minuti (es: *120 minuti*).
- **`[cinebot_titolo_prezzo]`**: Calcola in modo dinamico la stringa dei prezzi scorporando eventuali commissioni o diritti di prevendita, per mostrare il prezzo facciale (es: *€ 20.00 + d.d.p.*). Gestisce in automatico anche i range quando vi sono biglietti di prezzi diversi (*Da € 25.00 a € 35.00 +d.d.p.*).
- **`[cinebot_titolo_locale]`**: Stampa il nome del/dei locali in cui andrà in scena lo spettacolo.
- **`[cinebot_titolo_descrizione]`**: Inietta l'HTML rich-text formattato con la descrizione estesa (o sinossi). Passato in automatico a `wp_kses_post()` per la sicurezza.
- **`[cinebot_titolo_immagine]`**: Costruisce un intero tag HTML `<img>` per inserire la locandina/poster, estraendone l'URL dai dati API. 
  **Attributi specifici addizionali**:
  - `class`: Specifica la/le classi CSS del tag img (default: `"cinebot-immagine"`).
  - `alt`: Specifica il testo alternativo per accessibilità (default: stampa il nome del titolo).
- **`[cinebot_titolo_eventi]`**: Disegna una tabella riepilogativa di tutte le repliche specifiche del titolo. La tabella è formata dalle colonne: Giorno formattato, Ora, Nome del Locale e il pulsante per il Link d'acquisto esterno all'e-commerce (con target="_blank"). 
