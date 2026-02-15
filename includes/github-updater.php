<?php

class WP_Agent_Updater_GitHub_Updater {
    
    private $plugin_file;
    private $plugin_slug; // folder/filename.php
    private $slug; // folder name
    private $github_user;
    private $github_repo;
    private $cache_key;
    private $cache_duration = 3600; // 1 hour cache
    private $update_url;
    
    public function __construct($plugin_file, $github_user, $github_repo) {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->slug = dirname($this->plugin_slug);
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->cache_key = 'marrison_github_update_' . $this->slug;
        $this->update_url = "https://raw.githubusercontent.com/{$github_user}/{$github_repo}/master/updates.json";
        
        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_for_updates']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_action('admin_init', [$this, 'force_check']);
        add_filter('plugin_action_links_' . $this->plugin_slug, [$this, 'add_force_check_button'], 10, 2);
        add_action('admin_notices', [$this, 'display_check_result']);
        add_filter('upgrader_source_selection', [$this, 'upgrader_source_selection'], 10, 4);
        
        // Increase timeout for downloading this plugin - use upgrader-specific filter
        add_filter('upgrader_pre_download', [$this, 'increase_download_timeout'], 10, 3);
    }
    
    public function increase_download_timeout($reply, $package, $upgrader) {
        // Check if this is our plugin being downloaded
        if (strpos($package, 'github.com') !== false || strpos($package, 'api.github.com') !== false) {
            error_log('[GitHub Updater] Setting timeout to 60s for package download: ' . $package);
            
            // Modify the upgrader's skin to use longer timeout
            add_filter('http_request_args', function($args, $url) use ($package) {
                if ($url === $package) {
                    error_log('[GitHub Updater] Applying 60s timeout to: ' . $url);
                    $args['timeout'] = 60;
                }
                return $args;
            }, 10, 2);
        }
        return $reply;
    }
    
    public function display_check_result() {
        if (isset($_GET['marrison-check-result'])) {
            $status = sanitize_text_field($_GET['marrison-check-result']);
            $message = get_transient('marrison_check_message_' . get_current_user_id());
            
            if ($status === 'success') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($message) . '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($message) . '</p></div>';
            }
            
            delete_transient('marrison_check_message_' . get_current_user_id());
        }
    }
    
    public function check_for_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }
        
        $remote_info = $this->get_remote_info();
        
        if ($remote_info) {
            $current_version = isset($transient->checked[$this->plugin_slug]) ? $transient->checked[$this->plugin_slug] : '';
            if (empty($current_version)) {
                 $plugin_data = get_plugin_data($this->plugin_file);
                 $current_version = $plugin_data['Version'];
            }

            error_log('[GitHub Updater] Current version: ' . $current_version . ' | Remote version: ' . $remote_info->version);

            $plugin = new stdClass();
            $plugin->slug = $this->slug;
            $plugin->plugin = $this->plugin_slug;
            $plugin->new_version = $remote_info->version;
            $plugin->url = $remote_info->sections->description ?? '';
            $plugin->package = $remote_info->download_url;
            $plugin->icons = isset($remote_info->icons) ? (array)$remote_info->icons : [];
            $plugin->banners = isset($remote_info->banners) ? (array)$remote_info->banners : [];
            $plugin->banners_rtl = isset($remote_info->banners_rtl) ? (array)$remote_info->banners_rtl : [];

            if (version_compare($current_version, $remote_info->version, '<')) {
                error_log('[GitHub Updater] ✓ Update available! Injecting into transient (Package: ' . $plugin->package . ')');
                $transient->response[$this->plugin_slug] = $plugin;
            } else {
                error_log('[GitHub Updater] No update needed (current >= remote)');
                $transient->no_update[$this->plugin_slug] = $plugin;
            }
        } else {
            error_log('[GitHub Updater] Failed to get remote info');
        }
        
        return $transient;
    }
    
    public function plugin_info($res, $action, $args) {
        if ($action !== 'plugin_information') {
            return $res;
        }

        if (empty($args->slug) || ($args->slug !== $this->slug && $args->slug !== $this->plugin_slug)) {
            return $res;
        }
        
        $remote_info = $this->get_remote_info();
        
        if ($remote_info) {
            $res = new stdClass();
            $res->name = $remote_info->name;
            $res->slug = $this->slug;
            $res->version = $remote_info->version;
            $res->tested = $remote_info->tested ?? '';
            $res->requires = $remote_info->requires ?? '';
            $res->author = $remote_info->author ?? '';
            $res->download_link = $remote_info->download_url;
            $res->trunk = $remote_info->download_url;
            $res->last_updated = $remote_info->last_updated ?? '';
            $res->sections = (array)($remote_info->sections ?? []);
            $res->banners = isset($remote_info->banners) ? (array)$remote_info->banners : [];
            
            return $res;
        }
        
        return $res;
    }
    
    private function get_remote_info() {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            error_log('[GitHub Updater] Using cached update info for ' . $this->slug);
            return $cached;
        }
        
        error_log('[GitHub Updater] Fetching update info from: ' . $this->update_url);
        $response = wp_remote_get($this->update_url, [
            'timeout' => 30,
            'sslverify' => false,
            'redirection' => 5
        ]);
        
        if (is_wp_error($response)) {
            error_log('[GitHub Updater] Error fetching updates: ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[GitHub Updater] HTTP error ' . $code . ' when fetching updates');
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            error_log('[GitHub Updater] Invalid JSON or empty response');
            return false;
        }
        
        error_log('[GitHub Updater] Found version ' . ($data->version ?? 'UNKNOWN') . ' on GitHub');
        set_transient($this->cache_key, $data, $this->cache_duration);
        
        return $data;
    }
    
    public function force_check() {
        if (isset($_GET['marrison-force-check']) && $_GET['marrison-force-check'] === $this->slug && current_user_can('update_plugins')) {
            check_admin_referer('marrison_force_check_' . $this->slug);
            
            delete_transient($this->cache_key);
            delete_site_transient('update_plugins');
            
            // Force WP to check for updates
            wp_update_plugins();
            
            $transient = get_site_transient('update_plugins');
            $has_update = isset($transient->response[$this->plugin_slug]);
            
            $message = $has_update 
                ? sprintf(__('Update found! Version %s is available.', 'wp-agent-updater'), $transient->response[$this->plugin_slug]->new_version)
                : __('No update found. You are using the latest version.', 'wp-agent-updater');
            
            set_transient('marrison_check_message_' . get_current_user_id(), $message, 60);
            
            wp_safe_redirect(add_query_arg('marrison-check-result', $has_update ? 'success' : 'error', remove_query_arg(['marrison-force-check', '_wpnonce'])));
            exit;
        }
    }
    
    public function add_force_check_button($links) {
        $url = add_query_arg([
            'marrison-force-check' => $this->slug,
            '_wpnonce' => wp_create_nonce('marrison_force_check_' . $this->slug)
        ], self_admin_url('plugins.php'));
        
        $links[] = '<a href="' . esc_url($url) . '">' . __('Check for updates', 'wp-agent-updater') . '</a>';
        return $links;
    }

    public function upgrader_source_selection($source, $remote_source, $upgrader, $hook_extra = null) {
        global $wp_filesystem;

        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->plugin_slug) {
            $corrected_source = trailingslashit($remote_source) . $this->slug . '/';
            
            error_log('[GitHub Updater] Upgrader source selection - Original: ' . $source . ' | Corrected: ' . $corrected_source);
            
            if ($source !== $corrected_source) {
                error_log('[GitHub Updater] Renaming folder from ' . basename($source) . ' to ' . $this->slug);
                
                // Check if corrected source already exists and remove it
                if ($wp_filesystem->exists($corrected_source)) {
                    error_log('[GitHub Updater] Target folder exists, removing it first');
                    $wp_filesystem->delete($corrected_source, true);
                }
                
                if ($wp_filesystem->move($source, $corrected_source)) {
                    error_log('[GitHub Updater] ✓ Folder renamed successfully');
                    return $corrected_source;
                } else {
                    error_log('[GitHub Updater] ERROR: Failed to rename folder');
                    error_log('[GitHub Updater] Source exists: ' . ($wp_filesystem->exists($source) ? 'YES' : 'NO'));
                    error_log('[GitHub Updater] Target exists: ' . ($wp_filesystem->exists($corrected_source) ? 'YES' : 'NO'));
                }
            } else {
                error_log('[GitHub Updater] No rename needed');
            }
        }
        return $source;
    }
}
