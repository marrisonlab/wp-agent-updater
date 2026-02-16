<?php

/**
 * WP Agent Updater Core - Complete Rewrite
 * Robust, simple, reliable update management
 */

class WP_Agent_Updater_Core {
    
    private $config_option = 'marrison_agent_config';
    private $status_option = 'marrison_agent_status';
    private $log_file;
    
    public function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/marrison-agent-updater.log';
    }
    
    /**
     * Initialize agent
     */
    public function init() {
        // Set default configuration
        $this->ensure_config();
        
        // Add simple hooks for private updates
        add_filter('site_transient_update_plugins', [$this, 'inject_plugin_updates'], 999);
        add_filter('site_transient_update_themes', [$this, 'inject_theme_updates'], 999);
        
        // Allow private repo downloads
        add_filter('http_request_args', [$this, 'allow_private_downloads'], 10, 2);
        
        $this->log('Agent initialized');
    }
    
    /**
     * Ensure default configuration exists
     */
    private function ensure_config() {
        $config = $this->get_config();
        
        if (empty($config)) {
            $default_config = [
                'master_url' => '',
                'plugins_repo' => '',
                'themes_repo' => '',
                'enable_private_plugins' => false,
                'enable_private_themes' => false,
                'auto_sync' => true,
                'sync_interval' => 3600 // 1 hour
            ];
            
            update_option($this->config_option, $default_config);
            $this->log('Default configuration created');
        }
    }
    
    /**
     * Get agent configuration
     */
    public function get_config() {
        return get_option($this->config_option, []);
    }
    
    /**
     * Update agent configuration
     */
    public function update_config($config) {
        $current = $this->get_config();
        $updated = array_merge($current, $config);
        update_option($this->config_option, $updated);
        $this->log('Configuration updated');
        return true;
    }
    
    /**
     * Check if agent is active
     */
    public function is_active() {
        $status = get_option($this->status_option, 'inactive');
        return $status === 'active';
    }
    
    /**
     * Set agent status
     */
    public function set_status($status) {
        update_option($this->status_option, $status);
        $this->log("Status changed to: $status");
    }
    
    /**
     * Get site status data
     */
    public function get_status_data() {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Clear cache to get fresh data
        wp_clean_update_cache();
        wp_update_plugins();
        wp_update_themes();
        
        // Get plugins
        $all_plugins = get_plugins();
        $active_plugins = get_option('active_plugins', []);
        $plugin_updates = get_site_transient('update_plugins');
        
        $plugins = [];
        $plugins_need_update = [];
        
        foreach ($all_plugins as $file => $data) {
            $plugin_info = [
                'file' => $file,
                'name' => $data['Name'],
                'version' => $data['Version'],
                'active' => in_array($file, $active_plugins)
            ];
            
            $plugins[] = $plugin_info;
            
            if (isset($plugin_updates->response[$file])) {
                $plugins_need_update[] = array_merge($plugin_info, [
                    'new_version' => $plugin_updates->response[$file]->new_version,
                    'package' => $plugin_updates->response[$file]->package ?? ''
                ]);
            }
        }
        
        // Get themes
        $all_themes = wp_get_themes();
        $theme_updates = get_site_transient('update_themes');
        
        $themes = [];
        $themes_need_update = [];
        
        foreach ($all_themes as $slug => $theme) {
            $theme_info = [
                'slug' => $slug,
                'name' => $theme->get('Name'),
                'version' => $theme->get('Version')
            ];
            
            $themes[] = $theme_info;
            
            if (isset($theme_updates->response[$slug])) {
                $themes_need_update[] = array_merge($theme_info, [
                    'new_version' => $theme_updates->response[$slug]['new_version'],
                    'package' => $theme_updates->response[$slug]['package'] ?? ''
                ]);
            }
        }
        
        // Get translation updates
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        $translation_updates = wp_get_translation_updates();
        
        return [
            'site_url' => get_site_url(),
            'site_name' => get_bloginfo('name'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'status' => $this->get_status(),
            'plugins' => $plugins,
            'plugins_need_update' => $plugins_need_update,
            'themes' => $themes,
            'themes_need_update' => $themes_need_update,
            'translations_need_update' => count($translation_updates),
            'last_sync' => get_option('marrison_agent_last_sync', 'never')
        ];
    }
    
    /**
     * Inject private plugin updates
     */
    public function inject_plugin_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $config = $this->get_config();
        
        if (!$config['enable_private_plugins'] || empty($config['plugins_repo'])) {
            return $transient;
        }
        
        $repo_plugins = $this->fetch_repository($config['plugins_repo']);
        
        if (empty($repo_plugins)) {
            return $transient;
        }
        
        foreach ($repo_plugins as $slug => $plugin) {
            $plugin_file = $this->find_plugin_file($slug);
            
            if ($plugin_file && isset($transient->checked[$plugin_file])) {
                $current_version = $transient->checked[$plugin_file];
                $new_version = $plugin['version'] ?? '0.0.0';
                
                if (version_compare($current_version, $new_version, '<')) {
                    $update = new stdClass();
                    $update->slug = $slug;
                    $update->plugin = $plugin_file;
                    $update->new_version = $new_version;
                    $update->package = $plugin['download_url'] ?? $plugin['package'] ?? '';
                    $update->url = $plugin['url'] ?? '';
                    $update->tested = $plugin['tested'] ?? get_bloginfo('version');
                    $update->requires = $plugin['requires'] ?? '5.0';
                    $update->requires_php = $plugin['requires_php'] ?? '7.0';
                    
                    $transient->response[$plugin_file] = $update;
                    $this->log("Injected plugin update: $slug $current_version -> $new_version");
                }
            }
        }
        
        return $transient;
    }
    
    /**
     * Inject private theme updates
     */
    public function inject_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $config = $this->get_config();
        
        if (!$config['enable_private_themes'] || empty($config['themes_repo'])) {
            return $transient;
        }
        
        $repo_themes = $this->fetch_repository($config['themes_repo']);
        
        if (empty($repo_themes)) {
            return $transient;
        }
        
        foreach ($repo_themes as $slug => $theme) {
            if (isset($transient->checked[$slug])) {
                $current_version = $transient->checked[$slug];
                $new_version = $theme['version'] ?? '0.0.0';
                
                if (version_compare($current_version, $new_version, '<')) {
                    $update = [
                        'theme' => $slug,
                        'new_version' => $new_version,
                        'package' => $theme['download_url'] ?? $theme['package'] ?? '',
                        'url' => $theme['url'] ?? ''
                    ];
                    
                    $transient->response[$slug] = $update;
                    $this->log("Injected theme update: $slug $current_version -> $new_version");
                }
            }
        }
        
        return $transient;
    }
    
    /**
     * Fetch repository data
     */
    private function fetch_repository($url) {
        if (empty($url)) {
            return [];
        }
        
        $this->log("Fetching repository: $url");
        
        $response = wp_remote_get($url, [
            'timeout' => 30,
            'sslverify' => false,
            'headers' => ['User-Agent' => 'Marrison-Agent-Updater/1.0']
        ]);
        
        if (is_wp_error($response)) {
            $this->log('Repository fetch error: ' . $response->get_error_message());
            return [];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("Repository HTTP error: $code");
            return [];
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Repository JSON error: ' . json_last_error_msg());
            return [];
        }
        
        // Convert numeric array to associative
        if (is_array($data) && isset($data[0]) && isset($data[0]['slug'])) {
            $associative = [];
            foreach ($data as $item) {
                if (!empty($item['slug'])) {
                    $associative[$item['slug']] = $item;
                }
            }
            $data = $associative;
        }
        
        $this->log('Repository fetched: ' . count($data) . ' items');
        return $data;
    }
    
    /**
     * Find plugin file by slug
     */
    private function find_plugin_file($slug) {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $plugins = get_plugins();
        
        // Direct match
        if (isset($plugins[$slug])) {
            return $slug;
        }
        
        // Directory match
        foreach ($plugins as $file => $data) {
            if (dirname($file) === $slug) {
                return $file;
            }
        }
        
        // Single file plugin
        if (isset($plugins["$slug.php"])) {
            return "$slug.php";
        }
        
        return false;
    }
    
    /**
     * Allow private downloads
     */
    public function allow_private_downloads($args, $url) {
        $config = $this->get_config();
        
        $private_urls = [];
        if ($config['enable_private_plugins'] && !empty($config['plugins_repo'])) {
            $private_urls[] = $config['plugins_repo'];
        }
        if ($config['enable_private_themes'] && !empty($config['themes_repo'])) {
            $private_urls[] = $config['themes_repo'];
        }
        
        foreach ($private_urls as $repo_url) {
            if (strpos($url, $repo_url) !== false) {
                $args['sslverify'] = false;
                $args['timeout'] = 300;
                break;
            }
        }
        
        return $args;
    }
    
    /**
     * Perform full update routine
     */
    public function perform_update($options = []) {
        $defaults = [
            'clear_cache' => true,
            'update_plugins' => true,
            'update_themes' => true,
            'update_translations' => true
        ];
        
        $options = array_merge($defaults, $options);
        $results = [];
        
        try {
            $this->log('Starting update routine');
            
            if ($options['clear_cache']) {
                $this->clear_cache();
                $results['cache_cleared'] = true;
            }
            
            if ($options['update_plugins']) {
                $results['plugins'] = $this->update_plugins();
            }
            
            if ($options['update_themes']) {
                $results['themes'] = $this->update_themes();
            }
            
            if ($options['update_translations']) {
                $results['translations'] = $this->update_translations();
            }
            
            $this->log('Update routine completed');
            
            return [
                'success' => true,
                'message' => 'Update completed successfully',
                'results' => $results
            ];
            
        } catch (Exception $e) {
            $this->log('Update error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'results' => $results
            ];
        }
    }
    
    /**
     * Update plugins
     */
    private function update_plugins() {
        if (!class_exists('Plugin_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        
        $updates = get_site_transient('update_plugins');
        
        if (empty($updates->response)) {
            return ['updated' => 0, 'message' => 'No plugin updates available'];
        }
        
        $upgrader = new Plugin_Upgrader();
        $result = $upgrader->bulk_upgrade(array_keys($updates->response));
        
        $updated = 0;
        $errors = [];
        
        if (is_array($result)) {
            foreach ($result as $plugin => $upgrade_result) {
                if (is_wp_error($upgrade_result)) {
                    $errors[] = "$plugin: " . $upgrade_result->get_error_message();
                } else {
                    $updated++;
                }
            }
        }
        
        $message = "Updated $updated plugins";
        if (!empty($errors)) {
            $message .= ". Errors: " . implode('; ', $errors);
        }
        
        $this->log($message);
        
        return [
            'updated' => $updated,
            'errors' => $errors,
            'message' => $message
        ];
    }
    
    /**
     * Update themes
     */
    private function update_themes() {
        if (!class_exists('Theme_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        
        $updates = get_site_transient('update_themes');
        
        if (empty($updates->response)) {
            return ['updated' => 0, 'message' => 'No theme updates available'];
        }
        
        $upgrader = new Theme_Upgrader();
        $result = $upgrader->bulk_upgrade(array_keys($updates->response));
        
        $updated = 0;
        $errors = [];
        
        if (is_array($result)) {
            foreach ($result as $theme => $upgrade_result) {
                if (is_wp_error($upgrade_result)) {
                    $errors[] = "$theme: " . $upgrade_result->get_error_message();
                } else {
                    $updated++;
                }
            }
        }
        
        $message = "Updated $updated themes";
        if (!empty($errors)) {
            $message .= ". Errors: " . implode('; ', $errors);
        }
        
        $this->log($message);
        
        return [
            'updated' => $updated,
            'errors' => $errors,
            'message' => $message
        ];
    }
    
    /**
     * Update translations
     */
    private function update_translations() {
        if (!function_exists('wp_get_translation_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
        }
        
        if (!class_exists('Language_Pack_Upgrader')) {
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        }
        
        $updates = wp_get_translation_updates();
        
        if (empty($updates)) {
            return ['updated' => 0, 'message' => 'No translation updates available'];
        }
        
        $upgrader = new Language_Pack_Upgrader();
        $result = $upgrader->bulk_upgrade($updates);
        
        $updated = 0;
        $errors = [];
        
        if (is_array($result)) {
            foreach ($result as $update => $upgrade_result) {
                if (is_wp_error($upgrade_result)) {
                    $errors[] = "$update: " . $upgrade_result->get_error_message();
                } else {
                    $updated++;
                }
            }
        }
        
        $message = "Updated $updated translations";
        if (!empty($errors)) {
            $message .= ". Errors: " . implode('; ', $errors);
        }
        
        $this->log($message);
        
        return [
            'updated' => $updated,
            'errors' => $errors,
            'message' => $message
        ];
    }
    
    /**
     * Clear cache
     */
    private function clear_cache() {
        wp_cache_flush();
        wp_clean_plugins_cache(true);
        wp_clean_themes_cache(true);
        delete_site_transient('update_plugins');
        delete_site_transient('update_themes');
        delete_site_transient('update_core');
        
        $this->log('Cache cleared');
    }
    
    /**
     * Sync with master
     */
    public function sync_with_master() {
        $config = $this->get_config();
        
        if (empty($config['master_url'])) {
            return ['success' => false, 'message' => 'Master URL not configured'];
        }
        
        $endpoint = untrailingslashit($config['master_url']) . '/wp-json/marrison-master/v1/sync';
        
        $data = $this->get_status_data();
        
        $headers = ['Content-Type' => 'application/json'];
        $token = get_option('wp_agent_updater_master_token');
        $ts = time();
        if (!empty($token)) {
            $headers['X-Marrison-Token'] = $token;
            $headers['X-Marrison-Timestamp'] = (string)$ts;
            $headers['X-Marrison-Signature'] = hash_hmac('sha256', json_encode($data) . '|' . $ts, $token);
        }
        $response = wp_remote_post($endpoint, [
            'body' => json_encode($data),
            'headers' => $headers,
            'timeout' => 30,
            'sslverify' => true
        ]);
        
        if (is_wp_error($response)) {
            $this->log('Sync error: ' . $response->get_error_message());
            return ['success' => false, 'message' => $response->get_error_message()];
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            $this->log("Sync HTTP error: $code");
            return ['success' => false, 'message' => "HTTP $code"];
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Update local config from master response
        if (isset($body['config'])) {
            $this->update_config($body['config']);
        }
        
        update_option('marrison_agent_last_sync', current_time('mysql'));
        
        $this->log('Sync completed');
        return $body;
    }
    
    /**
     * Simple logging
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        error_log("[$timestamp] $message\n", 3, $this->log_file);
    }
}
