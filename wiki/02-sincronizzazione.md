# Sincronizzazione Dati

La funzionalità centrale di Cinebot WP è il meccanismo di sincronizzazione con l'API esterna. Questo processo si occupa di mantenere aggiornato il catalogo degli eventi sul sito WordPress.

## Come funziona

1. **Recupero dati (Fetch)**: Il plugin interroga gli endpoint API configurati utilizzando le credenziali crittografate salvate nelle impostazioni (AES-256-CBC con HMAC).
2. **Upsert Atomico**: I dati (Titoli, Eventi, Prezzi, Locali e Tipologie) vengono scritti all'interno del DB di WordPress avvalendosi di transazioni InnoDB per garantire coerenza dei dati (atomic transaction).
3. **Log Sincronizzazioni**: Tutti gli esiti della sincronizzazione vengono registrati nell'apposita sezione "Log sincronizzazioni" nel pannello di amministrazione, conservando lo storico per oltre 30 giorni.

## Esecuzione della Sincronizzazione

- **Automatica tramite Cron**: Nelle impostazioni è possibile definire la frequenza di esecuzione (oraria, giornaliera, settimanale). Il processo si innesca grazie al WP-Cron integrato in WordPress. Poiché WP-Cron è basato sul traffico del sito (traffic-driven), per avere sincronizzazioni esatte si raccomanda un cron-job lato server che attivi regolarmente `wp-cron.php`.
- **Manuale**: All'interno del menu **Cinebot -> API**, è possibile forzare una sincronizzazione cliccando il pulsante per il sync istantaneo.

## Proprietà dei dati (Ownership)

- **Source API vs Source Manual**: Il plugin tiene traccia della provenienza di un record (`source=api` oppure `source=manual`). I dati creati manualmente da un amministratore in WordPress non vengono sovrascritti o alterati durante la sincronizzazione con l'API. 
- Solo gli eventi con stato API `3` vengono considerati come visibili pubblicamente.
