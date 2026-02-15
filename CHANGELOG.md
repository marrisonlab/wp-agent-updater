# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.18] - 2026-02-15
### Changed
- Migliorato `gather_site_data` per includere anche plugin con update senza package.
- Allineati i dati inviati al Master per segnalare correttamente gli update bloccati.
### Fixed
- Assicurata la segnalazione degli aggiornamenti anche per plugin inattivi.

## [1.0.17] - 2026-02-14
### Changed
- Aggiunto conteggio traduzioni installate nel risultato di update.
- Sync invia `can_update` per distinguere update bloccati (senza package).
- Report dettagliato: `skipped_no_package` e `failed` per plugin.
### Fixed
- Rimossi `catch Throwable` per compatibilità con PHP vecchi (evita fatal).
- Migliorata affidabilità dell’upgrade manuale con backup/rollback e logging.

## [1.0.16] - 2026-02-14
### Changed
- Report dettagliato verso il Master: numero di plugin aggiornati.
- Migliorata resilienza degli aggiornamenti con fallback su upgrade singoli.
### Fixed
- Corretta logica di aggiornamento per evitare “successo” senza modifiche effettive.

## [1.0.15] - 2026-02-14
### Changed
- Allineamento versione post-rollback; aggiornati update.json e metadata.
### Fixed
- Stabilità routine di aggiornamento confermata post-rollback.

## [1.0.14] - 2026-02-14
### Fixed
- Evitati fallimenti silenziosi forzando `FS_METHOD=direct` e inizializzando `WP_Filesystem` nella routine di aggiornamento.
- Maggiore affidabilità degli update via REST per plugin/temi.

## [1.0.13] - 2026-02-13
### Changed
- Optimized sync performance: implemented soft cache clearing.
- Removed artificial sleep delays in update routines.
- Reduced external API calls during update checks.

## [1.0.12] - 2026-02-13

### Changed
- Updated Settings page layout to match Master plugin style (3 columns, card styling).
- Fixed styling issues (switches, alignment).
- Added standardized header to admin pages.

## [1.0.11] - 2026-02-13

### Changed
- Minor bug fixes and improvements.

## [1.0.10] - 2026-02-13

### Changed
- Renamed admin menu item to "WP Agent" for consistency.

## [1.0.9] - 2026-02-12

### Changed
- Updated sidebar menu icon with custom SVG.
- Improved icon styling to match WordPress admin interface.

## [1.0.8] - 2026-02-12

### Fixed
- Fixed fatal PHP error caused by undefined constant during update system initialization.
- Optimized GitHub Updater initialization in the main plugin file.

## [1.0.7] - 2026-02-12

### Fixed
- Rewrote GitHub update mechanism using remote JSON file.
- Added cache management for update requests.
- Implemented forced update check with button in plugin list.
- Added automatic plugin folder correction during update.

## [1.0.6] - 2026-02-12

### Maintenance
- Maintenance version update.

## [1.0.5] - 2026-02-12

### Maintenance
- Maintenance version update.

## [1.0.4] - 2026-02-12

### Maintenance
- Maintenance version update.

## [1.0.3] - 2026-02-12

### Fixed
- Moved GitHub Updater initialization to `plugins_loaded` to ensure background operation.
- Fixed update detection issue to correctly include plugin slug.
- Added support for displaying "Enable auto-updates" link in plugin list.
- Improved update response object with icons and banners.
- Fixed issue with update details popup.

## [1.0.2] - 2026-02-12

### Changed
- Fixed push sync endpoint to Master (from `marrison-master` to `wp-master-updater`).
- Updated API documentation in README to reflect correct endpoints.

## [1.0.1] - 2026-02-12

### Fixed
- Fixed settings page visibility issue.
- Added link to settings in the plugin list.

## [1.0.0] - 2024-02-12

### Added
- Initial version of the Marrison Agent plugin
- Secure communication with Marrison Master via REST API
- Automatic backup system before updates
- Support for public and private repositories
- API endpoints for data synchronization
- API endpoints for plugin/theme/translation updates
- API endpoints for backup management (create, list, restore)
- Cache system for optimal performance
- Administrative interface for configuration
- Operation logs for debug and audit
- Auto-detection and initial configuration
- Support for WordPress Multisite
- Error handling with detailed notifications
- Data sanitization and validation
- Access control based on WordPress roles
- Automatic repository cache cleaning
- Health check system to monitor status

### Changed
- Optimized backup query performance
- Improved memory management for large sites
- Optimized cache system
- Improved network error handling

### Fixed
- Fixed timeout issue during long operations
- Fixed backup management on sites with many plugins
- Fixed compatibility issues with some caching plugins
- Fixed permission issue on some server configurations
- Fixed various compatibility bugs with different WordPress versions

### Security
- Implemented robust authentication via nonces
- Added strict validation of all API parameters
- Implemented rate limiting to prevent abuse
- Added detailed access control
- Implemented encryption for sensitive data in backups
