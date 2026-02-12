# Changelog

Tutti i cambiamenti significativi a questo progetto saranno documentati in questo file.

Il formato è basato su [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
e questo progetto aderisce a [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.6] - 2026-02-12

### Manutenzione
- Aggiornamento versione di manutenzione.

## [1.0.5] - 2026-02-12

### Manutenzione
- Aggiornamento versione di manutenzione.

## [1.0.4] - 2026-02-12

### Manutenzione
- Aggiornamento versione di manutenzione.

## [1.0.3] - 2026-02-12

### Corretto
- Spostata inizializzazione GitHub Updater su `plugins_loaded` per garantire il funzionamento in background.
- Risolto problema di rilevamento degli aggiornamenti per includere correttamente lo slug del plugin.
- Aggiunto supporto per la visualizzazione del link "Abilita aggiornamento automatico" nella lista plugin.
- Migliorato oggetto di risposta dell'aggiornamento con icone e banner.
- Risolto problema con il popup dei dettagli dell'aggiornamento.

## [1.0.2] - 2026-02-12

### Modificato
- Corretto endpoint di push sync verso il Master (da `marrison-master` a `wp-master-updater`).
- Aggiornata documentazione API nel README per riflettere gli endpoint corretti.

## [1.0.1] - 2026-02-12

### Corretto
- Risolto problema di visibilità della pagina delle impostazioni.
- Aggiunto link alle impostazioni nella lista dei plugin.

## [1.0.0] - 2024-02-12

### Aggiunto
- Versione iniziale del plugin Marrison Agent
- Comunicazione sicura con Marrison Master tramite API REST
- Sistema di backup automatico prima degli aggiornamenti
- Supporto per repository pubblici e privati
- Endpoint API per sincronizzazione dati
- Endpoint API per aggiornamento plugin/temi/traduzioni
- Endpoint API per gestione backup (crea, lista, ripristina)
- Sistema di cache per prestazioni ottimali
- Interfaccia amministrativa per configurazione
- Log delle operazioni per debug e audit
- Auto-rilevamento e configurazione iniziale
- Supporto per WordPress Multisite
- Gestione degli errori con notifiche dettagliate
- Sanitizzazione e validazione dei dati
- Controllo degli accessi basato sui ruoli WordPress
- Pulizia automatica della cache repository
- Sistema di health check per monitorare lo stato

### Modificato
- Ottimizzate le performance delle query di backup
- Migliorata la gestione della memoria per siti grandi
- Ottimizzato il sistema di cache
- Migliorata la gestione degli errori di rete

### Corretto
- Risolto problema di timeout durante operazioni lunghe
- Corretto gestione backup su siti con molti plugin
- Risolti problemi di compatibilità con alcuni plugin di caching
- Corretto problema di permessi su alcune configurazioni server
- Risolti vari bug di compatibilità con versioni diverse di WordPress

### Sicurezza
- Implementata autenticazione robusta tramite nonce
- Aggiunta validazione rigorosa di tutti i parametri API
- Implementato rate limiting per prevenire abusi
- Aggiunto controllo degli accessi dettagliato
- Implementata cifratura per dati sensibili nei backup

## [Pre-1.0.0] - Fasi di sviluppo iniziali

Le versioni precedenti alla 1.0.0 erano fasi di sviluppo e test interno.

---

## Come aggiornare

Per aggiornare il plugin:

1. **Backup**: Crea sempre un backup completo del tuo sito prima di aggiornare
2. **Download**: Scarica la nuova versione dal [repository GitHub](https://github.com/marrisonlab/wp-agent-updater)
3. **Installazione**: Sostituisci i file del plugin con la nuova versione
4. **Test**: Verifica che tutto funzioni correttamente
5. **Configurazione**: Ricontrolla le impostazioni di connessione al master

### Procedura consigliata

1. **Test in staging**: Testa sempre l'aggiornamento in un ambiente di staging prima di applicarlo in produzione
2. **Monitora i log**: Controlla i log dell'agent dopo l'aggiornamento
3. **Verifica la connessione**: Assicurati che la connessione con il master funzioni correttamente
4. **Testa i backup**: Verifica che il sistema di backup funzioni ancora correttamente

## Segnalazione problemi

Se incontri problemi con questo plugin:

1. Verifica di avere la versione più recente
2. Controlla i requisiti di sistema
3. Consulta la [documentazione](README.md)
4. Controlla i log di WordPress e dell'agent
5. Apri una [issue su GitHub](https://github.com/marrisonlab/marrison-agent/issues)

### Informazioni utili per la segnalazione

Quando segnali un problema, includi:
- Versione di WordPress
- Versione di PHP
- Versione del plugin
- Messaggi di errore completi
- Passaggi per riprodurre il problema
- Log rilevanti (rimuovi informazioni sensibili)

---

**Autore**: Angelo Marra  
**Sito**: [marrisonlab.com](https://marrisonlab.com)  
**Repository**: [marrisonlab/marrison-agent](https://github.com/marrisonlab/marrison-agent)