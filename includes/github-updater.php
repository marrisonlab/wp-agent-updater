<?php

class WP_Agent_Updater_GitHub_Updater {
    
    private $plugin_file;
    private $plugin_slug; // folder/filename.php
    private $slug; // folder name
    private $github_repo;
    private $github_user;
    private $access_token;
    private $cache_key;
    private $cache_duration = 3600; // 1 hour cache
    
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $log_file = WP_CONTENT_DIR . '/wp-agent-updater-debug.log';
            $timestamp = date('Y-m-d H:i:s');
            error_log("[$timestamp] [WP Agent Updater GitHub] $message\n", 3, $log_file);
        }
    }

    public function __construct($plugin_file, $github_user, $github_repo, $access_token = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->slug = dirname($this->plugin_slug);
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->access_token = $access_token;
        $this->cache_key = 'wp_agent_updater_github_update_' . $this->plugin_slug;
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'get_plugin_info'], 10, 3);
        add_action('admin_init', [$this, 'force_check']);
        add_filter('plugin_action_links_' . $this->plugin_slug, [$this, 'add_force_check_button'], 10, 2);
    }
    
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $plugin_data = get_plugin_data($this->plugin_file);
        $current_version = $plugin_data['Version'];
        
        $remote_version = $this->get_remote_version();
        
        if ($remote_version) {
            // Normalize version by removing 'v' prefix if present
            $remote_ver_clean = ltrim($remote_version->tag_name, 'v');
            
            $this->log("Checking updates: Current=$current_version, Remote=$remote_ver_clean");

            $plugin = new stdClass();
            $plugin->id = 'https://github.com/' . $this->github_user . '/' . $this->github_repo;
            $plugin->slug = $this->slug;
            $plugin->plugin = $this->plugin_slug;
            $plugin->new_version = $remote_ver_clean;
            $plugin->url = $plugin_data['PluginURI'];
            $plugin->package = $this->get_download_url($remote_version);
            $plugin->icons = [
                '1x' => 'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/master/assets/icon-128x128.png',
                '2x' => 'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/master/assets/icon-256x256.png'
            ];
            $plugin->banners = [
                'low' => 'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/master/assets/banner-772x250.png',
                'high' => 'https://raw.githubusercontent.com/' . $this->github_user . '/' . $this->github_repo . '/master/assets/banner-1544x500.png'
            ];

            if (version_compare($current_version, $remote_ver_clean, '<')) {
                $this->log("Update available!");
                $transient->response[$this->plugin_slug] = $plugin;
            } else {
                // Add to no_update to enable "Enable auto-updates" link
                $transient->no_update[$this->plugin_slug] = $plugin;
            }
        }
        
        return $transient;
    }
    
    public function get_plugin_info($false, $action, $args) {
        // Check if this is the correct plugin
        if ($action !== 'plugin_information') {
            return $false;
        }

        // WP sends 'slug' as the folder name, but sometimes it might be the full path depending on context
        // We check both to be safe
        if (!isset($args->slug) || ($args->slug !== $this->slug && $args->slug !== $this->plugin_slug)) {
            return $false;
        }
        
        $remote_info = $this->get_remote_info();
        
        if (!$remote_info) {
            return $false;
        }
        
        $plugin = new stdClass();
        $plugin->name = $remote_info->name;
        $plugin->slug = $this->slug;
        $plugin->version = ltrim($remote_info->tag_name, 'v');
        $plugin->author = $remote_info->author;
        $plugin->homepage = $remote_info->homepage;
        $plugin->download_link = $this->get_download_url($remote_info);
        $plugin->sections = [
            'description' => $remote_info->description ?? '',
            'changelog' => $remote_info->body ?? '',
        ];
        
        return $plugin;
    }
    
    private function get_remote_version() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }
        
        $url = "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/releases/latest";
        $response = $this->github_request($url);
        
        if ($response) {
            set_transient($this->cache_key, $response, $this->cache_duration);
            return $response;
        }
        
        return null;
    }
    
    private function get_remote_info() {
        return $this->get_remote_version();
    }
    
    private function get_download_url($release) {
        if (isset($release->assets) && is_array($release->assets) && count($release->assets) > 0) {
            foreach ($release->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    return $asset->browser_download_url;
                }
            }
        }
        
        // Fallback to source code download
        return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/{$release->tag_name}.zip";
    }
    
    private function github_request($url) {
        $args = [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            ],
        ];
        
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body);
    }
    
    public function force_check() {
        if (isset($_GET['force-check']) && $_GET['force-check'] === '1' && 
            isset($_GET['plugin']) && $_GET['plugin'] === $this->plugin_slug) {
            
            check_admin_referer('wp-agent-updater-force-check-' . $this->plugin_slug);
            
            delete_transient($this->cache_key);
            wp_clean_plugins_cache();
            
            wp_redirect(admin_url('plugins.php?wp-agent-updater-check=1'));
            exit;
        }
    }
    
    public function add_force_check_button($actions, $plugin_file) {
        if ($plugin_file === $this->plugin_slug && current_user_can('update_plugins')) {
            $url = wp_nonce_url(
                admin_url('plugins.php?force-check=1&plugin=' . $this->plugin_slug),
                'wp-agent-updater-force-check-' . $this->plugin_slug
            );
            
            $actions['force-check'] = sprintf(
                '<a href="%s" aria-label="%s">%s</a>',
                $url,
                esc_attr__('Forza controllo aggiornamenti da GitHub', 'wp-agent-updater'),
                __('Forza Controllo', 'wp-agent-updater')
            );
        }
        
        return $actions;
    }
}

// Initialize GitHub updater for WP Agent Updater
add_action('plugins_loaded', function() {
    if (file_exists(plugin_dir_path(__FILE__) . '../wp-agent-updater.php')) {
        new WP_Agent_Updater_GitHub_Updater(
            plugin_dir_path(__FILE__) . '../wp-agent-updater.php',
            'marrisonlab',
            'wp-agent-updater'
        );
    }
});