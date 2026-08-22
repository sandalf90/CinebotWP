# Introduzione

**Cinebot WP** è un plugin per WordPress 6+ progettato per importare automaticamente la programmazione (titoli, eventi, prezzi, settori) dall'API di Cinebot all'interno del database di WordPress.

## Obiettivo del plugin

Il plugin si occupa di gestire un database relazionale personalizzato su WordPress, importando i dati in sola lettura e mettendoli a disposizione tramite un'interfaccia di amministrazione nativa e vari shortcode frontend.

## Architettura e Funzionalità Principali

- **Tabelle Personalizzate**: Crea tabelle dedicate nel database di WordPress per Titoli, Eventi, Prezzi, Locali e Tipologie in modo da garantire alta velocità ed efficienza rispetto ai Custom Post Type tradizionali.
- **Sincronizzazione in Sola Lettura**: I dati provengono dall'API di Cinebot e vengono inseriti in WordPress; non c'è scrittura inversa verso l'API.
- **Riconciliazione (Soft Delete)**: Gli eventi o titoli che scompaiono dall'API non vengono eliminati, ma disattivati. Se ricompaiono, verranno riattivati, mantenendo la coerenza dei dati.
- **WP-Cron e Task in Background**: Usa l'architettura Cron di WordPress per recuperare gli aggiornamenti a intervalli regolari.
- **Sistema di Template Frontend**: Usa una serie di Shortcode per presentare la programmazione e i dettagli del titolo all'utente.
