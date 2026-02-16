<?php
/**
 * Plugin Name: WP Agent Updater
 * Plugin URI: https://github.com/marrisonlab/wp-agent-updater
 * Description: Client agent for WP Master/Agent Updater System.
 * Version: 1.1.1
 * Author: Angelo Marra
 * Author URI: https://marrisonlab.com
 * License: GPL v2 or later
 * Text Domain: wp-agent-updater
 */

if (!defined('ABSPATH')) {
    exit;
}

define('WP_AGENT_UPDATER_PATH', plugin_dir_path(__FILE__));
define('WP_AGENT_UPDATER_URL', plugin_dir_url(__FILE__));

// Include files
require_once WP_AGENT_UPDATER_PATH . 'includes/core.php';
require_once WP_AGENT_UPDATER_PATH . 'includes/backups.php';
require_once WP_AGENT_UPDATER_PATH . 'includes/admin.php';
require_once WP_AGENT_UPDATER_PATH . 'includes/api.php';
require_once WP_AGENT_UPDATER_PATH . 'includes/github-updater.php';

/**
 * Schedule a daily sync with the master on plugin activation.
 */
function wp_agent_updater_activate() {
    if (!wp_next_scheduled('wp_agent_updater_daily_sync')) {
        wp_schedule_event(time(), 'daily', 'wp_agent_updater_daily_sync');
    }
    if (!wp_next_scheduled('wp_agent_updater_scheduled_scan')) {
        $frequency = get_option('wp_agent_updater_scan_frequency', 'hourly');
        wp_schedule_event(time(), $frequency, 'wp_agent_updater_scheduled_scan');
    }
}
register_activation_hook(__FILE__, 'wp_agent_updater_activate');

/**
 * Clear scheduled events on plugin deactivation.
 */
function wp_agent_updater_deactivate() {
    wp_clear_scheduled_hook('wp_agent_updater_daily_sync');
    wp_clear_scheduled_hook('wp_agent_updater_scheduled_scan');
}
register_deactivation_hook(__FILE__, 'wp_agent_updater_deactivate');

/**
 * Cron callback: send daily report/sync to master.
 */
function wp_agent_updater_run_daily_sync() {
    $agent = new WP_Agent_Updater_Core();

    // Respect service status and master URL configuration
    if (!$agent->is_active() || empty($agent->get_master_url())) {
        return;
    }

    $agent->sync_with_master();
}
add_action('wp_agent_updater_daily_sync', 'wp_agent_updater_run_daily_sync');

/**
 * Add custom cron schedules.
 */
function wp_agent_updater_cron_schedules($schedules) {
    if (!isset($schedules['15min'])) {
        $schedules['15min'] = [
            'interval' => 15 * 60,
            'display' => 'Every 15 Minutes'
        ];
    }
    if (!isset($schedules['30min'])) {
        $schedules['30min'] = [
            'interval' => 30 * 60,
            'display' => 'Every 30 Minutes'
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'wp_agent_updater_cron_schedules');

/**
 * Cron callback: scheduled scan and cache.
 */
function wp_agent_updater_run_scheduled_scan() {
    $agent = new WP_Agent_Updater_Core();
    if (!$agent->is_active()) {
        return;
    }
    $agent->run_scheduled_scan();
}
add_action('wp_agent_updater_scheduled_scan', 'wp_agent_updater_run_scheduled_scan');

// Initialize
function wp_agent_updater_init() {
    $agent = new WP_Agent_Updater_Core();
    $agent->init();
    
    // Initialize Backups
    WP_Agent_Updater_Backups::get_instance();

    // Initialize Admin
    if (is_admin()) {
        new WP_Agent_Updater_Admin();
    }

    // Initialize GitHub Updater
    new WP_Agent_Updater_GitHub_Updater(
        __FILE__,
        'marrisonlab',
        'wp-agent-updater'
    );
}
add_action('plugins_loaded', 'wp_agent_updater_init');
