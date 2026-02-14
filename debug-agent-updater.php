<?php
/**
 * Debug script for WP Agent Updater
 * Run this script to check current status and diagnose update issues
 */

// Include WordPress
require_once('../../../wp-config.php');

echo "=== WP Agent Updater Debug ===\n\n";

// Check if agent is active
$agent_active = get_option('wp_agent_updater_active');
echo "Agent Service Status: " . ($agent_active === 'yes' ? 'ACTIVE' : 'INACTIVE') . "\n";

// Check master URL
$master_url = get_option('wp_agent_updater_master_url');
echo "Master URL: " . ($master_url ?: 'NOT SET') . "\n";

// Check for master-injected updates
$injected_plugins = get_option('wp_agent_updater_master_injected_plugins', []);
$injected_themes = get_option('wp_agent_updater_master_injected_themes', []);

echo "\n=== Master-Injected Updates ===\n";
echo "Injected Plugin Updates: " . count($injected_plugins) . "\n";
if (!empty($injected_plugins)) {
    foreach ($injected_plugins as $file => $data) {
        echo "- $file: " . $data['new_version'] . " (Package: " . ($data['package'] ? 'YES' : 'NO') . ")\n";
    }
}

echo "Injected Theme Updates: " . count($injected_themes) . "\n";
if (!empty($injected_themes)) {
    foreach ($injected_themes as $slug => $data) {
        echo "- $slug: " . $data['new_version'] . " (Package: " . ($data['package'] ? 'YES' : 'NO') . ")\n";
    }
}

// Check for available updates
require_once(ABSPATH . 'wp-admin/includes/update.php');
wp_update_plugins();
wp_update_themes();

$plugin_updates = get_site_transient('update_plugins');
$theme_updates = get_site_transient('update_themes');

echo "\n=== WordPress Transient Updates ===\n";
echo "Plugin Updates: " . count((array)($plugin_updates->response ?? [])) . "\n";
echo "Theme Updates: " . count((array)($theme_updates->response ?? [])) . "\n";

if (!empty($plugin_updates->response)) {
    echo "\nPlugin Details:\n";
    foreach ($plugin_updates->response as $file => $data) {
        $package = isset($data->package) ? $data->package : 'NO PACKAGE';
        echo "- $file: Package $package\n";
    }
}

// Check log file
$log_file = WP_CONTENT_DIR . '/wp-agent-updater-debug.log';
if (file_exists($log_file)) {
    echo "\n=== Last 20 lines of debug log ===\n";
    $lines = file($log_file);
    $last_lines = array_slice($lines, -20);
    echo implode('', $last_lines);
} else {
    echo "\nNo debug log file found at: $log_file\n";
}

echo "\n=== Test Update Endpoint ===\n";
$endpoint = home_url('/wp-json/wp-agent-updater/v1/test-endpoints');
echo "Testing endpoint: $endpoint\n";

$response = wp_remote_get($endpoint, ['timeout' => 10]);
if (is_wp_error($response)) {
    echo "Endpoint test failed: " . $response->get_error_message() . "\n";
} else {
    $code = wp_remote_retrieve_response_code($response);
    echo "Endpoint response code: $code\n";
    if ($code === 200) {
        $body = json_decode(wp_remote_retrieve_body($response), true);
        echo "Endpoint response:\n";
        echo "- Status updates: " . count($body['status']['plugins_need_update'] ?? []) . " plugins, " . count($body['status']['themes_need_update'] ?? []) . " themes\n";
        echo "- Translations available: " . ($body['translations_available'] ?? 0) . "\n";
    }
}

echo "\n=== Test Sync with Master ===\n";
if ($agent_active === 'yes' && $master_url) {
    echo "Testing sync with master...\n";
    
    // Load agent core
    require_once('wp-agent-updater/includes/core.php');
    $agent = new WP_Agent_Updater_Core();
    
    $sync_result = $agent->sync_with_master();
    if (is_wp_error($sync_result)) {
        echo "Sync failed: " . $sync_result->get_error_message() . "\n";
    } else {
        echo "Sync successful!\n";
        if (isset($sync_result['injected_updates'])) {
            echo "- Received " . count($sync_result['injected_updates']['plugins'] ?? []) . " plugin updates\n";
            echo "- Received " . count($sync_result['injected_updates']['themes'] ?? []) . " theme updates\n";
        }
    }
} else {
    echo "Skipping sync test (agent inactive or no master URL)\n";
}

echo "\n=== Debug Complete ===\n";
