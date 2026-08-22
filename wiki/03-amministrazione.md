# Amministrazione

L'area di amministrazione di Cinebot WP è integrata in modo nativo nel pannello di WordPress e si compone di varie sezioni, navigabili dal sottomenu **Cinebot**.

## Sezioni

1. **Dashboard**
   Pannello riassuntivo che mostra lo stato della sincronizzazione, i contatori degli elementi in database (quanti titoli attivi, eventi, ecc.), eventuali allarmi e link rapidi alle funzionalità più utilizzate.
   
2. **API (Impostazioni)**
   Questa pagina consente di inserire le credenziali (Client ID, Secret, ecc.) crittografate per connettersi all'infrastruttura Cinebot. Consente inoltre di impostare la frequenza di sincronizzazione (il cron) e offre bottoni per testare la connessione o lanciare la sincronizzazione istantanea.

3. **Programmazioni**
   L'interfaccia CRUD per la gestione completa dei Titoli importati (e/o creati manualmente). Mostra la struttura ad albero: un Titolo possiede molteplici Eventi; un Evento ha più Settori e Prezzi.

4. **Locali**
   Una sezione CRUD dedicata alle sale o ai luoghi (venue) in cui avvengono gli eventi.

5. **Tipologie Evento**
   Gestione dei tipi di spettacolo/evento (ad esempio, Cinema, Teatro Prosa, Concerto). Il sistema possiede 62 tipologie predefinite, ma l'amministratore ha la facoltà di crearne di personalizzate.

6. **Log Sincronizzazioni**
   Tabella che conserva lo storico di ogni run di importazione dal sistema Cinebot, molto utile per effettuare troubleshooting o monitorare gli aggiornamenti di sistema.
