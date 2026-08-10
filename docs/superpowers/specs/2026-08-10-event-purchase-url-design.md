# URL di acquisto degli eventi importati

**Data:** 2026-08-10  
**Stato:** approvato  
**Ambito:** sincronizzazione, persistenza eventi e upgrade dello schema

## Obiettivo

Durante l'importazione delle programmazioni Cinebot, il plugin deve generare per ogni evento API un URL di acquisto HTTPS con il pattern:

```text
https://{host}/{path}/evento/{idevento}/acquista
```

Per la fixture approvata, l'evento remoto `2920` deve produrre:

```text
https://ticket.cinebot.it/martinovich/evento/2920/acquista
```

Il plugin salva il valore nel nuovo campo `url_acquisto` della tabella `cinebot_eventi`. In questa modifica il campo non viene esposto nel frontend e non viene reso modificabile nell'area amministrativa.

## Decisioni

- Il nome persistito e' `url_acquisto`; nel DTO PHP la proprieta' e' `urlAcquisto`.
- La colonna e' `VARCHAR(500) NULL`.
- Gli eventi `source=api` ricevono sempre un URL generato durante una sincronizzazione riuscita.
- Gli eventi manuali nuovi lasciano il campo a `NULL`. Un eventuale input manuale verra' progettato separatamente.
- Gli eventi API gia' presenti vengono valorizzati alla prima sincronizzazione successiva all'upgrade.
- L'upgrade dello schema e' automatico e non avvia una sincronizzazione ne' altre chiamate di rete.
- `host`, `path` o `idevento` mancanti o non validi fanno fallire l'intera sincronizzazione con rollback.
- L'URL viene soltanto memorizzato; pulsanti e link pubblici sono fuori scope.

## Architettura

### Servizio URL Cinebot

L'attuale `LocandinaService` viene sostituito internamente da `CinebotUrlService`, responsabile della costruzione sicura e deterministica degli URL Cinebot. Espone due operazioni:

```php
buildLocandina(string $host, string $path, int $titleId, int $flag): ?string
buildAcquisto(string $host, string $path, int $eventId): string
```

`buildLocandina()` conserva il comportamento esistente. `buildAcquisto()` restituisce sempre una stringa valida oppure solleva `InvalidArgumentException`.

Entrambe le operazioni condividono le stesse regole per la base URL:

- normalizzazione dell'host in minuscolo;
- accettazione di soli hostname DNS, senza schema, porta, credenziali, IP o `localhost`;
- rimozione degli slash iniziali e finali dal path;
- validazione e `rawurlencode()` indipendente di ogni segmento;
- rifiuto di segmenti vuoti, traversal, backslash, query, fragment, schemi e caratteri di controllo;
- rifiuto dell'URL finale quando supera 500 byte;
- errori generici che non riflettono il contenuto ricevuto.

Il servizio mantiene un solo punto di validazione per locandine e acquisti. Non vengono duplicate regole di sicurezza in `SyncService`.

### Flusso di sincronizzazione

`SyncService` riceve `CinebotUrlService` tramite dependency injection. Per ogni envelope di programmazione, `host` e `path` vengono propagati fino alla sincronizzazione dei relativi eventi.

Per ciascun evento:

1. `idevento` viene validato come intero positivo.
2. `CinebotUrlService::buildAcquisto()` genera l'URL usando `host`, `path` e `idevento` remoti.
3. Il valore viene assegnato a `Evento::$urlAcquisto`.
4. `EventoRepository::save()` persiste il valore nella stessa transazione della gerarchia.
5. `event_changed()` confronta anche `urlAcquisto`, quindi un cambio di `host` o `path` aggiorna l'evento e incrementa `eventi_updated`.

La sincronizzazione continua a non modificare eventi `source=manual`. La modifica di un evento API dall'editor amministrativo deve copiare dal record memorizzato `urlAcquisto`, insieme agli altri campi di ownership e riconciliazione, per evitare di cancellarlo durante un salvataggio che non espone il campo.

## Modello E Persistenza

La tabella `{prefix}cinebot_eventi` aggiunge:

| Campo | Tipo | Note |
|---|---|---|
| `url_acquisto` | `VARCHAR(500) NULL` | URL Cinebot generato per gli eventi API; `NULL` per eventi manuali non configurati |

`Evento` aggiunge `public ?string $urlAcquisto = null` e include il campo in `fromArray()` e `toArray()`. `EventoRepository::save()` include `url_acquisto` nei dati e nei formati di insert e update.

Non e' necessario un indice: il campo non viene usato per ricerca, ordinamento o join.

## Upgrade Automatico

La versione DB dello schema diventa `1.1.0`. Prima di comporre servizi e repository, il bootstrap confronta `cinebot_wp_db_version` con questa versione.

- Se la versione memorizzata e' uguale o successiva a `1.1.0`, non esegue query di migrazione e non tenta downgrade.
- Se la versione e' precedente o l'opzione non esiste, richiama l'installer idempotente basato su `dbDelta()`.
- L'opzione versione viene aggiornata solo dopo che schema e seeding sono terminati con successo.
- Un errore lascia invariata la versione precedente, interrompe soltanto il boot delle funzionalita' Cinebot dipendenti dallo schema e registra un avviso amministrativo sicuro. WordPress continua a rispondere e il plugin ritenta l'upgrade al caricamento successivo.
- La migrazione conserva tutte le righe e aggiunge `url_acquisto` con valore iniziale `NULL`.
- La migrazione non avvia una sincronizzazione. La sincronizzazione manuale o pianificata successiva esegue il backfill naturale usando i dati correnti dell'API.

L'installazione su un sito nuovo e l'upgrade di un sito esistente percorrono lo stesso installer idempotente.

## Error Handling

L'URL di acquisto e' un'invariante degli eventi API importati. Se `host`, `path` o `idevento` non permettono di generare un URL sicuro:

- il servizio URL solleva un'eccezione con messaggio non sensibile;
- `SyncService` annulla la transazione;
- nessun titolo, evento, locale, settore o prezzo parziale viene confermato;
- il risultato e il log espongono soltanto il messaggio sicuro gia' previsto, `Schedule synchronization failed.`;
- le cache pubbliche vengono mantenute, come per gli altri fallimenti antecedenti al commit.

Non sono previsti import parziali, fallback a HTTP o URL costruiti dal dominio WordPress.

## Verifica TDD

### Servizio URL

- Costruzione esatta dell'URL di acquisto campione.
- Normalizzazione di host e slash del path.
- Encoding indipendente dei segmenti sicuri.
- Determinismo a parita' di input.
- Rifiuto di ID non positivi, host e path ostili e URL oltre 500 byte.
- Assenza di input sensibili nei messaggi di errore.
- Regressione completa della generazione locandine esistente.

### Modello E Repository

- `Evento::fromArray()` e `toArray()` mappano `url_acquisto` senza mutare l'input.
- Il valore predefinito e' `NULL`.
- Insert, update e lettura repository conservano il valore esatto.
- Gli eventi manuali possono restare `NULL`.

### Schema E Upgrade

- Nuove installazioni contengono la colonna nullable della lunghezza prevista.
- Un database alla versione precedente viene aggiornato automaticamente.
- Le righe preesistenti restano invariate e ricevono `NULL` nella nuova colonna.
- La versione DB cambia soltanto dopo un upgrade riuscito.
- Un upgrade fallito rimane ritentabile, non avvia servizi su uno schema incompleto e non interrompe WordPress.
- Un bootstrap con versione `1.1.0` o successiva non riesegue l'installer.

### Sincronizzazione E Admin

- La fixture salva `https://ticket.cinebot.it/martinovich/evento/2920/acquista` per `idevento=2920`.
- Una seconda importazione identica e' idempotente.
- Un cambio di `host` o `path` aggiorna l'URL e incrementa `eventi_updated`.
- Input URL invalido provoca rollback completo e messaggi sicuri.
- La modifica admin di un evento API preserva `url_acquisto`.
- Gli eventi manuali non vengono valorizzati o sovrascritti dalla sincronizzazione.

La verifica finale esegue PHPUnit, WPCS, PHPStan e il build distributivo. Non vengono aggiunti test di rendering frontend perche' il campo non viene ancora proiettato nei read model pubblici.

## Criteri Di Accettazione

1. Dopo una sincronizzazione riuscita, ogni evento API importato contiene `url_acquisto` nel pattern approvato.
2. L'URL usa esclusivamente `host`, `path` e `idevento` del relativo envelope/evento API.
3. Nessun evento API viene confermato con URL mancante o non sicuro.
4. Gli eventi manuali restano protetti dalla sincronizzazione e il campo rimane disponibile per una futura gestione manuale.
5. I siti esistenti ricevono automaticamente la nuova colonna senza perdita di dati e senza sincronizzazione forzata.
6. Il campo non compare ancora nel frontend o nei form amministrativi.
7. Tutti i gate di qualita' del progetto risultano verdi.

## Fuori Scope

- Pulsante o link "Acquista" nelle card pubbliche.
- Esposizione di `url_acquisto` nel read model `ProgrammazioneCard`.
- Modifica manuale del campo nell'editor delle programmazioni.
- Generazione di URL per eventi manuali.
- Backfill senza una nuova risposta API.
- Retry di rete, import parziali o fallback di URL.

## Alternative Considerate

### Servizi separati con builder condiviso

Mantenere `LocandinaService`, aggiungere `AcquistoUrlService` ed estrarre un terzo builder comune avrebbe confini molto specifici, ma introdurrebbe tre componenti per due pattern URL senza un beneficio proporzionato.

### Generazione diretta in SyncService

La concatenazione inline avrebbe richiesto meno modifiche, ma avrebbe duplicato o aggirato la validazione di sicurezza esistente e legato i dettagli degli endpoint al servizio di orchestrazione. E' stata esclusa.
