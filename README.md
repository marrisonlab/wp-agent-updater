# WP Agent Updater

WP Agent Updater is the client component of the remote WordPress management system. This plugin connects to the Master Server to allow remote control and centralized updates.

## Features

- **Secure Communication**: Secure connection with the Master Server
- **Automatic Updates**: Receive and apply updates remotely
- **Backup System**: Creates automatic backups before updates
- **Repository Management**: Supports public and private repositories
- **REST API**: API endpoints for communication with the master
- **Optimized Cache**: Cache system for optimal performance
- **Advanced Security**: Robust authentication and authorization

## Installation

1. Download the latest version from the [GitHub repository](https://github.com/marrisonlab/wp-agent-updater)
2. Upload the plugin to the `/wp-content/plugins/` directory of your WordPress site
3. Activate the plugin via the WordPress admin panel
4. Configure connection settings to the master

## Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- Internet connection to communicate with the master
- Master plugin installed on the master server

## Configuration

### Basic Settings

1. Go to "Settings" → "WP Agent Updater"
2. Enter the Master Server URL
3. Configure backup and update options
4. Save changes

### Security

- Always use HTTPS connections
- Configure strong authentication keys
- Limit access to administrative features

## Usage

### Agent Dashboard

The dashboard shows:
- Connection status with the master
- Site and version information
- Log of latest operations
- Configuration options

### API Endpoints

The agent exposes several REST API endpoints:

- `/wp-json/wp-agent-updater/v1/status` - Client information and status
- `/wp-json/wp-agent-updater/v1/update` - Plugin/theme update
- `/wp-json/wp-agent-updater/v1/backups` - List available backups
- `/wp-json/wp-agent-updater/v1/backups/restore` - Restore backup
- `/wp-json/wp-agent-updater/v1/clear-repo-cache` - Clear repository cache

### Automatic Backups

The agent automatically creates backups before:
- Plugin updates
- Theme updates
- WordPress updates
- Maintenance operations

## Security

- Authentication based on WordPress nonces
- Access control for user roles
- Strict validation of incoming data
- Sanitization of all output data
- Operation logs for audit

## Support

For support and additional documentation:
- [GitHub Repository](https://github.com/marrisonlab/wp-agent-updater)
- [Issue Tracker](https://github.com/marrisonlab/wp-agent-updater/issues)
- Visit [marrisonlab.com](https://marrisonlab.com)

## Development

This plugin is open source and contributions are welcome!

### Development Installation

1. Clone the repository: `git clone https://github.com/marrisonlab/wp-agent-updater.git`
2. Activate the plugin in your WordPress development environment
3. Contribute following standard WordPress guidelines

### Code Structure

- `includes/core.php` - Core functionality
