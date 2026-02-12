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
            $current_version = isset($transient->checked[$this->plugin_file]) ? $transient->checked[$this->plugin_file] : '';
            if (empty($current_version)) {
                 $plugin_data = get_plugin_data($this->plugin_file);
                 $current_version = $plugin_data['Version'];
            }

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
                $transient->response[$this->plugin_slug] = $plugin;
            } else {
                $transient->no_update[$this->plugin_slug] = $plugin;
            }
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
            return $cached;
        }
        
        $response = wp_remote_get($this->update_url, ['timeout' => 15, 'sslverify' => false]);
        
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body);
        
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            return false;
        }
        
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
            
            if ($source !== $corrected_source) {
                $wp_filesystem->move($source, $corrected_source);
                return $corrected_source;
            }
        }
        return $source;
    }
}
