<?php
/**
 * Plugin Name: WP Agent Updater
 * Description: Agent for receiving update commands from master controller
 * Version: 2.0.0
 * Author: Marrison Lab
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define constants
define('MARRISON_AGENT_VERSION', '2.0.0');
define('MARRISON_AGENT_DIR', plugin_dir_path(__FILE__));

// Include core files
require_once MARRISON_AGENT_DIR . 'includes/core-new.php';
require_once MARRISON_AGENT_DIR . 'includes/api-new.php';

// Initialize the plugin
function marrison_agent_init() {
    $core = new WP_Agent_Updater_Core();
    $core->init();
}
add_action('plugins_loaded', 'marrison_agent_init');

// Schedule auto-sync if enabled
function marrison_agent_schedule_sync() {
    $config = get_option('marrison_agent_config', []);
    
    if ($config['auto_sync'] && !wp_next_scheduled('marrison_agent_sync_cron')) {
        wp_schedule_event(time(), 'hourly', 'marrison_agent_sync_cron');
    } elseif (!$config['auto_sync']) {
        wp_clear_scheduled_hook('marrison_agent_sync_cron');
    }
}
add_action('init', 'marrison_agent_schedule_sync');

// Auto-sync cron job
function marrison_agent_auto_sync() {
    $core = new WP_Agent_Updater_Core();
    if ($core->is_active()) {
        $core->sync_with_master();
    }
}
add_action('marrison_agent_sync_cron', 'marrison_agent_auto_sync');

// Activation hook
register_activation_hook(__FILE__, function() {
    // Create default options
    if (!get_option('marrison_agent_config')) {
        update_option('marrison_agent_config', [
            'master_url' => '',
            'plugins_repo' => '',
            'themes_repo' => '',
            'enable_private_plugins' => false,
            'enable_private_themes' => false,
            'auto_sync' => true,
            'sync_interval' => 3600
        ]);
    }
    
    if (!get_option('marrison_agent_status')) {
        update_option('marrison_agent_status', 'inactive');
    }
});

// Deactivation hook
register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('marrison_agent_sync_cron');
});
