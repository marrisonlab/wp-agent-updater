# WP Agent Updater

WP Agent Updater è il componente client del sistema di gestione remota di WordPress. Questo plugin si collega al Master Server per consentire il controllo remoto e gli aggiornamenti centralizzati.

## Caratteristiche

- **Comunicazione Sicura**: Connessione sicura con il Master Server
- **Aggiornamenti Automatici**: Ricevi e applica aggiornamenti da remoto
- **Sistema di Backup**: Crea backup automatici prima degli aggiornamenti
- **Gestione Repository**: Supporta repository pubblici e privati
- **API REST**: Endpoint API per comunicazione con il master
- **Cache Ottimizzata**: Sistema di cache per prestazioni ottimali
- **Sicurezza Avanzata**: Autenticazione e autorizzazione robuste

## Installazione

1. Scarica l'ultima versione dal [repository GitHub](https://github.com/marrisonlab/wp-agent-updater)
2. Carica il plugin nella directory `/wp-content/plugins/` del tuo sito WordPress
3. Attiva il plugin tramite il pannello di amministrazione WordPress
4. Configura le impostazioni di connessione al master

## Requisiti

- WordPress 5.0 o superiore
- PHP 7.0 o superiore
- Connessione internet per comunicare con il master
- Plugin Master installato sul server master

## Configurazione

### Impostazioni di Base

1. Vai a "Impostazioni" → "WP Agent Updater"
2. Inserisci l'URL del Master Server
3. Configura le opzioni di backup e aggiornamento
4. Salva le modifiche

### Sicurezza

- Utilizza sempre connessioni HTTPS
- Configura chiavi di autenticazione forti
- Limita l'accesso alle funzionalità di amministrazione

## Utilizzo

### Dashboard Agent

La dashboard mostra:
- Stato di connessione con il master
- Informazioni sul sito e sulla versione
- Log delle ultime operazioni
- Opzioni di configurazione

### Endpoint API

L'agent espone diversi endpoint API REST:

- `/wp-json/wp-agent-updater/v1/info` - Informazioni sul client
- `/wp-json/wp-agent-updater/v1/sync` - Sincronizzazione dati
- `/wp-json/wp-agent-updater/v1/update` - Aggiornamento plugin/temi
- `/wp-json/wp-agent-updater/v1/backup` - Gestione backup
- `/wp-json/wp-agent-updater/v1/restore` - Ripristino backup
- `/wp-json/wp-agent-updater/v1/clear-repo-cache` - Pulizia cache repository

### Backup Automatici

L'agent crea automaticamente backup prima di:
- Aggiornamenti di plugin
- Aggiornamenti di temi
- Aggiornamenti di WordPress
- Operazioni di manutenzione

## Sicurezza

- Autenticazione basata su nonce WordPress
- Controllo degli accessi per ruoli utente
- Validazione rigorosa dei dati in entrata
- Sanitizzazione di tutti i dati di output
- Log delle operazioni per audit

## Supporto

Per supporto e documentazione aggiuntiva:
- [Repository GitHub](https://github.com/marrisonlab/wp-agent-updater)
- [Issue Tracker](https://github.com/marrisonlab/wp-agent-updater/issues)
- Visita [marrisonlab.com](https://marrisonlab.com)

## Sviluppo

Questo plugin è open source e contribuzioni sono benvenute!

### Installazione per Sviluppo

1. Clona il repository: `git clone https://github.com/marrisonlab/wp-agent-updater.git`
2. Attiva il plugin nel tuo ambiente di sviluppo WordPress
3. Contribuisci seguendo le linee guida standard di WordPress

### Struttura del Codice

- `includes/core.php` - Core functionality
