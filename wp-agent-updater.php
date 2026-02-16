<?php
/**
 * Plugin Name: WP Agent Updater
 * Plugin URI: https://github.com/marrisonlab/wp-agent-updater
 * Description: Client agent for WP Master/Agent Updater System.
 * Version: 1.1.2.2
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

function wp_agent_updater_activate() {
    if (!wp_next_scheduled('wp_agent_updater_scheduled_scan')) {
        $frequency = get_option('wp_agent_updater_scan_frequency', 'hourly');
        wp_schedule_event(time(), $frequency, 'wp_agent_updater_scheduled_scan');
    }
    $poll = get_option('wp_agent_updater_poll_interval', 'disabled');
    if ($poll !== 'disabled' && !wp_next_scheduled('wp_agent_updater_poll_master')) {
        wp_schedule_event(time(), $poll, 'wp_agent_updater_poll_master');
    }
}
register_activation_hook(__FILE__, 'wp_agent_updater_activate');

/**
 * Clear scheduled events on plugin deactivation.
 */
function wp_agent_updater_deactivate() {
    wp_clear_scheduled_hook('wp_agent_updater_scheduled_scan');
    wp_clear_scheduled_hook('wp_agent_updater_poll_master');
}
register_deactivation_hook(__FILE__, 'wp_agent_updater_deactivate');


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
    if (!isset($schedules['2min'])) {
        $schedules['2min'] = [
            'interval' => 2 * 60,
            'display' => 'Every 2 Minutes'
        ];
    }
    if (!isset($schedules['5min'])) {
        $schedules['5min'] = [
            'interval' => 5 * 60,
            'display' => 'Every 5 Minutes'
        ];
    }
    if (!isset($schedules['10min'])) {
        $schedules['10min'] = [
            'interval' => 10 * 60,
            'display' => 'Every 10 Minutes'
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

function wp_agent_updater_poll_master_requests() {
    $agent = new WP_Agent_Updater_Core();
    if (!$agent->is_active()) {
        return;
    }
    $master = $agent->get_master_url();
    if (empty($master)) {
        return;
    }
    $site = get_site_url();
    $poll = untrailingslashit($master) . '/wp-json/wp-master-updater/v1/poll';
    $poll = add_query_arg('site_url', $site, $poll);
    $headers = [];
    $token = get_option('wp_agent_updater_master_token');
    $ts = time();
    if (!empty($token)) {
        $headers['X-Marrison-Token'] = $token;
        $headers['X-Marrison-Timestamp'] = (string)$ts;
        $headers['X-Marrison-Signature'] = hash_hmac('sha256', $site . '|' . $ts, $token);
    }
    $resp = wp_remote_get($poll, ['timeout' => 5, 'sslverify' => true, 'headers' => $headers]);
    if (is_wp_error($resp)) {
        return;
    }
    $info = json_decode(wp_remote_retrieve_body($resp), true);
    if (!is_array($info)) {
        return;
    }
    if (!empty($info['push_requested'])) {
        $agent->run_scheduled_scan();
    }
    if (!empty($info['update_requested'])) {
        $opts = is_array($info['update_options'] ?? null) ? $info['update_options'] : [];
        $clear = !isset($opts['clear_cache']) ? true : (bool)$opts['clear_cache'];
        $trans = !isset($opts['update_translations']) ? true : (bool)$opts['update_translations'];
        $agent->perform_full_update_routine($clear, $trans);
    }
}
add_action('wp_agent_updater_poll_master', 'wp_agent_updater_poll_master_requests');

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
